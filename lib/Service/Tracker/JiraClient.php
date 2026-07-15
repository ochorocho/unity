<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Attachment;
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

	/**
	 * Decode a Jira body field to display text (ADF on Cloud, wiki-markup string on
	 * Server). With $mentionAsToken, Cloud mentions render as the `@mention:<id>`
	 * editor token (for the editable body) instead of the display `@Name`.
	 */
	private function decodeBody(Connection $connection, mixed $raw, bool $mentionAsToken = false): string {
		if ($this->isServer($connection)) {
			return is_string($raw) ? $raw : '';
		}
		return $this->adf->toText($raw, $mentionAsToken);
	}

	/**
	 * Mentions in a Jira body, as `{id, label}` for the editor's userData. Cloud
	 * ADF only; Server wiki markup has no structured mentions here.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	private function bodyMentions(Connection $connection, mixed $raw): array {
		return $this->isServer($connection) ? [] : $this->adf->extractMentions($raw);
	}

	/**
	 * Rendered HTML for display of a Jira body, with mentions as profile-linked
	 * pills. On Cloud the ADF is rendered to HTML. On Server the body is wiki
	 * markup; we only rewrite its `[~username]` mention tokens into pill links and
	 * leave the rest to the 'html' body format (returns null when there are none,
	 * so mention-free Server bodies keep their existing rendering path).
	 */
	private function renderedBody(Connection $connection, mixed $raw): ?string {
		if ($raw === null) {
			return null;
		}
		$base = rtrim($connection->baseUrl, '/');
		if ($this->isServer($connection)) {
			return $this->linkServerMentions(is_string($raw) ? $raw : '', $base);
		}
		return $this->adf->toHtml(
			$raw,
			// Jira Cloud user profile: https://<site>.atlassian.net/jira/people/<accountId>
			static fn (string $accountId): ?string => $accountId !== '' ? $base . '/jira/people/' . rawurlencode($accountId) : null,
		);
	}

	/**
	 * Rewrite Jira Server wiki mention tokens (`[~username]`) into profile-linked
	 * mention pills, leaving the rest of the body untouched. Returns null when the
	 * body has no mention token, so mention-free bodies keep the plain 'html' path.
	 */
	private function linkServerMentions(string $body, string $base): ?string {
		$linked = preg_replace_callback(
			'/\[~([^\]\s]+)\]/',
			static function (array $m) use ($base): string {
				// Jira Server/DC user profile: /secure/ViewProfile.jspa?name=<username>
				$url = $base . '/secure/ViewProfile.jspa?name=' . rawurlencode($m[1]);
				return '<a class="unity-mention" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
					. htmlspecialchars($m[1], ENT_QUOTES) . '</a>';
			},
			$body,
		) ?? $body;
		return $linked !== $body ? $linked : null;
	}

	/** Encode user text to a Jira body value (ADF document on Cloud, plain string on Server). */
	private function encodeBody(Connection $connection, string $text): mixed {
		if ($this->isServer($connection)) {
			// Server/DC wiki markup mentions users as [~username].
			return $this->replaceMentionTokens($text, static fn (string $h): string => '[~' . $h . ']');
		}
		// Cloud: AdfConverter emits ADF mention nodes from the canonical tokens.
		return $this->adf->fromMarkdown($text);
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

	public function supportsAttachments(): bool {
		return true;
	}

	public function supportsMentions(): bool {
		return true;
	}

	/**
	 * @param array $refParts
	 * @return Attachment[]
	 */
	public function getAttachments(Connection $connection, array $refParts): array {
		$key = (string)($refParts['key'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['fields' => 'attachment'],
			], $connection),
			'Get attachments',
		);
		$attachments = [];
		foreach (($data['fields']['attachment'] ?? []) as $raw) {
			if (is_array($raw)) {
				$attachments[] = $this->normalizeAttachment($raw);
			}
		}
		return $attachments;
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeAttachment(array $raw): Attachment {
		$thumb = $raw['thumbnail'] ?? null;
		return new Attachment(
			(string)($raw['id'] ?? ''),
			(string)($raw['filename'] ?? ''),
			(string)($raw['mimeType'] ?? 'application/octet-stream'),
			(int)($raw['size'] ?? 0),
			(string)($raw['content'] ?? ''),
			is_string($thumb) && $thumb !== '' ? $thumb : null,
			(string)($raw['author']['displayName'] ?? ''),
			$raw['created'] ?? null,
		);
	}

	public function uploadAttachment(Connection $connection, array $refParts, string $filename, string $mimeType, string $content): Attachment {
		$key = (string)($refParts['key'] ?? '');
		// Multipart upload; must not send the JSON Content-Type, and Jira requires
		// the X-Atlassian-Token: no-check header on this endpoint.
		$auth = $this->authHeaders($connection);
		unset($auth['Content-Type']);
		$headers = ['X-Atlassian-Token' => 'no-check', 'User-Agent' => Application::USER_AGENT] + $auth;
		$data = $this->json(
			$this->request('POST', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/attachments', [
				'headers' => $headers,
				'multipart' => [[
					'name' => 'file',
					'contents' => $content,
					'filename' => $filename,
					'headers' => ['Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream'],
				]],
			], $connection),
			'Upload attachment',
		);
		// Jira returns an array of the created attachment objects.
		$first = $data[0] ?? null;
		if (is_array($first)) {
			return $this->normalizeAttachment($first);
		}
		throw new TrackerException('Upload failed: unexpected response');
	}

	public function deleteAttachment(Connection $connection, array $refParts, string $attachmentId): void {
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/attachment/' . rawurlencode($attachmentId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete attachment',
		);
	}

	// ---- Relations ---------------------------------------------------------

	public function supportsRelations(): bool {
		return true;
	}

	/**
	 * Directed vocabulary from Jira's issue link types: each type yields one entry
	 * per side (outward + inward), deduped when the type is symmetric ("Relates").
	 * The id encodes "{typeName}|{direction}" for addRelation().
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public function getRelationTypes(Connection $connection, array $refParts): array {
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/issueLinkType', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Get link types',
		);
		$out = [];
		foreach ($data['issueLinkTypes'] ?? [] as $t) {
			if (!is_array($t)) {
				continue;
			}
			$name = (string)($t['name'] ?? '');
			$outward = (string)($t['outward'] ?? '');
			$inward = (string)($t['inward'] ?? '');
			if ($name === '') {
				continue;
			}
			$out[] = ['id' => $name . '|outward', 'name' => ucfirst($outward !== '' ? $outward : $name)];
			if (strcasecmp($inward, $outward) !== 0 && $inward !== '') {
				$out[] = ['id' => $name . '|inward', 'name' => ucfirst($inward)];
			}
		}
		return $out;
	}

	/**
	 * @param array $refParts
	 * @return \OCA\Unity\Model\Relation[]
	 */
	public function getRelations(Connection $connection, array $refParts): array {
		$key = (string)($refParts['key'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['fields' => 'issuelinks'],
			], $connection),
			'Get relations',
		);
		$relations = [];
		foreach (($data['fields']['issuelinks'] ?? []) as $raw) {
			if (is_array($raw)) {
				$relations[] = $this->buildRelation($connection, $raw);
			}
		}
		return $relations;
	}

	/**
	 * @param array $refParts
	 * @param array $targetParts
	 */
	public function addRelation(Connection $connection, array $refParts, string $type, array $targetParts): \OCA\Unity\Model\Relation {
		$key = (string)($refParts['key'] ?? '');
		$targetKey = (string)($targetParts['key'] ?? '');
		if ($targetKey === '') {
			throw new TrackerException('Invalid target issue');
		}
		[$name, $direction] = array_pad(explode('|', $type, 2), 2, 'outward');
		// The outwardIssue is the source of the outward action. For the "outward"
		// choice the current issue is the source; for "inward" the target is.
		$body = $direction === 'inward'
			? ['type' => ['name' => $name], 'outwardIssue' => ['key' => $targetKey], 'inwardIssue' => ['key' => $key]]
			: ['type' => ['name' => $name], 'outwardIssue' => ['key' => $key], 'inwardIssue' => ['key' => $targetKey]];
		// POST returns 201 with no body, so re-read to recover the link (with its id).
		$this->json(
			$this->request('POST', $this->apiRoot($connection) . '/issueLink', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($body),
			], $connection),
			'Add relation',
		);
		// Match the created link by target, preferring the same direction. Jira may
		// normalize a symmetric link's direction (flipping the type) or lag on
		// read-after-write, so fall back to any link to the target, then to a
		// synthesized relation — the link exists regardless and the UI refetches.
		$fallback = null;
		foreach ($this->getRelations($connection, $refParts) as $relation) {
			if (Ref::decode($relation->targetRef)['p']['key'] !== $targetKey) {
				continue;
			}
			if ($relation->type === $type) {
				return $relation;
			}
			$fallback ??= $relation;
		}
		return $fallback ?? new \OCA\Unity\Model\Relation(
			'',
			$type,
			ucfirst($name),
			Ref::encode('jira', $connection->id, ['key' => $targetKey]),
			$targetKey,
			'',
			'',
			rtrim($connection->baseUrl, '/') . '/browse/' . $targetKey,
		);
	}

	/**
	 * @param array $refParts
	 */
	public function deleteRelation(Connection $connection, array $refParts, string $relationId): void {
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/issueLink/' . rawurlencode($relationId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete relation',
		);
	}

	/**
	 * A Jira issuelink entry carries the link type (with inward/outward text) and
	 * exactly one of inwardIssue/outwardIssue (the other end). outwardIssue means
	 * the current issue is the source → the outward label applies.
	 *
	 * @param array<string, mixed> $raw
	 */
	private function buildRelation(Connection $connection, array $raw): \OCA\Unity\Model\Relation {
		$type = is_array($raw['type'] ?? null) ? $raw['type'] : [];
		$name = (string)($type['name'] ?? '');
		if (isset($raw['outwardIssue']) && is_array($raw['outwardIssue'])) {
			$target = $raw['outwardIssue'];
			$typeLabel = ucfirst((string)($type['outward'] ?? $name));
			$direction = 'outward';
		} else {
			$target = is_array($raw['inwardIssue'] ?? null) ? $raw['inwardIssue'] : [];
			$typeLabel = ucfirst((string)($type['inward'] ?? $name));
			$direction = 'inward';
		}
		$targetKey = (string)($target['key'] ?? '');
		$targetFields = is_array($target['fields'] ?? null) ? $target['fields'] : [];
		return new \OCA\Unity\Model\Relation(
			(string)($raw['id'] ?? ''),
			$name . '|' . $direction,
			$typeLabel,
			Ref::encode('jira', $connection->id, ['key' => $targetKey]),
			$targetKey,
			(string)($targetFields['summary'] ?? ''),
			(string)($targetFields['status']['name'] ?? ''),
			rtrim($connection->baseUrl, '/') . '/browse/' . $targetKey,
		);
	}

	public function getComments(Connection $connection, array $refParts): array {
		$key = (string)($refParts['key'] ?? '');
		$response = $this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/comment', [
			'headers' => $this->defaultHeaders($connection),
		], $connection);
		$data = $this->json($response, 'Get comments');
		$server = $this->isServer($connection);
		$me = $this->currentUserKey($connection);
		$comments = [];
		foreach (($data['comments'] ?? []) as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			// Cloud identifies users by accountId, Server/DC by name. Only the
			// comment author may edit/delete it.
			$authorKey = $server ? (string)($raw['author']['name'] ?? '') : (string)($raw['author']['accountId'] ?? '');
			$own = $me !== '' && $authorKey === $me;
			$comment = new Comment(
				(string)($raw['id'] ?? ''),
				(string)($raw['author']['displayName'] ?? ''),
				$raw['author']['avatarUrls']['48x48'] ?? null,
				$this->decodeBody($connection, $raw['body'] ?? null, mentionAsToken: true),
				$raw['created'] ?? null,
				editable: $own,
				deletable: $own,
				mentions: $this->bodyMentions($connection, $raw['body'] ?? null),
			);
			$comment->renderedBody = $this->renderedBody($connection, $raw['body'] ?? null);
			$comments[] = $comment;
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
		$comment = new Comment(
			(string)($raw['id'] ?? ''),
			(string)($raw['author']['displayName'] ?? ''),
			$raw['author']['avatarUrls']['48x48'] ?? null,
			$this->decodeBody($connection, $raw['body'] ?? null, mentionAsToken: true),
			$raw['created'] ?? null,
			// The current user just authored it, so it is theirs to edit/delete.
			editable: true,
			deletable: true,
			mentions: $this->bodyMentions($connection, $raw['body'] ?? null),
		);
		$comment->renderedBody = $this->renderedBody($connection, $raw['body'] ?? null);
		return $comment;
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
		$comment = new Comment(
			(string)($raw['id'] ?? $commentId),
			(string)($raw['author']['displayName'] ?? ''),
			$raw['author']['avatarUrls']['48x48'] ?? null,
			$this->decodeBody($connection, $raw['body'] ?? null, mentionAsToken: true),
			$raw['created'] ?? null,
			editable: true,
			deletable: true,
			mentions: $this->bodyMentions($connection, $raw['body'] ?? null),
		);
		$comment->renderedBody = $this->renderedBody($connection, $raw['body'] ?? null);
		return $comment;
	}

	public function deleteComment(Connection $connection, array $refParts, string $commentId): void {
		$key = (string)($refParts['key'] ?? '');
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/comment/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete comment',
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
		if (array_key_exists('type', $changes) && (string)$changes['type'] !== '') {
			$fields['issuetype'] = ['id' => (string)$changes['type']];
		}
		if (isset($changes['fields']) && is_array($changes['fields']) && $changes['fields'] !== []) {
			$schemas = $this->fieldSchemas(array_values($this->editMetaFields($connection, $key)));
			$this->applyFields($fields, $changes['fields'], $schemas, $server);
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

	public function supportsCreate(): bool {
		return true;
	}

	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array {
		// A project(+type) context asks only for that project's issue types and the
		// field descriptors for the chosen type.
		if ($project !== null && $project !== '') {
			$noType = ($type === null || $type === '');
			return [
				'projects' => [],
				'capabilities' => ['type' => true, 'typeRequired' => true, 'assignee' => true],
				'types' => $noType ? $this->issueTypes($connection, $project) : [],
				'fields' => $this->describeFields($this->createMetaFields($connection, $project, (string)$type)),
			];
		}
		$projects = [];
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/createmeta', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['expand' => 'projects.issuetypes'],
				], $connection),
				'Create meta',
			);
			foreach ($data['projects'] ?? [] as $p) {
				if (!is_array($p)) {
					continue;
				}
				$types = [];
				foreach ($p['issuetypes'] ?? [] as $it) {
					if (is_array($it) && ($it['subtask'] ?? false) !== true) {
						$types[] = ['id' => (string)($it['id'] ?? ''), 'name' => (string)($it['name'] ?? '')];
					}
				}
				$projects[] = ['id' => (string)($p['key'] ?? ''), 'name' => (string)($p['name'] ?? $p['key'] ?? ''), 'types' => $types];
			}
		} catch (TrackerException $e) {
			// Newer Jira Cloud dropped the expandable createmeta; fall back to a bare
			// project list. Types are then resolved per project at create time.
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/project', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['maxResults' => '100'],
				], $connection),
				'Projects',
			);
			foreach ($data as $p) {
				if (is_array($p)) {
					$projects[] = ['id' => (string)($p['key'] ?? ''), 'name' => (string)($p['name'] ?? $p['key'] ?? ''), 'types' => []];
				}
			}
		}
		// createmeta / project lists aren't text-searchable, so narrow them here.
		return ['projects' => $this->filterProjectsByQuery($projects, $query), 'capabilities' => ['type' => true, 'typeRequired' => true], 'fields' => []];
	}

	public function createIssue(Connection $connection, array $target): Issue {
		$projectKey = (string)$target['project'];
		if ($projectKey === '') {
			throw new TrackerException('A project is required');
		}
		$typeId = (string)($target['type'] ?? '');
		if ($typeId === '') {
			$typeId = $this->defaultIssueTypeId($connection, $projectKey);
		}
		if ($typeId === '') {
			throw new TrackerException('An issue type is required');
		}
		$fields = [
			'project' => ['key' => $projectKey],
			'issuetype' => ['id' => $typeId],
			'summary' => (string)$target['title'],
		];
		$description = (string)($target['description'] ?? '');
		if ($description !== '') {
			$fields['description'] = $this->encodeBody($connection, $description);
		}
		$assignee = (string)($target['assignee'] ?? '');
		if ($assignee !== '') {
			$fields['assignee'] = $this->isServer($connection) ? ['name' => $assignee] : ['accountId' => $assignee];
		}
		$server = $this->isServer($connection);
		$metaFields = $this->createMetaFields($connection, $projectKey, $typeId);
		$dynamic = is_array($target['fields'] ?? null) ? $target['fields'] : [];
		if ($dynamic !== []) {
			$this->applyFields($fields, $dynamic, $this->fieldSchemas($metaFields), $server);
		}
		// Fill or flag required create-screen fields the form doesn't render, so a
		// missing one gives a clear message instead of an opaque HTTP 400.
		$this->applyRequiredDefaults($fields, $metaFields, $server);
		$data = $this->json(
			$this->request('POST', $this->apiRoot($connection) . '/issue', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['fields' => $fields]),
			], $connection),
			'Create issue',
		);
		$key = (string)($data['key'] ?? '');
		if ($key === '') {
			throw new TrackerException('Create failed: no issue key returned');
		}
		return $this->getIssue($connection, ['key' => $key]);
	}

	/** First non-subtask issue type for a project (used when none was chosen). */
	private function defaultIssueTypeId(Connection $connection, string $projectKey): string {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/createmeta', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['projectKeys' => $projectKey, 'expand' => 'projects.issuetypes'],
				], $connection),
				'Create meta',
			);
			foreach ($data['projects'] ?? [] as $p) {
				foreach ($p['issuetypes'] ?? [] as $it) {
					if (is_array($it) && ($it['subtask'] ?? false) !== true) {
						return (string)($it['id'] ?? '');
					}
				}
			}
		} catch (TrackerException $e) {
		}
		return '';
	}

	public function getEditMeta(Connection $connection, array $refParts, ?string $type = null): array {
		$key = (string)($refParts['key'] ?? '');
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
		$assignee = $this->currentAssignee($connection, $key);
		$fields = [];
		$typeId = '';
		// Offer the project's full issue-type list (same as the create dialog) so the
		// type can be changed on edit. Jira itself decides at save time whether a given
		// change is permitted; where it isn't (type not on the edit screen), the PUT
		// returns a clear error that surfaces to the user.
		$types = $this->issueTypes($connection, $this->projectKeyOf($key));
		try {
			$editable = $this->editMetaFields($connection, $key);
			$current = $this->currentFieldValues($connection, $key, array_keys($editable));
			$typeId = (string)($current['issuetype'] ?? '');
			if ($type !== null && $type !== '') {
				// Fields for the prospective type come from create-meta (edit-meta only
				// describes the current type).
				$fields = $this->describeFields($this->createMetaFields($connection, $this->projectKeyOf($key), $type));
			} else {
				$fields = $this->describeFields(array_values($editable), $current);
			}
		} catch (TrackerException $e) {
		}
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => false, 'labels' => true, 'labelsFreeText' => true, 'type' => $types !== []],
			'statuses' => $statuses,
			'assignee' => $assignee,
			'labels' => [],
			'fields' => $fields,
			'types' => $types,
			'typeId' => $typeId,
		];
	}

	/**
	 * The issue's current assignee as {id, name}, or null if unassigned. Fetched
	 * separately (cheap) so the edit form can preselect it in the search picker.
	 *
	 * @return array{id: string, name: string}|null
	 */
	private function currentAssignee(Connection $connection, string $key): ?array {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['fields' => 'assignee'],
				], $connection),
				'Assignee',
			);
		} catch (TrackerException $e) {
			return null;
		}
		$user = $data['fields']['assignee'] ?? null;
		return is_array($user) ? $this->assigneeOption($connection, $user) : null;
	}

	/**
	 * Normalize a Jira user object to an assignee option. Cloud addresses users by
	 * accountId, Server/DC by name — the same identity updateIssue/createIssue use.
	 *
	 * @param array<string, mixed> $user
	 * @return array{id: string, name: string}
	 */
	private function assigneeOption(Connection $connection, array $user): array {
		$id = $this->isServer($connection) ? (string)($user['name'] ?? '') : (string)($user['accountId'] ?? '');
		return ['id' => $id, 'name' => (string)($user['displayName'] ?? '')];
	}

	public function searchAssignees(Connection $connection, array $context, string $query): array {
		$query = trim($query);
		$params = ['maxResults' => '50'];
		// Jira Cloud needs the `query` param present (even empty) alongside
		// issueKey/project: omitting it 400s, but an empty value returns the full
		// assignable list — which pre-loads the picker before the user types.
		// Server/DC lists from issueKey/project alone, so it omits `query` when empty.
		if ($query !== '' || !$this->isServer($connection)) {
			$params['query'] = $query;
		}
		if (isset($context['refParts'])) {
			$params['issueKey'] = (string)($context['refParts']['key'] ?? '');
		} elseif (isset($context['project'])) {
			$params['project'] = (string)$context['project'];
		}
		try {
			$users = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/user/assignable/search', [
					'headers' => $this->defaultHeaders($connection),
					'query' => $params,
				], $connection),
				'Assignable users',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($users as $user) {
			if (is_array($user)) {
				$out[] = $this->assigneeOption($connection, $user);
			}
		}
		return $out;
	}

	/** Project key from a Jira issue key (e.g. "AKE-4" → "AKE"). */
	private function projectKeyOf(string $issueKey): string {
		$pos = strrpos($issueKey, '-');
		return $pos === false ? '' : substr($issueKey, 0, $pos);
	}

	// ---- Dynamic fields ----------------------------------------------------

	/** Fields with first-class widgets or that we can't render generically. */
	private const SKIP_FIELDS = [
		'project', 'issuetype', 'summary', 'description', 'assignee', 'reporter',
		'labels', 'attachment', 'issuelinks', 'timetracking', 'worklog', 'comment',
		'status', 'resolution', 'parent', 'subtasks', 'security', 'watches',
	];

	/**
	 * Issue types available for a project via the granular create-meta endpoint
	 * (reliable, unlike the deprecated bulk `expand=projects.issuetypes`). Subtasks
	 * are excluded.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	private function issueTypes(Connection $connection, string $projectKey): array {
		if ($projectKey === '') {
			return [];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/createmeta/' . rawurlencode($projectKey) . '/issuetypes', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['maxResults' => '200'],
				], $connection),
				'Issue types',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['issueTypes'] ?? [] as $it) {
			if (is_array($it) && ($it['subtask'] ?? false) !== true) {
				$out[] = ['id' => (string)($it['id'] ?? ''), 'name' => (string)($it['name'] ?? '')];
			}
		}
		return $out;
	}

	/**
	 * Field metadata for a project + issue type via the granular create-meta endpoint.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function createMetaFields(Connection $connection, string $projectKey, string $typeId): array {
		if ($projectKey === '' || $typeId === '') {
			return [];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/createmeta/' . rawurlencode($projectKey) . '/issuetypes/' . rawurlencode($typeId), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['maxResults' => '200'],
				], $connection),
				'Create meta fields',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['fields'] ?? [] as $f) {
			if (is_array($f)) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/**
	 * Editable field metadata for an issue, keyed by field id (with fieldId injected).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function editMetaFields(Connection $connection, string $key): array {
		if ($key === '') {
			return [];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key) . '/editmeta', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Edit meta',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['fields'] ?? [] as $id => $f) {
			if (is_array($f)) {
				$f['fieldId'] = (string)$id;
				$out[(string)$id] = $f;
			}
		}
		return $out;
	}

	/**
	 * Build descriptors from create/edit-meta field entries, skipping first-class and
	 * unrenderable fields. $current maps field ids to current values (edit preselect).
	 *
	 * @param list<array<string, mixed>> $entries
	 * @param array<string, mixed> $current
	 * @return list<array<string, mixed>>
	 */
	private function describeFields(array $entries, array $current = []): array {
		$fields = [];
		foreach ($entries as $entry) {
			$id = (string)($entry['fieldId'] ?? '');
			if ($id === '' || in_array($id, self::SKIP_FIELDS, true)) {
				continue;
			}
			$schema = is_array($entry['schema'] ?? null) ? $entry['schema'] : [];
			$options = $this->jiraOptions($entry);
			$type = $this->jiraFieldType($schema, $options !== []);
			if ($type === null) {
				continue;
			}
			$extra = ['required' => (bool)($entry['required'] ?? false)];
			if ($type === 'select' || $type === 'multiselect') {
				$extra['options'] = $options;
			}
			if ((string)($schema['type'] ?? '') === 'datetime') {
				$extra['help'] = 'YYYY-MM-DD';
			}
			if (array_key_exists($id, $current)) {
				$extra['value'] = $current[$id];
			}
			$fields[] = $this->field($id, (string)($entry['name'] ?? $id), $type, $extra);
		}
		return $fields;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function jiraFieldType(array $schema, bool $hasOptions): ?string {
		$type = (string)($schema['type'] ?? '');
		$items = (string)($schema['items'] ?? '');
		if ($type === 'array') {
			if (in_array($items, ['option', 'version', 'component', 'user'], true)) {
				return $hasOptions ? 'multiselect' : null;
			}
			return null;
		}
		if (in_array($type, ['option', 'option2', 'priority', 'version', 'component', 'securitylevel', 'group', 'user'], true)) {
			return $hasOptions ? 'select' : null;
		}
		return match ($type) {
			'string' => 'text',
			'number' => 'float',
			'date' => 'date',
			'datetime' => 'text',
			default => null,
		};
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return list<array{id: string, name: string}>
	 */
	private function jiraOptions(array $entry): array {
		$out = [];
		foreach ($entry['allowedValues'] ?? [] as $av) {
			if (!is_array($av)) {
				continue;
			}
			$id = (string)($av['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$out[] = ['id' => $id, 'name' => (string)($av['value'] ?? $av['name'] ?? $av['label'] ?? $id)];
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 * @return array<string, array<string, mixed>>
	 */
	private function fieldSchemas(array $entries): array {
		$out = [];
		foreach ($entries as $entry) {
			$id = (string)($entry['fieldId'] ?? '');
			if ($id !== '' && is_array($entry['schema'] ?? null)) {
				$out[$id] = $entry['schema'];
			}
		}
		return $out;
	}

	/**
	 * Fold dynamic field values into a Jira fields payload, encoding each per its schema.
	 *
	 * @param array<string, mixed> $fields payload (mutated)
	 * @param array<string, mixed> $values descriptor id => value
	 * @param array<string, array<string, mixed>> $schemas
	 */
	private function applyFields(array &$fields, array $values, array $schemas, bool $isServer): void {
		foreach ($values as $id => $value) {
			$id = (string)$id;
			if (!isset($schemas[$id]) || $value === '' || $value === null || $value === []) {
				continue;
			}
			$encoded = $this->encodeJiraValue($schemas[$id], $value, $isServer);
			if ($encoded !== null && $encoded !== []) {
				$fields[$id] = $encoded;
			}
		}
	}

	/** Fields set explicitly by createIssue, or left for Jira to default itself. */
	private const CREATE_HANDLED_FIELDS = ['project', 'issuetype', 'summary', 'description', 'assignee', 'reporter'];

	/**
	 * Ensure required create-screen fields the form doesn't render are satisfied:
	 * fields Jira defaults itself are left alone, option fields are auto-filled with
	 * their first allowed value, and any remaining required field is reported by
	 * name (thrown before the POST) instead of surfacing as an opaque HTTP 400.
	 *
	 * @param array<string, mixed> $fields create payload (mutated)
	 * @param list<array<string, mixed>> $metaFields create-meta field entries
	 * @throws TrackerException when a required field can't be filled
	 */
	private function applyRequiredDefaults(array &$fields, array $metaFields, bool $isServer): void {
		$missing = [];
		foreach ($metaFields as $entry) {
			$id = (string)($entry['fieldId'] ?? '');
			if ($id === '' || isset($fields[$id]) || in_array($id, self::CREATE_HANDLED_FIELDS, true)) {
				continue;
			}
			if (($entry['required'] ?? false) !== true || ($entry['hasDefaultValue'] ?? false) === true) {
				continue;
			}
			// Only auto-fill fields we can encode as a plain {id} option (standard
			// selects). Custom/number/free-form types (e.g. the Tempo account field)
			// need a value shape we can't guess, so report them instead of sending a
			// malformed value that Jira rejects with a confusing error.
			$schema = is_array($entry['schema'] ?? null) ? $entry['schema'] : [];
			$allowed = is_array($entry['allowedValues'] ?? null) ? $entry['allowedValues'] : [];
			$first = $allowed[0]['id'] ?? null;
			if ($this->isOptionSchema($schema) && is_scalar($first) && (string)$first !== '') {
				$encoded = $this->encodeJiraValue($schema, (string)$first, $isServer);
				if ($encoded !== null && $encoded !== []) {
					$fields[$id] = $encoded;
					continue;
				}
			}
			$missing[] = (string)($entry['name'] ?? $id);
		}
		if ($missing !== []) {
			throw new TrackerException('This issue type requires field(s) not supported here: ' . implode(', ', $missing) . '. Please set them in Jira.');
		}
	}

	/** Option-like schemas whose value encodes to a simple {id} (safe to auto-fill). */
	private const OPTION_SCHEMA_TYPES = ['option', 'option2', 'priority', 'version', 'component', 'securitylevel', 'group'];

	/**
	 * Whether a field's value encodes to a plain {id} option (single or array of),
	 * so a first-allowed-value default can be sent safely.
	 *
	 * @param array<string, mixed> $schema
	 */
	private function isOptionSchema(array $schema): bool {
		$type = (string)($schema['type'] ?? '');
		if (in_array($type, self::OPTION_SCHEMA_TYPES, true)) {
			return true;
		}
		return $type === 'array' && in_array((string)($schema['items'] ?? ''), self::OPTION_SCHEMA_TYPES, true);
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function encodeJiraValue(array $schema, mixed $value, bool $isServer): mixed {
		$type = (string)($schema['type'] ?? '');
		$items = (string)($schema['items'] ?? '');
		// The Tempo Account field (a Connect field with an `option2` base type) takes
		// the raw account id as a scalar string, not a {id: …} option object — sending
		// the object makes Tempo reject the create with "value must be a number".
		if (str_contains((string)($schema['custom'] ?? ''), 'io.tempo.jira__account')) {
			return ($value === '' || $value === null) ? null : (string)$value;
		}
		if ($type === 'array') {
			$out = [];
			foreach (is_array($value) ? $value : [$value] as $v) {
				if ($items === 'string') {
					$out[] = (string)$v;
				} elseif ($items === 'user') {
					$out[] = $isServer ? ['name' => (string)$v] : ['accountId' => (string)$v];
				} else {
					$out[] = ['id' => (string)$v];
				}
			}
			return $out;
		}
		return match ($type) {
			'number' => is_numeric($value) ? $value + 0 : null,
			'date', 'datetime', 'string' => (string)$value,
			'user' => $isServer ? ['name' => (string)$value] : ['accountId' => (string)$value],
			'option', 'option2', 'priority', 'version', 'component', 'securitylevel', 'group' => ['id' => (string)$value],
			default => $value,
		};
	}

	/**
	 * Current values for the given field ids, normalized to descriptor form.
	 *
	 * @param list<string> $fieldIds
	 * @return array<string, mixed>
	 */
	private function currentFieldValues(Connection $connection, string $key, array $fieldIds): array {
		if ($key === '' || $fieldIds === []) {
			return [];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/issue/' . rawurlencode($key), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['fields' => implode(',', $fieldIds)],
				], $connection),
				'Issue fields',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$raw = is_array($data['fields'] ?? null) ? $data['fields'] : [];
		$current = [];
		foreach ($raw as $id => $value) {
			if ($value !== null) {
				$current[(string)$id] = $this->normalizeCurrentValue($value);
			}
		}
		return $current;
	}

	/** Reduce a Jira field value to the id(s)/scalar our descriptors expect. */
	private function normalizeCurrentValue(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}
		if (array_key_exists('id', $value)) {
			return (string)$value['id'];
		}
		if (array_key_exists('accountId', $value)) {
			return (string)$value['accountId'];
		}
		if (array_key_exists('name', $value) && !array_key_exists(0, $value)) {
			return (string)$value['name'];
		}
		$out = [];
		foreach ($value as $v) {
			if (is_array($v)) {
				$out[] = (string)($v['id'] ?? $v['accountId'] ?? $v['name'] ?? $v['value'] ?? '');
			} else {
				$out[] = (string)$v;
			}
		}
		return $out;
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
				createdAt: $raw['created'] ?? null,
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
		$jql .= ' ORDER BY ' . $field . ' ' . $direction;
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
		$description = $this->decodeBody($connection, $fields['description'] ?? null, mentionAsToken: true);
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
			renderedDescription: $this->renderedBody($connection, $fields['description'] ?? null),
			mentions: $this->bodyMentions($connection, $fields['description'] ?? null),
		);
	}
}
