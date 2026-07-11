<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Comment;
use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Model\TimeRecord;
use OCA\Unity\Model\TrackerSearchResult;
use OCA\Unity\Service\AdfConverter;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Jira client for both Jira Cloud and Jira Server / Data Center.
 *
 * The deployment is detected from the base URL (Cloud is always *.atlassian.net)
 * or, for custom domains, from the (cached) /serverInfo `deploymentType`. The two
 * flavours differ in: API version (v3 vs v2), auth (Basic email:token vs Bearer
 * personal-access-token), search pagination (token-based /search/jql vs
 * offset-based /search), body format (ADF vs wiki-markup text), and how users are
 * addressed (accountId vs name). Tempo is Cloud-only; on Server we always use the
 * native worklog API.
 */
class JiraClient extends AbstractTrackerClient {

	private const FIELDS = 'summary,status,assignee,reporter,creator,labels,project,created,updated,description,timespent';
	private const TEMPO_API = 'https://api.tempo.io/4';

	private ICache $cache;

	public function __construct(
		IClientService $clientService,
		LoggerInterface $logger,
		private AdfConverter $adf,
		ICacheFactory $cacheFactory,
	) {
		parent::__construct($clientService, $logger);
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	public function getTrackerId(): string {
		return 'jira';
	}

	/** True for Jira Server / Data Center, false for Jira Cloud. */
	private function isServer(Connection $connection): bool {
		return $this->deployment($connection) === 'server';
	}

	private function deployment(Connection $connection): string {
		$host = strtolower((string)parse_url($connection->baseUrl, PHP_URL_HOST));
		if ($host !== '' && str_contains($host, 'atlassian.net')) {
			// Jira Cloud is always served from *.atlassian.net.
			return 'cloud';
		}
		$key = 'jira:deploy:' . sha1(rtrim($connection->baseUrl, '/'));
		$cached = $this->cache->get($key);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}
		$deployment = $this->probeDeployment($connection, $host);
		$this->cache->set($key, $deployment, 86400);
		return $deployment;
	}

	/** Detect deployment from /serverInfo; fall back to "server" for custom domains. */
	private function probeDeployment(Connection $connection, string $host): string {
		try {
			$response = $this->request('GET', rtrim($connection->baseUrl, '/') . '/rest/api/2/serverInfo', [
				'headers' => ['Accept' => 'application/json', 'User-Agent' => Application::USER_AGENT],
			], $connection);
			if ($response->getStatusCode() === 200) {
				$data = json_decode((string)$response->getBody(), true);
				$type = is_array($data) ? strtolower((string)($data['deploymentType'] ?? '')) : '';
				if ($type === 'cloud') {
					return 'cloud';
				}
				if ($type === 'server' || $type === 'datacenter') {
					return 'server';
				}
			}
		} catch (\Throwable $e) {
			// fall through to the heuristic
		}
		return 'server';
	}

	private function apiRoot(Connection $connection): string {
		$version = $this->isServer($connection) ? '2' : '3';
		return rtrim($connection->baseUrl, '/') . '/rest/api/' . $version;
	}

	protected function authHeaders(Connection $connection): array {
		if ($this->isServer($connection)) {
			// Jira Server / DC personal access token.
			return [
				'Authorization' => 'Bearer ' . $connection->token,
				'Content-Type' => 'application/json',
			];
		}
		return [
			'Authorization' => 'Basic ' . base64_encode($connection->username . ':' . $connection->token),
			'Content-Type' => 'application/json',
		];
	}

	/** Body storage format exposed to the frontend renderer. */
	private function bodyFormat(Connection $connection): string {
		// Jira Server / DC returns description and comment bodies as rendered HTML,
		// so they go through the frontend's sanitized HTML renderer. Cloud bodies are
		// ADF converted to Markdown by decodeBody().
		return $this->isServer($connection) ? 'html' : 'markdown';
	}

	/** Decode a Jira body field to display text (ADF on Cloud, wiki-markup string on Server). */
	private function decodeBody(Connection $connection, mixed $raw): string {
		if ($this->isServer($connection)) {
			return is_string($raw) ? $raw : '';
		}
		return $this->adf->toText($raw);
	}

	/** Encode user text to a Jira body value (ADF document on Cloud, plain string on Server). */
	private function encodeBody(Connection $connection, string $text): mixed {
		return $this->isServer($connection) ? $text : $this->adf->fromMarkdown($text);
	}

	public function testConnection(Connection $connection): array {
		try {
			$response = $this->request('GET', $this->apiRoot($connection) . '/myself', [
				'headers' => $this->defaultHeaders($connection),
			], $connection);
			$data = $this->json($response, 'Authentication');
			$flavour = $this->isServer($connection) ? 'Jira Server' : 'Jira Cloud';
			return [
				'ok' => true,
				'message' => 'Connected (' . $flavour . ')',
				'user' => (string)($data['displayName'] ?? $data['emailAddress'] ?? $data['name'] ?? ''),
			];
		} catch (TrackerException $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	public function search(Connection $connection, IssueQuery $query, ?string $cursor = null): TrackerSearchResult {
		if ($cursor === null || $cursor === '') {
			$parts = $this->parseReference($query->term);
			if ($parts !== null) {
				try {
					return new TrackerSearchResult([$this->getIssue($connection, $parts)]);
				} catch (TrackerException $e) {
					// not a resolvable key here — fall through to full-text search
				}
			}
		}
		$jql = $this->buildJql($query);
		if ($this->isServer($connection)) {
			return $this->searchServer($connection, $jql, $query, $cursor);
		}
		return $this->searchCloud($connection, $jql, $query, $cursor);
	}

	/** Jira Cloud: token-paginated /search/jql (no total count). */
	private function searchCloud(Connection $connection, string $jql, IssueQuery $query, ?string $cursor): TrackerSearchResult {
		$params = [
			'jql' => $jql,
			'fields' => self::FIELDS,
			'maxResults' => (string)$query->limit,
		];
		if ($cursor !== null && $cursor !== '') {
			$params['nextPageToken'] = $cursor;
		}
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/search/jql', [
				'headers' => $this->defaultHeaders($connection),
				'query' => $params,
			], $connection),
			'Search',
		);
		$issues = $this->mapIssues($connection, $data['issues'] ?? []);
		$next = $data['nextPageToken'] ?? null;
		return new TrackerSearchResult($issues, is_string($next) ? $next : null);
	}

	/** Jira Server / DC: offset-paginated /search with a total count. */
	private function searchServer(Connection $connection, string $jql, IssueQuery $query, ?string $cursor): TrackerSearchResult {
		$startAt = ($cursor !== null && $cursor !== '') ? (int)$cursor : 0;
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/search', [
				'headers' => $this->defaultHeaders($connection),
				'query' => [
					'jql' => $jql,
					'fields' => self::FIELDS,
					'startAt' => (string)$startAt,
					'maxResults' => (string)$query->limit,
				],
			], $connection),
			'Search',
		);
		$issues = $this->mapIssues($connection, $data['issues'] ?? []);
		$total = (int)($data['total'] ?? 0);
		$maxResults = (int)($data['maxResults'] ?? $query->limit);
		$nextStart = $startAt + max($maxResults, count($issues));
		$next = $nextStart < $total ? (string)$nextStart : null;
		return new TrackerSearchResult($issues, $next);
	}

	/**
	 * @param mixed $rawIssues
	 * @return Issue[]
	 */
	private function mapIssues(Connection $connection, mixed $rawIssues): array {
		$issues = [];
		foreach ((is_array($rawIssues) ? $rawIssues : []) as $raw) {
			if (is_array($raw)) {
				$issues[] = $this->normalizeIssue($connection, $raw);
			}
		}
		return $issues;
	}

	public function getIssue(Connection $connection, array $refParts): Issue {
		$key = (string)($refParts['key'] ?? '');
		$response = $this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
			'headers' => $this->defaultHeaders($connection),
			'query' => ['fields' => self::FIELDS],
		], $connection);
		$data = $this->json($response, 'Get issue');
		return $this->normalizeIssue($connection, $data);
	}

	public function getComments(Connection $connection, array $refParts): array {
		$key = (string)($refParts['key'] ?? '');
		$response = $this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/comment', [
			'headers' => $this->defaultHeaders($connection),
		], $connection);
		$data = $this->json($response, 'Get comments');
		$comments = [];
		foreach (($data['comments'] ?? []) as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$comments[] = new Comment(
				(string)($raw['id'] ?? ''),
				(string)($raw['author']['displayName'] ?? ''),
				$raw['author']['avatarUrls']['48x48'] ?? null,
				$this->decodeBody($connection, $raw['body'] ?? null),
				$raw['created'] ?? null,
			);
		}
		return $comments;
	}

	public function addComment(Connection $connection, array $refParts, string $body): Comment {
		$key = (string)($refParts['key'] ?? '');
		$response = $this->request('POST', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/comment', [
			'headers' => $this->defaultHeaders($connection),
			'body' => json_encode(['body' => $this->encodeBody($connection, $body)]),
		], $connection);
		$raw = $this->json($response, 'Add comment');
		return new Comment(
			(string)($raw['id'] ?? ''),
			(string)($raw['author']['displayName'] ?? ''),
			$raw['author']['avatarUrls']['48x48'] ?? null,
			$this->decodeBody($connection, $raw['body'] ?? null),
			$raw['created'] ?? null,
		);
	}

	public function updateComment(Connection $connection, array $refParts, string $commentId, string $body): Comment {
		$key = (string)($refParts['key'] ?? '');
		$raw = $this->json(
			$this->request('PUT', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/comment/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $this->encodeBody($connection, $body)]),
			], $connection),
			'Update comment',
		);
		return new Comment(
			(string)($raw['id'] ?? $commentId),
			(string)($raw['author']['displayName'] ?? ''),
			$raw['author']['avatarUrls']['48x48'] ?? null,
			$this->decodeBody($connection, $raw['body'] ?? null),
			$raw['created'] ?? null,
		);
	}

	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue {
		$key = (string)($refParts['key'] ?? '');
		$server = $this->isServer($connection);
		$fields = [];
		if (array_key_exists('title', $changes)) {
			$fields['summary'] = (string)$changes['title'];
		}
		if (array_key_exists('description', $changes)) {
			$fields['description'] = $this->encodeBody($connection, (string)$changes['description']);
		}
		if (array_key_exists('assignee', $changes)) {
			$account = (string)$changes['assignee'];
			if ($account === '') {
				$fields['assignee'] = null;
			} else {
				$fields['assignee'] = $server ? ['name' => $account] : ['accountId' => $account];
			}
		}
		if (array_key_exists('labels', $changes) && is_array($changes['labels'])) {
			$fields['labels'] = array_values(array_map('strval', $changes['labels']));
		}
		if ($fields !== []) {
			$this->json(
				$this->request('PUT', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
					'headers' => $this->defaultHeaders($connection),
					'body' => json_encode(['fields' => $fields]),
				], $connection),
				'Update issue',
			);
		}
		if (array_key_exists('status', $changes) && (string)$changes['status'] !== '') {
			$this->json(
				$this->request('POST', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/transitions', [
					'headers' => $this->defaultHeaders($connection),
					'body' => json_encode(['transition' => ['id' => (string)$changes['status']]]),
				], $connection),
				'Transition issue',
			);
		}
		return $this->getIssue($connection, $refParts);
	}

	public function getEditMeta(Connection $connection, array $refParts): array {
		$key = (string)($refParts['key'] ?? '');
		$server = $this->isServer($connection);
		$statuses = [];
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/transitions', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Transitions',
			);
			foreach ($data['transitions'] ?? [] as $tr) {
				if (is_array($tr)) {
					$statuses[] = ['id' => (string)($tr['id'] ?? ''), 'name' => (string)($tr['name'] ?? $tr['to']['name'] ?? '')];
				}
			}
		} catch (TrackerException $e) {
		}
		$assignees = [];
		try {
			$users = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/user/assignable/search', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['issueKey' => $key, 'maxResults' => '50'],
				], $connection),
				'Assignable users',
			);
			foreach ($users as $user) {
				if (is_array($user)) {
					// Cloud addresses users by accountId; Server by name.
					$id = $server ? (string)($user['name'] ?? '') : (string)($user['accountId'] ?? '');
					$assignees[] = ['id' => $id, 'name' => (string)($user['displayName'] ?? '')];
				}
			}
		} catch (TrackerException $e) {
		}
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => false, 'labels' => true, 'labelsFreeText' => true],
			'statuses' => $statuses,
			'assignees' => $assignees,
			'labels' => [],
		];
	}

	public function logTime(Connection $connection, array $refParts, int $seconds, string $comment, ?string $startedAt): void {
		$key = (string)($refParts['key'] ?? '');
		// Tempo is a Cloud-only product; on Server we always use the native worklog.
		if (!$this->isServer($connection) && $connection->tempoToken !== '') {
			$this->logTimeViaTempo($connection, $key, $seconds, $comment, $startedAt);
			return;
		}
		$this->logTimeNative($connection, $key, $seconds, $comment, $startedAt);
	}

	private function logTimeNative(Connection $connection, string $key, int $seconds, string $comment, ?string $startedAt): void {
		$payload = ['timeSpentSeconds' => $seconds];
		if ($comment !== '') {
			$payload['comment'] = $this->encodeBody($connection, $comment);
		}
		if ($startedAt !== null && $startedAt !== '') {
			$payload['started'] = $this->formatStarted($startedAt);
		}
		$response = $this->request('POST', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/worklog', [
			'headers' => $this->defaultHeaders($connection),
			'body' => json_encode($payload),
		], $connection);
		$this->json($response, 'Log time');
	}

	/**
	 * Log time through the Tempo Cloud API (a separate product with its own host
	 * and token). Tempo v4 needs the numeric Jira issue id and the author's Jira
	 * accountId, which we resolve from Jira first.
	 */
	private function logTimeViaTempo(Connection $connection, string $key, int $seconds, string $comment, ?string $startedAt): void {
		$issueId = $this->getIssueNumericId($connection, $key);
		$accountId = $this->getMyAccountId($connection);
		$startDate = ($startedAt !== null && $startedAt !== '') ? substr($startedAt, 0, 10) : gmdate('Y-m-d');
		$payload = [
			'issueId' => $issueId,
			'timeSpentSeconds' => $seconds,
			'startDate' => $startDate,
			'startTime' => '09:00:00',
			'description' => $comment !== '' ? $comment : 'Worklog',
			'authorAccountId' => $accountId,
		];
		$response = $this->request('POST', self::TEMPO_API . '/worklogs', [
			'headers' => [
				'Authorization' => 'Bearer ' . $connection->tempoToken,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'User-Agent' => Application::USER_AGENT,
			],
			'body' => json_encode($payload),
		], $connection);
		$this->json($response, 'Log time (Tempo)');
	}

	public function getTimeRecords(Connection $connection, array $refParts): array {
		$key = (string)($refParts['key'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/worklog', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Get worklogs',
		);
		$server = $this->isServer($connection);
		$me = $this->currentUserKey($connection);
		$records = [];
		foreach (($data['worklogs'] ?? []) as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			// Cloud identifies users by accountId, Server/DC by name. Only the
			// worklog author may edit/delete it.
			$authorKey = $server ? (string)($raw['author']['name'] ?? '') : (string)($raw['author']['accountId'] ?? '');
			$own = $me !== '' && $authorKey === $me;
			$records[] = new TimeRecord(
				(string)($raw['id'] ?? ''),
				(string)($raw['author']['displayName'] ?? ''),
				(int)($raw['timeSpentSeconds'] ?? 0),
				$raw['started'] ?? null,
				$this->decodeBody($connection, $raw['comment'] ?? null),
				editable: $own,
				deletable: $own,
			);
		}
		return $records;
	}

	/**
	 * The connection user's own identity as worklog authors are keyed: the
	 * accountId on Cloud, the username on Server/DC. Returns '' if unresolved.
	 */
	private function currentUserKey(Connection $connection): string {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/myself', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Resolve current user',
			);
			return $this->isServer($connection)
				? (string)($data['name'] ?? '')
				: (string)($data['accountId'] ?? '');
		} catch (TrackerException $e) {
			return '';
		}
	}

	/**
	 * Edit/delete operate on the native Jira worklog (id from getTimeRecords).
	 * Tempo writes its worklogs to Jira natively, so the same endpoint updates
	 * Tempo entries too (as long as Jira permits native worklog changes).
	 */
	public function updateTime(Connection $connection, array $refParts, string $recordId, int $seconds, string $comment, ?string $startedAt): void {
		$key = (string)($refParts['key'] ?? '');
		$payload = ['timeSpentSeconds' => $seconds];
		if ($comment !== '') {
			$payload['comment'] = $this->encodeBody($connection, $comment);
		}
		if ($startedAt !== null && $startedAt !== '') {
			$payload['started'] = $this->formatStarted($startedAt);
		}
		$this->json(
			$this->request('PUT', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/worklog/' . rawurlencode($recordId), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($payload),
			], $connection),
			'Update time',
		);
	}

	public function deleteTime(Connection $connection, array $refParts, string $recordId): void {
		$key = (string)($refParts['key'] ?? '');
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/worklog/' . rawurlencode($recordId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete time',
		);
	}

	private function getIssueNumericId(Connection $connection, string $key): int {
		$response = $this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
			'headers' => $this->defaultHeaders($connection),
			'query' => ['fields' => 'id'],
		], $connection);
		$data = $this->json($response, 'Resolve issue id');
		return (int)($data['id'] ?? 0);
	}

	private function getMyAccountId(Connection $connection): string {
		$response = $this->request('GET', $this->apiRoot($connection) . '/myself', [
			'headers' => $this->defaultHeaders($connection),
		], $connection);
		$data = $this->json($response, 'Resolve account');
		return (string)($data['accountId'] ?? '');
	}

	/** Jira's worklog `started` needs the exact format 2026-07-08T09:00:00.000+0000. */
	private function formatStarted(string $startedAt): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startedAt) === 1) {
			return $startedAt . 'T09:00:00.000+0000';
		}
		return $startedAt;
	}

	/**
	 * Detect a Jira issue key (e.g. ABC-123) so pasting the detail-link text
	 * jumps straight to that issue.
	 *
	 * @return array{key: string}|null
	 */
	private function parseReference(string $term): ?array {
		$term = trim($term);
		return preg_match('/^[A-Za-z][A-Za-z0-9]+-\d+$/', $term) === 1
			? ['key' => strtoupper($term)]
			: null;
	}

	private function buildJql(IssueQuery $query): string {
		$clauses = [];
		$term = trim($query->term);
		if ($term !== '') {
			$clauses[] = 'text ~ "' . $this->escapeJql($term) . '"';
		}
		if ($query->assignedToMe) {
			// assignee is a bounding clause Jira accepts on /search/jql.
			$clauses[] = 'assignee = currentUser()';
		} else {
			// Jira Cloud rejects unbounded JQL — including a bare ORDER BY *and* a
			// text-only search. Always add a recency bound when not filtered by assignee.
			$clauses[] = 'updated >= -365d';
		}
		if (!$query->showClosed) {
			// Unlike the other trackers, Jira applies no status filter by default, so
			// exclude Done-category (closed) issues explicitly.
			$clauses[] = 'statusCategory != Done';
		}
		$jql = implode(' AND ', $clauses);
		$field = match ($query->sort) {
			'created' => 'created',
			'title' => 'summary',
			'status' => 'status',
			default => 'updated',
		};
		$direction = strtolower($query->order) === 'asc' ? 'ASC' : 'DESC';
		$jql .= ($jql === '' ? '' : ' ') . 'ORDER BY ' . $field . ' ' . $direction;
		return $jql;
	}

	private function escapeJql(string $term): string {
		return str_replace(['\\', '"'], ['\\\\', '\\"'], $term);
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeIssue(Connection $connection, array $raw): Issue {
		$fields = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];
		$key = (string)($raw['key'] ?? '');
		$description = $this->decodeBody($connection, $fields['description'] ?? null);
		$labels = [];
		foreach (($fields['labels'] ?? []) as $label) {
			if (is_string($label)) {
				$labels[] = $label;
			}
		}
		$timeSpent = $fields['timespent'] ?? null;

		return new Issue(
			Ref::encode('jira', $connection->id, ['key' => $key]),
			'jira',
			$connection->id,
			$connection->label,
			$key,
			(string)($fields['summary'] ?? ''),
			$description,
			(string)($fields['status']['name'] ?? ''),
			(string)($fields['reporter']['displayName'] ?? $fields['creator']['displayName'] ?? ''),
			(string)($fields['assignee']['displayName'] ?? ''),
			$labels,
			(string)($fields['project']['name'] ?? $fields['project']['key'] ?? ''),
			$fields['created'] ?? null,
			$fields['updated'] ?? null,
			rtrim($connection->baseUrl, '/') . '/browse/' . $key,
			is_int($timeSpent) ? $timeSpent : null,
			$this->bodyFormat($connection),
		);
	}
}
