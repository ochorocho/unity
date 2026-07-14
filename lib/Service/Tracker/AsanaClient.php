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
use OCA\Unity\Service\AsanaHtmlConverter;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Asana REST API client (https://app.asana.com/api/1.0). Auth is a Bearer
 * Personal Access Token. Asana models an "issue" as a task; comments are
 * "comment" stories, labels are tags, and time entries are premium-gated
 * time-tracking entries (minutes only, read-only description). Every request
 * body is wrapped in {"data": …} and every response in {"data": …,
 * "next_page": …}; reads pass opt_fields because Asana returns compact objects
 * otherwise. Text is a restricted HTML subset (html_notes / html_text).
 */
class AsanaClient extends AbstractTrackerClient {

	private const API_BASE = 'https://app.asana.com/api/1.0';

	/** Prefix marking an Attachment::$src that must be re-resolved to a fresh, expiring download URL. */
	private const ATTACHMENT_MARKER = 'asana-attachment:';

	/** opt_fields for a task in a list/search result (kept lean). */
	private const LIST_FIELDS = 'name,completed,assignee.name,memberships.project.name,projects.name,tags.name,permalink_url,created_at,modified_at';

	/** opt_fields for a single task's detail view (adds body, ids, due date). */
	private const DETAIL_FIELDS = 'name,notes,html_notes,completed,assignee.name,assignee.gid,memberships.project.name,memberships.project.gid,projects.name,projects.gid,tags.name,tags.gid,permalink_url,created_at,modified_at,due_on';

	/** opt_fields to read a task's custom fields with their options and current values. */
	private const CUSTOM_FIELD_READ = 'custom_fields.name,custom_fields.gid,custom_fields.resource_subtype,custom_fields.enum_options.name,custom_fields.enum_options.gid,custom_fields.enum_options.enabled,custom_fields.enum_value.gid,custom_fields.multi_enum_values.gid,custom_fields.number_value,custom_fields.text_value,custom_fields.date_value.date';

	/** @var array<string, string> resolved workspace gid keyed by connection id (request-scoped) */
	private array $workspaceCache = [];

	/** @var array<string, string> current user gid keyed by connection id (request-scoped) */
	private array $userGidCache = [];

	public function __construct(
		IClientService $clientService,
		LoggerInterface $logger,
		private AsanaHtmlConverter $html,
	) {
		parent::__construct($clientService, $logger);
	}

	public function getTrackerId(): string {
		return 'asana';
	}

	private function apiRoot(Connection $connection): string {
		return $connection->baseUrl !== '' ? rtrim($connection->baseUrl, '/') : self::API_BASE;
	}

	protected function authHeaders(Connection $connection): array {
		return ['Authorization' => 'Bearer ' . $connection->token];
	}

	/** Read headers plus JSON Content-Type for POST/PUT bodies. */
	private function writeHeaders(Connection $connection): array {
		return array_merge($this->defaultHeaders($connection), ['Content-Type' => 'application/json']);
	}

	/** Asana error envelope is {"errors":[{"message":…}]}; fall back to the base keys. */
	protected function extractError(string $body): string {
		$data = json_decode($body, true);
		if (is_array($data) && isset($data['errors'][0]['message']) && is_string($data['errors'][0]['message'])) {
			return $data['errors'][0]['message'];
		}
		return parent::extractError($body);
	}

	public function testConnection(Connection $connection): array {
		try {
			$data = $this->data(
				$this->json(
					$this->request('GET', $this->apiRoot($connection) . '/users/me', [
						'headers' => $this->defaultHeaders($connection),
						'query' => ['opt_fields' => 'name,email'],
					], $connection),
					'Authentication',
				),
			);
			$name = (string)($data['name'] ?? '');
			return ['ok' => true, 'message' => 'Connected', 'user' => $name !== '' ? $name : (string)($data['email'] ?? '')];
		} catch (TrackerException $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	public function search(Connection $connection, IssueQuery $query, ?string $cursor = null): TrackerSearchResult {
		$term = trim($query->term);

		// A bare numeric task gid jumps straight to that task.
		if (($cursor === null || $cursor === '') && $term !== '' && ctype_digit($term)) {
			try {
				return new TrackerSearchResult([$this->getIssue($connection, ['gid' => $term])]);
			} catch (TrackerException $e) {
				// not a resolvable gid — fall through to search
			}
		}

		$workspace = $this->resolveWorkspace($connection);
		$limit = max(1, min($query->limit, 100));

		if ($term !== '') {
			// Typeahead works on every plan but returns a single, unpaginated page
			// and has no completed filter, so drop closed tasks client-side.
			$response = $this->request('GET', $this->apiRoot($connection) . '/workspaces/' . rawurlencode($workspace) . '/typeahead', [
				'headers' => $this->defaultHeaders($connection),
				'query' => [
					'resource_type' => 'task',
					'query' => $term,
					'count' => (string)$limit,
					'opt_fields' => self::LIST_FIELDS,
				],
			], $connection);
			$rows = $this->data($this->json($response, 'Search'));
			$issues = [];
			foreach ($rows as $raw) {
				if (!is_array($raw)) {
					continue;
				}
				if (!$query->showClosed && ($raw['completed'] ?? false) === true) {
					continue;
				}
				$issues[] = $this->normalizeIssue($connection, $raw);
			}
			return new TrackerSearchResult($issues);
		}

		// No term: the free-tier-safe listing is "tasks assigned to me" in the
		// workspace (assignee + workspace are required together). offset paginates.
		$params = [
			'assignee' => 'me',
			'workspace' => $workspace,
			'limit' => (string)$limit,
			'opt_fields' => self::LIST_FIELDS,
		];
		if (!$query->showClosed) {
			$params['completed_since'] = 'now';
		}
		if ($cursor !== null && $cursor !== '') {
			$params['offset'] = $cursor;
		}
		$response = $this->request('GET', $this->apiRoot($connection) . '/tasks', [
			'headers' => $this->defaultHeaders($connection),
			'query' => $params,
		], $connection);
		$payload = $this->json($response, 'Search');
		$issues = [];
		foreach ($this->data($payload) as $raw) {
			if (is_array($raw)) {
				$issues[] = $this->normalizeIssue($connection, $raw);
			}
		}
		$next = $payload['next_page']['offset'] ?? null;
		return new TrackerSearchResult($issues, is_string($next) && $next !== '' ? $next : null);
	}

	public function getIssue(Connection $connection, array $refParts): Issue {
		$raw = $this->data(
			$this->json(
				$this->request('GET', $this->taskUrl($connection, $refParts), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['opt_fields' => self::DETAIL_FIELDS],
				], $connection),
				'Get issue',
			),
		);
		return $this->normalizeIssue($connection, $raw);
	}

	public function getComments(Connection $connection, array $refParts): array {
		$rows = $this->data(
			$this->json(
				$this->request('GET', $this->taskUrl($connection, $refParts) . '/stories', [
					'headers' => $this->defaultHeaders($connection),
					'query' => [
						'opt_fields' => 'created_by.name,created_by.gid,created_at,text,html_text,resource_subtype',
						'limit' => '100',
					],
				], $connection),
				'Get comments',
			),
		);
		$me = $this->currentUserGid($connection);
		$comments = [];
		foreach ($rows as $raw) {
			// Keep only user comments; drop system stories (assignments, status changes, …).
			if (!is_array($raw) || ($raw['resource_subtype'] ?? '') !== 'comment_added') {
				continue;
			}
			$comments[] = $this->normalizeComment($raw, $me);
		}
		return $comments;
	}

	public function addComment(Connection $connection, array $refParts, string $body): Comment {
		$raw = $this->data(
			$this->json(
				$this->request('POST', $this->taskUrl($connection, $refParts) . '/stories', [
					'headers' => $this->writeHeaders($connection),
					'body' => json_encode(['data' => ['html_text' => $this->html->fromMarkdown($body, false)]]),
					'query' => ['opt_fields' => 'created_by.name,created_by.gid,created_at,text,html_text,resource_subtype'],
				], $connection),
				'Add comment',
			),
		);
		return $this->normalizeComment($raw, $this->currentUserGid($connection));
	}

	public function updateComment(Connection $connection, array $refParts, string $commentId, string $body): Comment {
		$raw = $this->data(
			$this->json(
				$this->request('PUT', $this->apiRoot($connection) . '/stories/' . rawurlencode($commentId), [
					'headers' => $this->writeHeaders($connection),
					'body' => json_encode(['data' => ['html_text' => $this->html->fromMarkdown($body, false)]]),
					'query' => ['opt_fields' => 'created_by.name,created_by.gid,created_at,text,html_text,resource_subtype'],
				], $connection),
				'Update comment',
			),
		);
		return $this->normalizeComment($raw, $this->currentUserGid($connection));
	}

	public function deleteComment(Connection $connection, array $refParts, string $commentId): void {
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/stories/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete comment',
		);
	}

	// ---- Attachments -------------------------------------------------------

	public function supportsAttachments(): bool {
		return true;
	}

	public function getAttachments(Connection $connection, array $refParts): array {
		$gid = (string)($refParts['gid'] ?? '');
		$rows = $this->data(
			$this->json(
				$this->request('GET', $this->apiRoot($connection) . '/attachments', [
					'headers' => $this->defaultHeaders($connection),
					'query' => [
						'parent' => $gid,
						'opt_fields' => 'name,size,resource_subtype,created_at',
						'limit' => '100',
					],
				], $connection),
				'Get attachments',
			),
		);
		$attachments = [];
		foreach ($rows as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$id = (string)($raw['gid'] ?? '');
			$name = (string)($raw['name'] ?? '');
			$attachments[] = new Attachment(
				$id,
				$name,
				$this->guessMime($name),
				(int)($raw['size'] ?? 0),
				// download_url is a temporary pre-signed URL, so proxy through a
				// marker that fetchFile() re-resolves at click time.
				self::ATTACHMENT_MARKER . $id,
				null,
				'',
				$raw['created_at'] ?? null,
			);
		}
		return $attachments;
	}

	public function uploadAttachment(Connection $connection, array $refParts, string $filename, string $mimeType, string $content): Attachment {
		$gid = (string)($refParts['gid'] ?? '');
		// Multipart upload must not carry the JSON Content-Type (the client sets
		// the multipart boundary itself).
		$headers = ['User-Agent' => Application::USER_AGENT] + $this->authHeaders($connection);
		$raw = $this->data(
			$this->json(
				$this->request('POST', $this->apiRoot($connection) . '/attachments', [
					'headers' => $headers,
					'multipart' => [
						['name' => 'parent', 'contents' => $gid],
						[
							'name' => 'file',
							'contents' => $content,
							'filename' => $filename,
							'headers' => ['Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream'],
						],
					],
				], $connection),
				'Upload attachment',
			),
		);
		$id = (string)($raw['gid'] ?? '');
		$name = (string)($raw['name'] ?? $filename);
		return new Attachment(
			$id,
			$name,
			$mimeType !== '' ? $mimeType : $this->guessMime($name),
			isset($raw['size']) ? (int)$raw['size'] : strlen($content),
			self::ATTACHMENT_MARKER . $id,
			null,
			'',
			$raw['created_at'] ?? null,
		);
	}

	public function deleteAttachment(Connection $connection, array $refParts, string $attachmentId): void {
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/attachments/' . rawurlencode($attachmentId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete attachment',
		);
	}

	/**
	 * Resolve an attachment marker to a fresh, expiring download URL (requires
	 * an authenticated metadata call) and validate the resulting host. Direct
	 * URLs (e.g. avatars) are host-checked as-is.
	 *
	 * @param array $refParts
	 */
	protected function resolveFileUrl(Connection $connection, array $refParts, string $src): string {
		$src = trim($src);
		if (str_starts_with($src, self::ATTACHMENT_MARKER)) {
			$gid = substr($src, strlen(self::ATTACHMENT_MARKER));
			$meta = $this->data(
				$this->json(
					$this->request('GET', $this->apiRoot($connection) . '/attachments/' . rawurlencode($gid), [
						'headers' => $this->defaultHeaders($connection),
						'query' => ['opt_fields' => 'download_url'],
					], $connection),
					'Attachment',
				),
			);
			$src = (string)($meta['download_url'] ?? '');
			if ($src === '') {
				throw new TrackerException('Attachment is not downloadable');
			}
		}
		if (preg_match('#^https?://#i', $src) !== 1) {
			throw new TrackerException('Unsupported file source');
		}
		$host = strtolower((string)parse_url($src, PHP_URL_HOST));
		$allowed = $host === 'app.asana.com'
			|| str_ends_with($host, '.asana.com')
			|| str_ends_with($host, '.asanausercontent.com')
			|| $host === 's3.amazonaws.com'
			|| str_ends_with($host, '.s3.amazonaws.com');
		if (!$allowed) {
			throw new TrackerException('File host not allowed');
		}
		return $src;
	}

	/** Pre-signed download URLs carry their auth in the query string; sending a Bearer header can break them. */
	protected function fileHeaders(Connection $connection): array {
		return ['User-Agent' => Application::USER_AGENT];
	}

	// ---- Time tracking (premium-gated) -------------------------------------

	public function getTimeRecords(Connection $connection, array $refParts): array {
		$response = $this->request('GET', $this->taskUrl($connection, $refParts) . '/time_tracking_entries', [
			'headers' => $this->defaultHeaders($connection),
			'query' => [
				'opt_fields' => 'duration_minutes,entered_on,created_by.name,created_by.gid,created_at',
				'limit' => '100',
			],
		], $connection);
		// Non-premium orgs return 402/403 (and some plans 400) — degrade to no records.
		if (in_array($response->getStatusCode(), [400, 402, 403], true)) {
			return [];
		}
		$me = $this->currentUserGid($connection);
		$records = [];
		foreach ($this->data($this->json($response, 'Get time entries')) as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$own = $me !== '' && (string)($raw['created_by']['gid'] ?? '') === $me;
			$records[] = new TimeRecord(
				(string)($raw['gid'] ?? ''),
				(string)($raw['created_by']['name'] ?? ''),
				(int)round(((float)($raw['duration_minutes'] ?? 0)) * 60),
				$raw['entered_on'] ?? null,
				'',
				editable: $own,
				deletable: $own,
			);
		}
		return $records;
	}

	public function logTime(Connection $connection, array $refParts, int $seconds, string $comment, ?string $startedAt): void {
		// Asana time entries have no writable comment field, so $comment is ignored.
		$response = $this->request('POST', $this->taskUrl($connection, $refParts) . '/time_tracking_entries', [
			'headers' => $this->writeHeaders($connection),
			'body' => json_encode(['data' => [
				'duration_minutes' => $this->toMinutes($seconds),
				'entered_on' => $this->enteredOn($startedAt),
			]]),
		], $connection);
		$this->assertTimeTrackingAvailable($response);
		$this->json($response, 'Log time');
	}

	public function updateTime(Connection $connection, array $refParts, string $recordId, int $seconds, string $comment, ?string $startedAt): void {
		$data = ['duration_minutes' => $this->toMinutes($seconds)];
		if ($startedAt !== null && $startedAt !== '') {
			$data['entered_on'] = $this->enteredOn($startedAt);
		}
		$response = $this->request('PUT', $this->apiRoot($connection) . '/time_tracking_entries/' . rawurlencode($recordId), [
			'headers' => $this->writeHeaders($connection),
			'body' => json_encode(['data' => $data]),
		], $connection);
		$this->assertTimeTrackingAvailable($response);
		$this->json($response, 'Update time entry');
	}

	public function deleteTime(Connection $connection, array $refParts, string $recordId): void {
		$response = $this->request('DELETE', $this->apiRoot($connection) . '/time_tracking_entries/' . rawurlencode($recordId), [
			'headers' => $this->defaultHeaders($connection),
		], $connection);
		$this->assertTimeTrackingAvailable($response);
		$this->json($response, 'Delete time entry');
	}

	// ---- Create ------------------------------------------------------------

	public function supportsCreate(): bool {
		return true;
	}

	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array {
		if ($project !== null && $project !== '') {
			return [
				'projects' => [],
				'capabilities' => ['type' => false, 'typeRequired' => false],
				'fields' => array_merge(
					[$this->field('due_on', 'Due date', 'date')],
					$this->projectCustomFieldMeta($connection, $project)['descriptors'],
				),
			];
		}
		$workspace = $this->resolveWorkspace($connection);
		$rows = $this->data(
			$this->json(
				$this->request('GET', $this->apiRoot($connection) . '/projects', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['workspace' => $workspace, 'archived' => 'false', 'opt_fields' => 'name', 'limit' => '100'],
				], $connection),
				'Projects',
			),
		);
		$projects = [];
		foreach ($rows as $raw) {
			if (is_array($raw) && isset($raw['gid'])) {
				$projects[] = ['id' => (string)$raw['gid'], 'name' => (string)($raw['name'] ?? $raw['gid']), 'types' => []];
			}
		}
		return [
			'projects' => $this->filterProjectsByQuery($projects, $query),
			'capabilities' => ['type' => false, 'typeRequired' => false],
			'fields' => [],
		];
	}

	public function createIssue(Connection $connection, array $target): Issue {
		$project = (string)$target['project'];
		if ($project === '') {
			throw new TrackerException('A project is required');
		}
		$data = [
			'name' => (string)$target['title'],
			'html_notes' => $this->html->fromMarkdown((string)($target['description'] ?? '')),
			'workspace' => $this->resolveWorkspace($connection),
			'projects' => [$project],
		];
		$fields = is_array($target['fields'] ?? null) ? $target['fields'] : [];
		if ($fields !== []) {
			$this->applyFields($data, $fields, $this->projectCustomFieldMeta($connection, $project)['types']);
		}
		$raw = $this->data(
			$this->json(
				$this->request('POST', $this->apiRoot($connection) . '/tasks', [
					'headers' => $this->writeHeaders($connection),
					'body' => json_encode(['data' => $data]),
					'query' => ['opt_fields' => self::DETAIL_FIELDS],
				], $connection),
				'Create issue',
			),
		);
		return $this->normalizeIssue($connection, $raw);
	}

	// ---- Edit --------------------------------------------------------------

	public function getEditMeta(Connection $connection, array $refParts, ?string $type = null): array {
		$workspace = $this->resolveWorkspace($connection);

		$assignees = [];
		try {
			$users = $this->data(
				$this->json(
					$this->request('GET', $this->apiRoot($connection) . '/users', [
						'headers' => $this->defaultHeaders($connection),
						'query' => ['workspace' => $workspace, 'opt_fields' => 'name', 'limit' => '100'],
					], $connection),
					'Users',
				),
			);
			foreach ($users as $user) {
				if (is_array($user) && isset($user['gid'])) {
					$assignees[] = ['id' => (string)$user['gid'], 'name' => (string)($user['name'] ?? $user['gid'])];
				}
			}
		} catch (TrackerException $e) {
		}

		$labels = [];
		try {
			$labels = $this->fetchWorkspaceTags($connection);
		} catch (TrackerException $e) {
		}

		$fields = [];
		try {
			$fields = $this->taskFieldDescriptors($connection, $refParts);
		} catch (TrackerException $e) {
		}

		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => false, 'labels' => true, 'labelsFreeText' => false],
			'statuses' => [['id' => 'incomplete', 'name' => 'Incomplete'], ['id' => 'completed', 'name' => 'Completed']],
			'assignees' => $assignees,
			'labels' => $labels,
			'fields' => $fields,
		];
	}

	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue {
		$data = [];
		if (array_key_exists('title', $changes)) {
			$data['name'] = (string)$changes['title'];
		}
		if (array_key_exists('description', $changes)) {
			$data['html_notes'] = $this->html->fromMarkdown((string)$changes['description']);
		}
		if (array_key_exists('status', $changes)) {
			$data['completed'] = (string)$changes['status'] === 'completed';
		}
		if (array_key_exists('assignee', $changes)) {
			$assignee = (string)$changes['assignee'];
			$data['assignee'] = $assignee === '' ? null : $assignee;
		}
		if (isset($changes['fields']) && is_array($changes['fields']) && $changes['fields'] !== []) {
			$this->applyFields($data, $changes['fields'], $this->taskCustomFieldTypes($connection, $refParts));
		}
		if ($data !== []) {
			$this->json(
				$this->request('PUT', $this->taskUrl($connection, $refParts), [
					'headers' => $this->writeHeaders($connection),
					'body' => json_encode(['data' => $data]),
				], $connection),
				'Update issue',
			);
		}

		if (array_key_exists('labels', $changes) && is_array($changes['labels'])) {
			$this->syncTags($connection, $refParts, array_values(array_map('strval', $changes['labels'])));
		}

		return $this->getIssue($connection, $refParts);
	}

	// ---- Helpers -----------------------------------------------------------

	private function taskUrl(Connection $connection, array $refParts): string {
		return $this->apiRoot($connection) . '/tasks/' . rawurlencode((string)($refParts['gid'] ?? ''));
	}

	/**
	 * The task's workspace, resolved from the connection setting or the token's
	 * first workspace. Memoized per connection for the life of this request.
	 */
	private function resolveWorkspace(Connection $connection): string {
		$configured = trim((string)($connection->settings['workspace'] ?? ''));
		if ($configured !== '') {
			return $configured;
		}
		if (isset($this->workspaceCache[$connection->id])) {
			return $this->workspaceCache[$connection->id];
		}
		$rows = $this->data(
			$this->json(
				$this->request('GET', $this->apiRoot($connection) . '/workspaces', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['opt_fields' => 'name', 'limit' => '1'],
				], $connection),
				'Workspaces',
			),
		);
		$gid = isset($rows[0]) && is_array($rows[0]) ? (string)($rows[0]['gid'] ?? '') : '';
		if ($gid === '') {
			throw new TrackerException('No Asana workspace available for this token');
		}
		return $this->workspaceCache[$connection->id] = $gid;
	}

	/** The connection user's own Asana gid, or '' if it can't be resolved. */
	private function currentUserGid(Connection $connection): string {
		if (isset($this->userGidCache[$connection->id])) {
			return $this->userGidCache[$connection->id];
		}
		try {
			$data = $this->data(
				$this->json(
					$this->request('GET', $this->apiRoot($connection) . '/users/me', [
						'headers' => $this->defaultHeaders($connection),
					], $connection),
					'Get current user',
				),
			);
			return $this->userGidCache[$connection->id] = (string)($data['gid'] ?? '');
		} catch (TrackerException $e) {
			return $this->userGidCache[$connection->id] = '';
		}
	}

	/** Unwrap the {"data": …} envelope, returning the inner array. */
	private function data(array $payload): array {
		$inner = $payload['data'] ?? null;
		return is_array($inner) ? $inner : [];
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeIssue(Connection $connection, array $raw): Issue {
		$gid = (string)($raw['gid'] ?? '');
		$labels = [];
		foreach (($raw['tags'] ?? []) as $tag) {
			if (is_array($tag) && isset($tag['name'])) {
				$labels[] = (string)$tag['name'];
			}
		}
		$project = (string)($raw['memberships'][0]['project']['name'] ?? ($raw['projects'][0]['name'] ?? ''));
		$html = (string)($raw['html_notes'] ?? '');
		$description = $html !== '' ? $this->html->toText($html) : (string)($raw['notes'] ?? '');

		return new Issue(
			Ref::encode('asana', $connection->id, ['gid' => $gid]),
			'asana',
			$connection->id,
			$connection->label,
			'#' . $gid,
			(string)($raw['name'] ?? ''),
			$description,
			($raw['completed'] ?? false) === true ? 'completed' : 'incomplete',
			'',
			(string)($raw['assignee']['name'] ?? ''),
			$labels,
			$project,
			$raw['created_at'] ?? null,
			$raw['modified_at'] ?? null,
			(string)($raw['permalink_url'] ?? ''),
			null,
			'markdown',
			$html !== '' ? $this->html->toRenderedHtml($html) : null,
		);
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeComment(array $raw, string $me): Comment {
		$authorGid = (string)($raw['created_by']['gid'] ?? '');
		$own = $me !== '' && $authorGid === $me;
		$html = (string)($raw['html_text'] ?? '');
		$body = $html !== '' ? $this->html->toText($html) : (string)($raw['text'] ?? '');
		return new Comment(
			(string)($raw['gid'] ?? ''),
			(string)($raw['created_by']['name'] ?? ''),
			null,
			$body,
			$raw['created_at'] ?? null,
			null,
			$html !== '' ? $this->html->toRenderedHtml($html) : null,
			editable: $own,
			deletable: $own,
		);
	}

	private function toMinutes(int $seconds): int {
		return (int)round($seconds / 60);
	}

	private function enteredOn(?string $startedAt): string {
		if ($startedAt !== null && $startedAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $startedAt) === 1) {
			return substr($startedAt, 0, 10);
		}
		return date('Y-m-d');
	}

	private function assertTimeTrackingAvailable(\OCP\Http\Client\IResponse $response): void {
		if (in_array($response->getStatusCode(), [402, 403], true)) {
			throw new TrackerException('Time tracking requires a paid Asana plan (Advanced or above)');
		}
	}

	/**
	 * Add/remove tags so the task's tag set matches $wanted (tag *names*, matching
	 * the name-based label channel used by the edit form). Asana identifies tags by
	 * gid and has no "set tags" on the task update, so the change is expressed as a
	 * diff and each wanted name is resolved to a workspace tag gid before writing.
	 *
	 * @param string[] $wanted tag names
	 */
	private function syncTags(Connection $connection, array $refParts, array $wanted): void {
		$raw = $this->data(
			$this->json(
				$this->request('GET', $this->taskUrl($connection, $refParts), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['opt_fields' => 'tags.gid,tags.name'],
				], $connection),
				'Get task tags',
			),
		);
		$currentByName = [];
		foreach (($raw['tags'] ?? []) as $tag) {
			if (is_array($tag) && isset($tag['gid'], $tag['name'])) {
				$currentByName[(string)$tag['name']] = (string)$tag['gid'];
			}
		}
		$wanted = array_values(array_unique(array_filter($wanted, static fn (string $n): bool => $n !== '')));

		foreach (array_diff(array_keys($currentByName), $wanted) as $name) {
			$this->tagMutation($connection, $refParts, 'removeTag', $currentByName[$name]);
		}

		$toAdd = array_diff($wanted, array_keys($currentByName));
		if ($toAdd === []) {
			return;
		}
		// Resolve names to gids via the workspace tag list. With labelsFreeText=false
		// the form only offers existing tags, so any add should resolve; skip unknowns.
		$byName = [];
		foreach ($this->fetchWorkspaceTags($connection) as $tag) {
			$byName[$tag['name']] ??= $tag['id'];
		}
		foreach ($toAdd as $name) {
			if (isset($byName[$name])) {
				$this->tagMutation($connection, $refParts, 'addTag', $byName[$name]);
			}
		}
	}

	/**
	 * The workspace's tags as label options, first-wins on duplicate names.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	private function fetchWorkspaceTags(Connection $connection): array {
		$workspace = $this->resolveWorkspace($connection);
		$tags = $this->data(
			$this->json(
				$this->request('GET', $this->apiRoot($connection) . '/workspaces/' . rawurlencode($workspace) . '/tags', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['opt_fields' => 'name', 'limit' => '100'],
				], $connection),
				'Tags',
			),
		);
		$labels = [];
		foreach ($tags as $tag) {
			if (is_array($tag) && isset($tag['gid'])) {
				$labels[] = ['id' => (string)$tag['gid'], 'name' => (string)($tag['name'] ?? $tag['gid'])];
			}
		}
		return $labels;
	}

	private function tagMutation(Connection $connection, array $refParts, string $action, string $tagGid): void {
		$this->json(
			$this->request('POST', $this->taskUrl($connection, $refParts) . '/' . $action, [
				'headers' => $this->writeHeaders($connection),
				'body' => json_encode(['data' => ['tag' => $tagGid]]),
			], $connection),
			ucfirst($action),
		);
	}

	// ---- Custom fields -----------------------------------------------------

	/**
	 * Custom-field descriptors + a gid→Asana-type map for a project (used by
	 * create, which has no task to read fields from).
	 *
	 * @return array{descriptors: list<array<string, mixed>>, types: array<string, string>}
	 */
	private function projectCustomFieldMeta(Connection $connection, string $projectGid): array {
		$rows = $this->data(
			$this->json(
				$this->request('GET', $this->apiRoot($connection) . '/projects/' . rawurlencode($projectGid) . '/custom_field_settings', [
					'headers' => $this->defaultHeaders($connection),
					'query' => [
						'opt_fields' => 'custom_field.name,custom_field.gid,custom_field.resource_subtype,custom_field.enum_options.name,custom_field.enum_options.gid,custom_field.enum_options.enabled',
						'limit' => '100',
					],
				], $connection),
				'Custom fields',
			),
		);
		$descriptors = [];
		$types = [];
		foreach ($rows as $row) {
			$cf = is_array($row) ? ($row['custom_field'] ?? null) : null;
			if (!is_array($cf)) {
				continue;
			}
			$descriptor = $this->customFieldDescriptor($cf);
			if ($descriptor === null) {
				continue;
			}
			$descriptors[] = $descriptor;
			$types[(string)$cf['gid']] = (string)($cf['resource_subtype'] ?? '');
		}
		return ['descriptors' => $descriptors, 'types' => $types];
	}

	/**
	 * Dynamic-field descriptors for a task, each carrying its current value (used
	 * to preselect the edit form): the native `due_on` Due date followed by the
	 * task's custom fields. A single task read yields options and values.
	 *
	 * @param array $refParts
	 * @return list<array<string, mixed>>
	 */
	private function taskFieldDescriptors(Connection $connection, array $refParts): array {
		$raw = $this->data(
			$this->json(
				$this->request('GET', $this->taskUrl($connection, $refParts), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['opt_fields' => self::CUSTOM_FIELD_READ . ',due_on'],
				], $connection),
				'Custom fields',
			),
		);
		$due = $this->field('due_on', 'Due date', 'date');
		$dueValue = $raw['due_on'] ?? null;
		if (is_string($dueValue) && $dueValue !== '') {
			$due['value'] = $dueValue;
		}
		$descriptors = [$due];
		foreach (($raw['custom_fields'] ?? []) as $cf) {
			if (!is_array($cf)) {
				continue;
			}
			$descriptor = $this->customFieldDescriptor($cf);
			if ($descriptor === null) {
				continue;
			}
			$value = $this->currentCustomFieldValue($cf);
			if ($value !== null) {
				$descriptor['value'] = $value;
			}
			$descriptors[] = $descriptor;
		}
		return $descriptors;
	}

	/**
	 * gid→Asana-type map for a task's custom fields (used by update to coerce
	 * written values without re-reading options).
	 *
	 * @param array $refParts
	 * @return array<string, string>
	 */
	private function taskCustomFieldTypes(Connection $connection, array $refParts): array {
		$raw = $this->data(
			$this->json(
				$this->request('GET', $this->taskUrl($connection, $refParts), [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['opt_fields' => 'custom_fields.gid,custom_fields.resource_subtype'],
				], $connection),
				'Custom fields',
			),
		);
		$types = [];
		foreach (($raw['custom_fields'] ?? []) as $cf) {
			if (is_array($cf) && isset($cf['gid'])) {
				$types[(string)$cf['gid']] = (string)($cf['resource_subtype'] ?? '');
			}
		}
		return $types;
	}

	/**
	 * Build a dynamic-field descriptor from an Asana custom_field object, or null
	 * for unsupported types (e.g. people).
	 *
	 * @param array<string, mixed> $cf
	 * @return array<string, mixed>|null
	 */
	private function customFieldDescriptor(array $cf): ?array {
		$gid = (string)($cf['gid'] ?? '');
		$name = (string)($cf['name'] ?? $gid);
		if ($gid === '') {
			return null;
		}
		switch ((string)($cf['resource_subtype'] ?? '')) {
			case 'text':
				return $this->field($gid, $name, 'text');
			case 'number':
				return $this->field($gid, $name, 'float');
			case 'date':
				return $this->field($gid, $name, 'date');
			case 'enum':
				return $this->field($gid, $name, 'select', ['options' => $this->enumOptions($cf)]);
			case 'multi_enum':
				return $this->field($gid, $name, 'multiselect', ['options' => $this->enumOptions($cf), 'multiple' => true]);
			default:
				return null;
		}
	}

	/**
	 * @param array<string, mixed> $cf
	 * @return list<array{id: string, name: string}>
	 */
	private function enumOptions(array $cf): array {
		$options = [];
		foreach (($cf['enum_options'] ?? []) as $opt) {
			if (is_array($opt) && isset($opt['gid']) && ($opt['enabled'] ?? true) !== false) {
				$options[] = ['id' => (string)$opt['gid'], 'name' => (string)($opt['name'] ?? $opt['gid'])];
			}
		}
		return $options;
	}

	/**
	 * The current value of a task custom field in the shape the edit form expects.
	 *
	 * @param array<string, mixed> $cf
	 * @return string|list<string>|null
	 */
	private function currentCustomFieldValue(array $cf) {
		switch ((string)($cf['resource_subtype'] ?? '')) {
			case 'enum':
				$gid = $cf['enum_value']['gid'] ?? null;
				return is_string($gid) ? $gid : null;
			case 'multi_enum':
				$values = [];
				foreach (($cf['multi_enum_values'] ?? []) as $v) {
					if (is_array($v) && isset($v['gid'])) {
						$values[] = (string)$v['gid'];
					}
				}
				return $values;
			case 'number':
				return isset($cf['number_value']) ? (string)$cf['number_value'] : null;
			case 'date':
				$date = $cf['date_value']['date'] ?? null;
				return is_string($date) ? $date : null;
			case 'text':
				return isset($cf['text_value']) && is_string($cf['text_value']) ? $cf['text_value'] : null;
			default:
				return null;
		}
	}

	/**
	 * Apply the dynamic-field channel to a task payload: `due_on` is a native
	 * field, everything else is a custom field keyed by its gid.
	 *
	 * @param array<string, mixed> $data task payload (mutated)
	 * @param array<string, mixed> $fields id→value from the form
	 * @param array<string, string> $types gid→Asana custom-field type
	 */
	private function applyFields(array &$data, array $fields, array $types): void {
		$custom = [];
		foreach ($fields as $id => $value) {
			$id = (string)$id;
			if ($id === 'due_on') {
				$data['due_on'] = ($value === '' || $value === null) ? null : (string)$value;
				continue;
			}
			if (!isset($types[$id])) {
				continue;
			}
			$custom[$id] = $this->coerceCustomValue($types[$id], $value);
		}
		if ($custom !== []) {
			$data['custom_fields'] = $custom;
		}
	}

	/**
	 * Coerce a form value into the JSON shape Asana expects for a custom-field type.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function coerceCustomValue(string $type, $value) {
		switch ($type) {
			case 'enum':
				return ($value === '' || $value === null) ? null : (string)$value;
			case 'multi_enum':
				if (is_array($value)) {
					return array_values(array_map('strval', $value));
				}
				return ($value === '' || $value === null) ? [] : [(string)$value];
			case 'number':
				return is_numeric($value) ? $value + 0 : null;
			case 'date':
				return ($value === '' || $value === null) ? null : (string)$value;
			default:
				return (string)$value;
		}
	}

	private function guessMime(string $filename): string {
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		return match ($ext) {
			'png' => 'image/png',
			'jpg', 'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'pdf' => 'application/pdf',
			'txt' => 'text/plain',
			'zip' => 'application/zip',
			default => 'application/octet-stream',
		};
	}
}
