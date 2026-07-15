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

/**
 * GitLab REST API v4 client. Auth via PRIVATE-TOKEN header. Lists issues
 * globally (scope=all) with page-based pagination; issue-scoped endpoints use
 * the per-project internal id (iid). Text is Markdown (no conversion needed).
 */
class GitLabClient extends AbstractTrackerClient {

	public function getTrackerId(): string {
		return 'gitlab';
	}

	private function apiRoot(Connection $connection): string {
		return rtrim($connection->baseUrl, '/') . '/api/v4';
	}

	protected function authHeaders(Connection $connection): array {
		return [
			'PRIVATE-TOKEN' => $connection->token,
			'Content-Type' => 'application/json',
		];
	}

	public function testConnection(Connection $connection): array {
		try {
			$response = $this->request('GET', $this->apiRoot($connection) . '/user', [
				'headers' => $this->defaultHeaders($connection),
			], $connection);
			$data = $this->json($response, 'Authentication');
			return ['ok' => true, 'message' => 'Connected', 'user' => (string)($data['username'] ?? '')];
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
					// not a resolvable project/iid here — fall through to search
				}
			}
		}
		$params = [
			'scope' => $query->assignedToMe ? 'assigned_to_me' : 'all',
			'order_by' => $this->orderBy($query->sort),
			'sort' => strtolower($query->order) === 'asc' ? 'asc' : 'desc',
			'per_page' => (string)$query->limit,
			'state' => $query->showClosed ? 'all' : 'opened',
		];
		if (trim($query->term) !== '') {
			$params['search'] = $query->term;
			$params['in'] = 'title,description';
		}
		if ($cursor !== null && $cursor !== '') {
			$params['page'] = $cursor;
		}
		$response = $this->request('GET', $this->apiRoot($connection) . '/issues', [
			'headers' => $this->defaultHeaders($connection),
			'query' => $params,
		], $connection);
		$data = $this->json($response, 'Search');

		$issues = [];
		foreach ($data as $raw) {
			if (is_array($raw)) {
				$issues[] = $this->normalizeIssue($connection, $raw);
			}
		}
		$nextPage = $response->getHeader('X-Next-Page');
		return new TrackerSearchResult($issues, $nextPage !== '' ? $nextPage : null);
	}

	public function getIssue(Connection $connection, array $refParts): Issue {
		$data = $this->json(
			$this->request('GET', $this->issueUrl($connection, $refParts), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Get issue',
		);
		$issue = $this->normalizeIssue($connection, $data);
		// Render the Markdown (incl. embedded raw HTML like <details>) via GitLab's
		// own renderer so the UI matches GitLab exactly; the raw description is kept
		// for editing. Only done for the single-issue view, never the list.
		$issue->renderedDescription = $this->renderMarkdown($connection, $issue->description, (string)($refParts['path'] ?? ''));
		return $issue;
	}

	public function getComments(Connection $connection, array $refParts): array {
		$data = $this->json(
			$this->request('GET', $this->issueUrl($connection, $refParts) . '/notes', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['sort' => 'asc', 'order_by' => 'created_at'],
			], $connection),
			'Get comments',
		);
		$path = (string)($refParts['path'] ?? '');
		$currentUsername = $this->currentUsername($connection);
		$comments = [];
		foreach ($data as $raw) {
			if (!is_array($raw) || ($raw['system'] ?? false) === true) {
				continue;
			}
			$body = (string)($raw['body'] ?? '');
			// Only the author may edit/delete their own note (identity is the
			// username, not the display name).
			$own = $currentUsername !== '' && (string)($raw['author']['username'] ?? '') === $currentUsername;
			$comment = new Comment(
				(string)($raw['id'] ?? ''),
				(string)($raw['author']['name'] ?? $raw['author']['username'] ?? ''),
				$raw['author']['avatar_url'] ?? null,
				$body,
				$raw['created_at'] ?? null,
				editable: $own,
				deletable: $own,
			);
			$comment->renderedBody = $this->renderMarkdown($connection, $body, $path);
			$comments[] = $comment;
		}
		return $comments;
	}

	public function supportsMentions(): bool {
		return true;
	}

	/** Rewrite canonical @mention tokens to GitLab's native `@username` form. */
	private function mentions(string $text): string {
		return $this->replaceMentionTokens($text, static fn (string $h): string => '@' . $h);
	}

	public function addComment(Connection $connection, array $refParts, string $body): Comment {
		$body = $this->mentions($body);
		$raw = $this->json(
			$this->request('POST', $this->issueUrl($connection, $refParts) . '/notes', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $body]),
			], $connection),
			'Add comment',
		);
		$newBody = (string)($raw['body'] ?? $body);
		$comment = new Comment(
			(string)($raw['id'] ?? ''),
			(string)($raw['author']['name'] ?? ''),
			$raw['author']['avatar_url'] ?? null,
			$newBody,
			$raw['created_at'] ?? null,
			// The current user just authored it, so it is theirs to edit/delete.
			editable: true,
			deletable: true,
		);
		$comment->renderedBody = $this->renderMarkdown($connection, $newBody, (string)($refParts['path'] ?? ''));
		return $comment;
	}

	public function logTime(Connection $connection, array $refParts, int $seconds, string $comment, ?string $startedAt): void {
		$this->json(
			$this->request('POST', $this->issueUrl($connection, $refParts) . '/add_spent_time', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['duration' => $seconds . 's', 'summary' => $comment],
			], $connection),
			'Log time',
		);
	}

	public function updateComment(Connection $connection, array $refParts, string $commentId, string $body): Comment {
		$body = $this->mentions($body);
		$raw = $this->json(
			$this->request('PUT', $this->issueUrl($connection, $refParts) . '/notes/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $body]),
			], $connection),
			'Update comment',
		);
		$newBody = (string)($raw['body'] ?? $body);
		$comment = new Comment(
			(string)($raw['id'] ?? $commentId),
			(string)($raw['author']['name'] ?? ''),
			$raw['author']['avatar_url'] ?? null,
			$newBody,
			$raw['created_at'] ?? null,
			editable: true,
			deletable: true,
		);
		$comment->renderedBody = $this->renderMarkdown($connection, $newBody, (string)($refParts['path'] ?? ''));
		return $comment;
	}

	public function deleteComment(Connection $connection, array $refParts, string $commentId): void {
		$this->json(
			$this->request('DELETE', $this->issueUrl($connection, $refParts) . '/notes/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete comment',
		);
	}

	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue {
		$body = [];
		if (array_key_exists('title', $changes)) {
			$body['title'] = (string)$changes['title'];
		}
		if (array_key_exists('description', $changes)) {
			$body['description'] = $this->mentions((string)$changes['description']);
		}
		if (array_key_exists('status', $changes)) {
			$status = (string)$changes['status'];
			if ($status === 'closed') {
				$body['state_event'] = 'close';
			} elseif ($status === 'opened') {
				$body['state_event'] = 'reopen';
			}
		}
		if (array_key_exists('assignee', $changes)) {
			$assignee = (string)$changes['assignee'];
			$body['assignee_ids'] = $assignee === '' ? [] : [(int)$assignee];
		}
		if (array_key_exists('labels', $changes) && is_array($changes['labels'])) {
			$body['labels'] = implode(',', array_map('strval', $changes['labels']));
		}
		if (isset($changes['fields']) && is_array($changes['fields'])) {
			$this->applyFields($body, $changes['fields']);
		}
		$data = $this->json(
			$this->request('PUT', $this->issueUrl($connection, $refParts), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($body),
			], $connection),
			'Update issue',
		);
		return $this->normalizeIssue($connection, $data);
	}

	public function supportsCreate(): bool {
		return true;
	}

	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array {
		// A project context asks only for that project's field descriptors.
		if ($project !== null && $project !== '') {
			return [
				'projects' => [],
				'capabilities' => ['type' => false, 'typeRequired' => false, 'assignee' => true],
				'fields' => $this->describeFields($connection, $project),
			];
		}
		$params = [
			'membership' => 'true',
			'simple' => 'true',
			// 30 = Developer, the minimum access level that can create issues.
			'min_access_level' => '30',
			'per_page' => '100',
			'order_by' => 'last_activity_at',
		];
		// GitLab's /projects endpoint searches names/paths natively, so the query
		// reaches beyond the first page of results.
		if ($query !== null && trim($query) !== '') {
			$params['search'] = trim($query);
		}
		$data = $this->json(
			$this->request('GET', $this->apiRoot($connection) . '/projects', [
				'headers' => $this->defaultHeaders($connection),
				'query' => $params,
			], $connection),
			'Projects',
		);
		$projects = [];
		foreach ($data as $raw) {
			if (is_array($raw)) {
				$projects[] = [
					'id' => (string)($raw['id'] ?? ''),
					'name' => (string)($raw['name_with_namespace'] ?? $raw['path_with_namespace'] ?? $raw['name'] ?? ''),
					'types' => [],
				];
			}
		}
		return ['projects' => $projects, 'capabilities' => ['type' => false, 'typeRequired' => false], 'fields' => []];
	}

	public function createIssue(Connection $connection, array $target): Issue {
		$projectId = (string)$target['project'];
		if ($projectId === '') {
			throw new TrackerException('A project is required');
		}
		$body = [
			'title' => (string)$target['title'],
			'description' => $this->mentions((string)($target['description'] ?? '')),
		];
		$assignee = (string)($target['assignee'] ?? '');
		if ($assignee !== '') {
			$body['assignee_ids'] = [(int)$assignee];
		}
		$this->applyFields($body, is_array($target['fields'] ?? null) ? $target['fields'] : []);
		$data = $this->json(
			$this->request('POST', $this->apiRoot($connection) . '/projects/' . rawurlencode($projectId) . '/issues', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($body),
			], $connection),
			'Create issue',
		);
		return $this->normalizeIssue($connection, $data);
	}

	public function getEditMeta(Connection $connection, array $refParts, ?string $type = null): array {
		$project = rawurlencode((string)($refParts['project'] ?? ''));
		$labels = [];
		try {
			$found = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/projects/' . $project . '/labels', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['per_page' => '100'],
				], $connection),
				'Labels',
			);
			foreach ($found as $label) {
				if (is_array($label) && isset($label['name'])) {
					$labels[] = ['id' => (string)$label['name'], 'name' => (string)$label['name']];
				}
			}
		} catch (TrackerException $e) {
		}
		$assignee = null;
		$fields = [];
		try {
			$projectId = (string)($refParts['project'] ?? '');
			$issue = $this->json(
				$this->request('GET', $this->issueUrl($connection, $refParts), ['headers' => $this->defaultHeaders($connection)], $connection),
				'Issue',
			);
			$assignee = $this->currentAssignee($issue);
			$fields = $this->describeFields($connection, $projectId, $this->currentFieldValues($issue));
		} catch (TrackerException $e) {
		}
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => true, 'labels' => true, 'labelsFreeText' => false],
			'statuses' => [['id' => 'opened', 'name' => 'Open'], ['id' => 'closed', 'name' => 'Closed']],
			'assignee' => $assignee,
			'labels' => $labels,
			'fields' => $fields,
		];
	}

	/**
	 * The issue's current assignee as {id, name}, or null if unassigned.
	 *
	 * @param array<string, mixed> $issue
	 * @return array{id: string, name: string}|null
	 */
	private function currentAssignee(array $issue): ?array {
		$a = $issue['assignee'] ?? ($issue['assignees'][0] ?? null);
		if (!is_array($a) || !isset($a['id'])) {
			return null;
		}
		return ['id' => (string)$a['id'], 'name' => (string)($a['name'] ?? $a['username'] ?? '')];
	}

	public function searchAssignees(Connection $connection, array $context, string $query): array {
		$project = isset($context['refParts'])
			? (string)($context['refParts']['project'] ?? '')
			: (string)($context['project'] ?? '');
		if ($project === '') {
			return [];
		}
		$params = ['per_page' => '50'];
		$query = trim($query);
		if ($query !== '') {
			$params['query'] = $query;
		}
		try {
			$members = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/projects/' . rawurlencode($project) . '/members/all', [
					'headers' => $this->defaultHeaders($connection),
					'query' => $params,
				], $connection),
				'Members',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($members as $member) {
			if (is_array($member) && isset($member['id'])) {
				// `id` is the numeric member id used for assignment; `mention` is the
				// @username handle GitLab resolves in comment/description bodies.
				$entry = ['id' => (string)$member['id'], 'name' => (string)($member['name'] ?? $member['username'] ?? '')];
				if (isset($member['username']) && (string)$member['username'] !== '') {
					$entry['mention'] = (string)$member['username'];
				}
				$out[] = $entry;
			}
		}
		return $out;
	}

	// ---- Dynamic fields ----------------------------------------------------

	/**
	 * @param array<string, mixed> $current
	 * @return list<array<string, mixed>>
	 */
	private function describeFields(Connection $connection, string $projectId, array $current = []): array {
		$fields = [];
		$add = function (string $id, string $name, string $type, array $extra = []) use (&$fields, $current): void {
			if (array_key_exists($id, $current)) {
				$extra['value'] = $current[$id];
			}
			$fields[] = $this->field($id, $name, $type, $extra);
		};
		$milestones = $this->milestoneOptions($connection, $projectId);
		if ($milestones !== []) {
			$add('milestone_id', 'Milestone', 'select', ['options' => $milestones]);
		}
		$add('due_date', 'Due date', 'date');
		$add('weight', 'Weight', 'int');
		$add('confidential', 'Confidential', 'bool');
		return $fields;
	}

	/**
	 * @return list<array{id: string, name: string}>
	 */
	private function milestoneOptions(Connection $connection, string $projectId): array {
		if ($projectId === '') {
			return [];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/projects/' . rawurlencode($projectId) . '/milestones', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['state' => 'active', 'per_page' => '100'],
				], $connection),
				'Milestones',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data as $m) {
			if (is_array($m) && isset($m['id'])) {
				$out[] = ['id' => (string)$m['id'], 'name' => (string)($m['title'] ?? $m['id'])];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $body issue payload (mutated)
	 * @param array<string, mixed> $fields
	 */
	private function applyFields(array &$body, array $fields): void {
		foreach ($fields as $id => $value) {
			switch ($id) {
				case 'milestone_id':
				case 'weight':
					if ($value !== '' && $value !== null) {
						$body[$id] = (int)$value;
					}
					break;
				case 'due_date':
					if ($value !== '' && $value !== null) {
						$body['due_date'] = substr((string)$value, 0, 10);
					}
					break;
				case 'confidential':
					$body['confidential'] = (bool)$value;
					break;
			}
		}
	}

	/**
	 * @param array<string, mixed> $issue
	 * @return array<string, mixed>
	 */
	private function currentFieldValues(array $issue): array {
		$current = [];
		if (isset($issue['milestone']['id'])) {
			$current['milestone_id'] = (string)$issue['milestone']['id'];
		}
		if (isset($issue['due_date']) && is_string($issue['due_date'])) {
			$current['due_date'] = $issue['due_date'];
		}
		if (isset($issue['weight'])) {
			$current['weight'] = (string)$issue['weight'];
		}
		if (isset($issue['confidential'])) {
			$current['confidential'] = (bool)$issue['confidential'];
		}
		return $current;
	}

	// ---- Relations ---------------------------------------------------------

	/** GitLab link_type => human label, from the current issue's perspective. */
	private const RELATION_LABELS = [
		'relates_to' => 'Relates to',
		'blocks' => 'Blocks',
		'is_blocked_by' => 'Is blocked by',
	];

	public function supportsRelations(): bool {
		return true;
	}

	/**
	 * `relates_to` works on every tier. Blocking relationships (`blocks`/
	 * `is_blocked_by`) require GitLab Premium/Ultimate, so they are only offered on
	 * instances that support them — Community Edition rejects those link_type values
	 * with "link_type does not have a valid value".
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public function getRelationTypes(Connection $connection, array $refParts): array {
		$types = [['id' => 'relates_to', 'name' => 'Relates to']];
		if ($this->supportsBlocking($connection)) {
			$types[] = ['id' => 'blocks', 'name' => 'Blocks'];
			$types[] = ['id' => 'is_blocked_by', 'name' => 'Is blocked by'];
		}
		return $types;
	}

	/**
	 * Whether this GitLab instance supports blocking relationships (a Premium/
	 * Ultimate feature, only present in Enterprise Edition). Detected from
	 * /api/v4/metadata's `enterprise` flag; any failure (older GitLab without the
	 * endpoint, Community Edition) is treated as unsupported.
	 */
	private function supportsBlocking(Connection $connection): bool {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/metadata', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Metadata',
			);
		} catch (TrackerException $e) {
			return false;
		}
		return ($data['enterprise'] ?? false) === true;
	}

	/**
	 * @param array $refParts
	 * @return \OCA\Unity\Model\Relation[]
	 */
	public function getRelations(Connection $connection, array $refParts): array {
		$data = $this->json(
			$this->request('GET', $this->issueUrl($connection, $refParts) . '/links', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Get relations',
		);
		$relations = [];
		foreach ($data as $raw) {
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
		$targetProject = (string)($targetParts['project'] ?? '');
		$targetIid = (string)($targetParts['iid'] ?? '');
		if ($targetProject === '' || $targetIid === '') {
			throw new TrackerException('Invalid target issue');
		}
		// GitLab's `is_blocked_by` POST is buggy (400 "link_type does not have a valid
		// value") even where blocking is licensed, so create the equivalent `blocks`
		// link from the target issue back to the current one instead.
		if ($type === 'is_blocked_by') {
			$linkUrl = $this->issueUrl($connection, $targetParts) . '/links';
			$linkBody = [
				'target_project_id' => (string)($refParts['project'] ?? ''),
				'target_issue_iid' => (string)($refParts['iid'] ?? ''),
				'link_type' => 'blocks',
			];
		} else {
			$linkUrl = $this->issueUrl($connection, $refParts) . '/links';
			$linkBody = [
				'target_project_id' => $targetProject,
				'target_issue_iid' => $targetIid,
				'link_type' => $type,
			];
		}
		// POST returns {source_issue, target_issue, link_type} without the link id,
		// so re-read the links to obtain the issue_link_id needed for deletion.
		$this->json(
			$this->request('POST', $linkUrl, [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($linkBody),
			], $connection),
			'Add relation',
		);
		foreach ($this->getRelations($connection, $refParts) as $relation) {
			$target = Ref::decode($relation->targetRef)['p'];
			if ((string)($target['project'] ?? '') === $targetProject && (string)($target['iid'] ?? '') === $targetIid) {
				return $relation;
			}
		}
		// The link was created (POST succeeded); return a best-effort relation rather
		// than failing if the read-back didn't surface it. The UI refetches for the
		// authoritative list (with the real issue_link_id).
		return new \OCA\Unity\Model\Relation(
			'',
			$type,
			self::RELATION_LABELS[$type] ?? 'Relates to',
			Ref::encode('gitlab', $connection->id, ['project' => $targetProject, 'iid' => $targetIid, 'path' => (string)($targetParts['path'] ?? '')]),
			'#' . $targetIid,
			'',
			'',
			'',
		);
	}

	/**
	 * @param array $refParts
	 */
	public function deleteRelation(Connection $connection, array $refParts, string $relationId): void {
		$this->json(
			$this->request('DELETE', $this->issueUrl($connection, $refParts) . '/links/' . rawurlencode($relationId), [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete relation',
		);
	}

	/**
	 * A linked-issue entry from GET /links is a full issue object annotated with
	 * `link_type` (from the current issue's perspective) and `issue_link_id`.
	 *
	 * @param array<string, mixed> $raw
	 */
	private function buildRelation(Connection $connection, array $raw): \OCA\Unity\Model\Relation {
		$linkType = (string)($raw['link_type'] ?? 'relates_to');
		$iid = (string)($raw['iid'] ?? '');
		$projectId = (string)($raw['project_id'] ?? '');
		$path = '';
		if (isset($raw['references']['full']) && is_string($raw['references']['full'])) {
			$path = explode('#', $raw['references']['full'])[0];
		}
		return new \OCA\Unity\Model\Relation(
			(string)($raw['issue_link_id'] ?? ''),
			$linkType,
			self::RELATION_LABELS[$linkType] ?? 'Relates to',
			Ref::encode('gitlab', $connection->id, ['project' => $projectId, 'iid' => $iid, 'path' => $path]),
			'#' . $iid,
			(string)($raw['title'] ?? ''),
			(string)($raw['state'] ?? ''),
			(string)($raw['web_url'] ?? ''),
		);
	}

	protected function fileHeaders(Connection $connection): array {
		return ['PRIVATE-TOKEN' => $connection->token];
	}

	protected function resolveFileUrl(Connection $connection, array $refParts, string $src): string {
		$src = trim($src);
		if ($src === '') {
			throw new TrackerException('Empty file source');
		}
		if (preg_match('#^https?://#i', $src) !== 1) {
			// Project-relative upload: /uploads/{secret}/{filename} → uploads API.
			if (preg_match('#/uploads/([0-9a-fA-F]+)/(.+)$#', $src, $m) === 1) {
				$projectId = rawurlencode((string)($refParts['project'] ?? ''));
				return $this->apiRoot($connection) . '/projects/' . $projectId
					. '/uploads/' . $m[1] . '/' . rawurlencode($m[2]);
			}
			throw new TrackerException('Unsupported relative file path');
		}
		$host = strtolower((string)parse_url($src, PHP_URL_HOST));
		$baseHost = strtolower((string)parse_url($connection->baseUrl, PHP_URL_HOST));
		if ($host === '' || $host !== $baseHost) {
			throw new TrackerException('File host not allowed');
		}
		return $src;
	}

	/**
	 * Detect a "group/path #iid" reference (the detail-link text) so it jumps
	 * straight to that issue. Requires a project path (a slash) to scope the iid.
	 *
	 * @return array{project: string, iid: string}|null
	 */
	private function parseReference(string $term): ?array {
		if (preg_match('~^(.+/.+?)\s*#?(\d+)$~', trim($term), $m) === 1) {
			return ['project' => trim($m[1]), 'iid' => $m[2]];
		}
		return null;
	}

	private function issueUrl(Connection $connection, array $refParts): string {
		$project = rawurlencode((string)($refParts['project'] ?? ''));
		$iid = rawurlencode((string)($refParts['iid'] ?? ''));
		return $this->apiRoot($connection) . '/projects/' . $project . '/issues/' . $iid;
	}

	private function orderBy(string $sort): string {
		return match ($sort) {
			'created' => 'created_at',
			'title' => 'title',
			default => 'updated_at',
		};
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeIssue(Connection $connection, array $raw): Issue {
		$iid = (string)($raw['iid'] ?? '');
		$projectId = (string)($raw['project_id'] ?? '');
		$labels = [];
		foreach (($raw['labels'] ?? []) as $label) {
			if (is_string($label)) {
				$labels[] = $label;
			}
		}
		$assignee = '';
		if (isset($raw['assignee']['name'])) {
			$assignee = (string)$raw['assignee']['name'];
		} elseif (isset($raw['assignees'][0]['name'])) {
			$assignee = (string)$raw['assignees'][0]['name'];
		}
		$project = '';
		if (isset($raw['references']['full']) && is_string($raw['references']['full'])) {
			$project = explode('#', $raw['references']['full'])[0];
		}
		$timeSpent = $raw['time_stats']['total_time_spent'] ?? null;

		return new Issue(
			Ref::encode('gitlab', $connection->id, ['project' => $projectId, 'iid' => $iid, 'path' => $project]),
			'gitlab',
			$connection->id,
			$connection->label,
			'#' . $iid,
			(string)($raw['title'] ?? ''),
			(string)($raw['description'] ?? ''),
			(string)($raw['state'] ?? ''),
			(string)($raw['author']['name'] ?? ''),
			$assignee,
			$labels,
			$project,
			$raw['created_at'] ?? null,
			$raw['updated_at'] ?? null,
			(string)($raw['web_url'] ?? ''),
			is_int($timeSpent) && $timeSpent > 0 ? $timeSpent : null,
			'markdown',
		);
	}

	public function getTimeRecords(Connection $connection, array $refParts): array {
		$path = (string)($refParts['path'] ?? '');
		$iid = (string)($refParts['iid'] ?? '');
		if ($path === '' || $iid === '') {
			return [];
		}
		// GitLab exposes individual timelogs only via GraphQL (REST has just the total).
		$query = 'query($fullPath: ID!, $iid: String!) {'
			. ' project(fullPath: $fullPath) { issue(iid: $iid) {'
			. ' timelogs { nodes { id timeSpent spentAt summary user { name username } } } } } }';
		$data = $this->graphql($connection, $query, ['fullPath' => $path, 'iid' => $iid], 'Get timelogs');
		$nodes = $data['data']['project']['issue']['timelogs']['nodes'] ?? [];
		$currentUsername = $this->currentUsername($connection);
		$records = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			// The GraphQL id is a global id (gid://gitlab/Timelog/123); keep only the
			// numeric part so it survives as a URL path segment, and rebuild the gid
			// in deleteTime().
			$numericId = (string)preg_replace('#^.*/#', '', (string)($node['id'] ?? ''));
			// Only the author may delete their own timelog.
			$own = $currentUsername !== '' && (string)($node['user']['username'] ?? '') === $currentUsername;
			$records[] = new TimeRecord(
				$numericId,
				(string)($node['user']['name'] ?? ''),
				(int)($node['timeSpent'] ?? 0),
				$node['spentAt'] ?? null,
				(string)($node['summary'] ?? ''),
				// GitLab's API cannot edit a timelog in place, only delete it.
				editable: false,
				deletable: $own && $numericId !== '',
			);
		}
		return $records;
	}

	/**
	 * Render GitLab-Flavored Markdown to HTML via GitLab's own `/api/v4/markdown`
	 * endpoint, so descriptions/comments (including embedded raw HTML such as
	 * <details>/<summary>) display exactly as GitLab renders them. The `project`
	 * context lets GitLab resolve references and relative upload paths.
	 *
	 * Returns null on empty input or any failure, so the UI falls back to the raw
	 * Markdown rather than showing a blank body.
	 */
	private function renderMarkdown(Connection $connection, string $text, string $projectPath): ?string {
		if (trim($text) === '') {
			return null;
		}
		try {
			$payload = ['text' => $text, 'gfm' => true];
			if ($projectPath !== '') {
				$payload['project'] = $projectPath;
			}
			$data = $this->json(
				$this->request('POST', $this->apiRoot($connection) . '/markdown', [
					'headers' => $this->defaultHeaders($connection),
					'body' => json_encode($payload),
				], $connection),
				'Render markdown',
			);
			$html = $data['html'] ?? null;
			return is_string($html) && $html !== '' ? $html : null;
		} catch (TrackerException $e) {
			return null;
		}
	}

	/** The connection user's own GitLab username, or '' if it can't be resolved. */
	private function currentUsername(Connection $connection): string {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/user', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Get current user',
			);
			return (string)($data['username'] ?? '');
		} catch (TrackerException $e) {
			return '';
		}
	}

	public function deleteTime(Connection $connection, array $refParts, string $recordId): void {
		if ($recordId === '') {
			throw new TrackerException('Missing timelog id');
		}
		$gid = str_starts_with($recordId, 'gid://') ? $recordId : 'gid://gitlab/Timelog/' . $recordId;
		$mutation = 'mutation($id: TimelogID!) { timelogDelete(input: { id: $id }) { errors } }';
		$data = $this->graphql($connection, $mutation, ['id' => $gid], 'Delete time');
		$errors = $data['data']['timelogDelete']['errors'] ?? [];
		if (is_array($errors) && $errors !== []) {
			throw new TrackerException('Delete time failed: ' . implode('; ', array_map('strval', $errors)));
		}
	}

	/**
	 * Run a GraphQL query/mutation and return the decoded body. Raises on transport
	 * errors and on top-level GraphQL `errors` (which HTTP 200 would otherwise hide).
	 *
	 * @param array<string, mixed> $variables
	 * @return array<mixed>
	 */
	private function graphql(Connection $connection, string $query, array $variables, string $context): array {
		$response = $this->request('POST', rtrim($connection->baseUrl, '/') . '/api/graphql', [
			'headers' => [
				'Authorization' => 'Bearer ' . $connection->token,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'User-Agent' => Application::USER_AGENT,
			],
			'body' => json_encode(['query' => $query, 'variables' => $variables]),
		], $connection);
		$data = $this->json($response, $context);
		if (isset($data['errors']) && is_array($data['errors']) && $data['errors'] !== []) {
			$first = $data['errors'][0]['message'] ?? 'GraphQL error';
			throw new TrackerException($context . ' failed: ' . (string)$first);
		}
		return $data;
	}
}
