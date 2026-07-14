<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use OCA\Unity\Model\Attachment;
use OCA\Unity\Model\Comment;
use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Model\TimeRecord;
use OCA\Unity\Model\TrackerSearchResult;

/**
 * Redmine REST API client. Self-hosted; every path needs the .json suffix and
 * REST must be enabled server-side. Auth via X-Redmine-API-Key. Comments are
 * added by updating the issue with a `notes` field and read back from journals.
 * Offset/limit pagination with a total_count.
 */
class RedmineClient extends AbstractTrackerClient {

	public function getTrackerId(): string {
		return 'redmine';
	}

	private function base(Connection $connection): string {
		return rtrim($connection->baseUrl, '/');
	}

	protected function authHeaders(Connection $connection): array {
		return [
			'X-Redmine-API-Key' => $connection->token,
			'Content-Type' => 'application/json',
		];
	}

	public function testConnection(Connection $connection): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/users/current.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Authentication',
			);
			$user = $data['user'] ?? [];
			$name = trim((string)($user['firstname'] ?? '') . ' ' . (string)($user['lastname'] ?? ''));
			return ['ok' => true, 'message' => 'Connected', 'user' => $name !== '' ? $name : (string)($user['login'] ?? '')];
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
					// no such issue id here — fall through to search
				}
			}
		}
		$offset = $cursor !== null && $cursor !== '' ? (int)$cursor : 0;
		$params = [
			'sort' => $this->sort($query),
			'limit' => (string)$query->limit,
			'offset' => (string)$offset,
			'status_id' => $query->showClosed ? '*' : 'open',
		];
		if ($query->assignedToMe) {
			$params['assigned_to_id'] = 'me';
		}
		if (trim($query->term) !== '') {
			// Redmine field filter: subject contains term.
			$params['subject'] = '~' . $query->term;
		}
		$response = $this->request('GET', $this->base($connection) . '/issues.json', [
			'headers' => $this->defaultHeaders($connection),
			'query' => $params,
		], $connection);
		$data = $this->json($response, 'Search');

		$issues = [];
		foreach (($data['issues'] ?? []) as $raw) {
			if (is_array($raw)) {
				$issues[] = $this->normalizeIssue($connection, $raw);
			}
		}
		$total = (int)($data['total_count'] ?? 0);
		$next = ($offset + $query->limit) < $total ? (string)($offset + $query->limit) : null;
		return new TrackerSearchResult($issues, $next);
	}

	public function getIssue(Connection $connection, array $refParts): Issue {
		$id = (string)($refParts['id'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['include' => 'journals'],
			], $connection),
			'Get issue',
		);
		return $this->normalizeIssue($connection, is_array($data['issue'] ?? null) ? $data['issue'] : []);
	}

	public function getComments(Connection $connection, array $refParts): array {
		$id = (string)($refParts['id'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['include' => 'journals'],
			], $connection),
			'Get comments',
		);
		$currentUserId = $this->currentUserId($connection);
		$comments = [];
		foreach (($data['issue']['journals'] ?? []) as $journal) {
			if (!is_array($journal)) {
				continue;
			}
			$notes = (string)($journal['notes'] ?? '');
			if (trim($notes) === '') {
				continue;
			}
			// Only the note's author may edit it.
			$own = $currentUserId !== '' && (string)($journal['user']['id'] ?? '') === $currentUserId;
			$comments[] = new Comment(
				(string)($journal['id'] ?? ''),
				(string)($journal['user']['name'] ?? ''),
				null,
				$notes,
				$journal['created_on'] ?? null,
				editable: $own,
			);
		}
		return $comments;
	}

	public function addComment(Connection $connection, array $refParts, string $body): Comment {
		$id = (string)($refParts['id'] ?? '');
		$this->json(
			$this->request('PUT', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['issue' => ['notes' => $body]]),
			], $connection),
			'Add comment',
		);
		// Redmine returns 204 No Content; synthesize the created note.
		return new Comment('', '', null, $body, null);
	}

	public function logTime(Connection $connection, array $refParts, int $seconds, string $comment, ?string $startedAt): void {
		$id = (int)($refParts['id'] ?? 0);
		$entry = [
			'issue_id' => $id,
			'hours' => round($seconds / 3600, 2),
			'comments' => $comment,
		];
		$activityId = (int)($connection->settings['activityId'] ?? 0);
		if ($activityId > 0) {
			$entry['activity_id'] = $activityId;
		}
		if ($startedAt !== null && $startedAt !== '') {
			$entry['spent_on'] = substr($startedAt, 0, 10);
		}
		$this->json(
			$this->request('POST', $this->base($connection) . '/time_entries.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['time_entry' => $entry]),
			], $connection),
			'Log time',
		);
	}

	public function updateComment(Connection $connection, array $refParts, string $commentId, string $body): Comment {
		$this->json(
			$this->request('PUT', $this->base($connection) . '/journals/' . rawurlencode($commentId) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['journal' => ['notes' => $body]]),
			], $connection),
			'Update comment',
		);
		return new Comment($commentId, '', null, $body, null);
	}

	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue {
		$id = (string)($refParts['id'] ?? '');
		$issue = [];
		if (array_key_exists('title', $changes)) {
			$issue['subject'] = (string)$changes['title'];
		}
		if (array_key_exists('description', $changes)) {
			$issue['description'] = (string)$changes['description'];
		}
		if (array_key_exists('status', $changes) && (string)$changes['status'] !== '') {
			$issue['status_id'] = (int)$changes['status'];
		}
		if (array_key_exists('assignee', $changes)) {
			$assignee = (string)$changes['assignee'];
			$issue['assigned_to_id'] = $assignee === '' ? '' : (int)$assignee;
		}
		if (array_key_exists('type', $changes) && (string)$changes['type'] !== '') {
			$issue['tracker_id'] = (int)$changes['type'];
		}
		if (isset($changes['fields']) && is_array($changes['fields'])) {
			$this->applyFields($issue, $changes['fields']);
		}
		if ($issue !== []) {
			$this->json(
				$this->request('PUT', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
					'headers' => $this->defaultHeaders($connection),
					'body' => json_encode(['issue' => $issue]),
				], $connection),
				'Update issue',
			);
		}
		return $this->getIssue($connection, $refParts);
	}

	public function supportsCreate(): bool {
		return true;
	}

	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array {
		// A project+type context asks only for that combination's field descriptors;
		// skip the (paginated) project listing the initial call needs.
		if ($project !== null && $project !== '') {
			return [
				'projects' => [],
				'capabilities' => ['type' => false, 'typeRequired' => false, 'assignee' => true],
				'fields' => $this->describeFields($connection, $project, $type),
			];
		}
		// Redmine trackers are global (shared by all projects).
		$types = [];
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/trackers.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Trackers',
			);
			foreach ($data['trackers'] ?? [] as $tr) {
				if (is_array($tr)) {
					$types[] = ['id' => (string)($tr['id'] ?? ''), 'name' => (string)($tr['name'] ?? '')];
				}
			}
		} catch (TrackerException $e) {
		}
		$projects = [];
		$offset = 0;
		do {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/projects.json', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['limit' => '100', 'offset' => (string)$offset],
				], $connection),
				'Projects',
			);
			foreach ($data['projects'] ?? [] as $p) {
				if (is_array($p)) {
					$projects[] = ['id' => (string)($p['id'] ?? ''), 'name' => (string)($p['name'] ?? ''), 'types' => $types];
				}
			}
			$total = (int)($data['total_count'] ?? 0);
			$offset += 100;
		} while ($offset < $total && $offset < 500);
		// Redmine's /projects.json has no name-search param, so narrow the list here.
		return ['projects' => $this->filterProjectsByQuery($projects, $query), 'capabilities' => ['type' => $types !== [], 'typeRequired' => $types !== []], 'fields' => []];
	}

	public function createIssue(Connection $connection, array $target): Issue {
		$projectId = (int)$target['project'];
		if ($projectId <= 0) {
			throw new TrackerException('A project is required');
		}
		$issue = [
			'project_id' => $projectId,
			'subject' => (string)$target['title'],
			'description' => (string)($target['description'] ?? ''),
		];
		$trackerId = (int)($target['type'] ?? 0);
		if ($trackerId > 0) {
			$issue['tracker_id'] = $trackerId;
		}
		$assignee = (string)($target['assignee'] ?? '');
		if ($assignee !== '') {
			$issue['assigned_to_id'] = (int)$assignee;
		}
		$this->applyFields($issue, is_array($target['fields'] ?? null) ? $target['fields'] : []);
		$data = $this->json(
			$this->request('POST', $this->base($connection) . '/issues.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['issue' => $issue]),
			], $connection),
			'Create issue',
		);
		return $this->normalizeIssue($connection, is_array($data['issue'] ?? null) ? $data['issue'] : []);
	}

	public function getEditMeta(Connection $connection, array $refParts, ?string $type = null): array {
		$statuses = [];
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/issue_statuses.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Statuses',
			);
			foreach ($data['issue_statuses'] ?? [] as $status) {
				if (is_array($status)) {
					$statuses[] = ['id' => (string)($status['id'] ?? ''), 'name' => (string)($status['name'] ?? '')];
				}
			}
		} catch (TrackerException $e) {
		}
		// Trackers (Redmine's "type") are global and freely changeable on edit.
		$types = $this->optionList($connection, '/trackers.json', 'trackers');
		$assignee = null;
		$fields = [];
		$typeId = '';
		try {
			$id = (string)($refParts['id'] ?? '');
			$issueData = $this->json(
				$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Issue',
			);
			$issue = is_array($issueData['issue'] ?? null) ? $issueData['issue'] : [];
			$projectId = (string)($issue['project']['id'] ?? '');
			$typeId = (string)($issue['tracker']['id'] ?? '');
			if (isset($issue['assigned_to']['id'])) {
				$assignee = ['id' => (string)$issue['assigned_to']['id'], 'name' => (string)($issue['assigned_to']['name'] ?? '')];
			}
			// Describe fields for the prospective type when switching, else the current one.
			$effectiveTracker = ($type !== null && $type !== '') ? $type : $typeId;
			if ($projectId !== '') {
				$inlineCustom = is_array($issue['custom_fields'] ?? null) ? $issue['custom_fields'] : [];
				$fields = $this->describeFields($connection, $projectId, $effectiveTracker, $this->currentFieldValues($issue), $inlineCustom);
			}
		} catch (TrackerException $e) {
		}
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => false, 'labels' => false, 'labelsFreeText' => false, 'type' => $types !== []],
			'statuses' => $statuses,
			'assignee' => $assignee,
			'labels' => [],
			'fields' => $fields,
			'types' => $types,
			'typeId' => $typeId,
		];
	}

	public function searchAssignees(Connection $connection, array $context, string $query): array {
		$projectId = (string)($context['project'] ?? '');
		if ($projectId === '' && isset($context['refParts'])) {
			$projectId = $this->issueProjectId($connection, (string)($context['refParts']['id'] ?? ''));
		}
		if ($projectId === '') {
			return [];
		}
		// Redmine memberships have no server-side search, so filter by name here.
		return $this->filterByName($this->membershipOptions($connection, $projectId), $query);
	}

	/** The project id of an issue, or '' if it can't be resolved. */
	private function issueProjectId(Connection $connection, string $issueId): string {
		if ($issueId === '') {
			return '';
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($issueId) . '.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Issue',
			);
		} catch (TrackerException $e) {
			return '';
		}
		return (string)($data['issue']['project']['id'] ?? '');
	}

	// ---- Dynamic fields ----------------------------------------------------

	/**
	 * Build field descriptors for a project (+ tracker). $current maps descriptor ids
	 * to current values (edit preselect); $inlineCustom is the issue's inline
	 * custom_fields, used to discover custom fields when the token lacks admin.
	 *
	 * @param array<string, mixed> $current
	 * @param list<array<string, mixed>> $inlineCustom
	 * @return list<array<string, mixed>>
	 */
	private function describeFields(Connection $connection, string $projectId, ?string $trackerId = null, array $current = [], array $inlineCustom = []): array {
		if ($projectId === '') {
			return [];
		}
		$fields = [];
		$add = function (string $id, string $name, string $type, array $extra = []) use (&$fields, $current): void {
			if (array_key_exists($id, $current)) {
				$extra['value'] = $current[$id];
			}
			$fields[] = $this->field($id, $name, $type, $extra);
		};

		$priorities = $this->optionList($connection, '/enumerations/issue_priorities.json', 'issue_priorities');
		if ($priorities !== []) {
			$add('priority_id', 'Priority', 'select', ['options' => $priorities]);
		}
		$categories = $this->optionList($connection, '/projects/' . rawurlencode($projectId) . '/issue_categories.json', 'issue_categories');
		if ($categories !== []) {
			$add('category_id', 'Category', 'select', ['options' => $categories]);
		}
		$versions = $this->versionOptions($connection, $projectId);
		if ($versions !== []) {
			$add('fixed_version_id', 'Target version', 'select', ['options' => $versions]);
		}
		$add('start_date', 'Start date', 'date');
		$add('due_date', 'Due date', 'date');
		$add('estimated_hours', 'Estimated hours', 'float');
		$add('done_ratio', '% Done', 'int');
		$add('parent_issue_id', 'Parent issue', 'int');
		$add('is_private', 'Private', 'bool');

		foreach ($this->customFieldDescriptors($connection, $projectId, $trackerId, $inlineCustom) as $cf) {
			$id = (string)$cf['id'];
			if (array_key_exists($id, $current)) {
				$cf['value'] = $current[$id];
			}
			$fields[] = $cf;
		}
		return $fields;
	}

	/**
	 * @return list<array{id: string, name: string}>
	 */
	private function optionList(Connection $connection, string $path, string $key): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . $path, ['headers' => $this->defaultHeaders($connection)], $connection),
				$key,
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data[$key] ?? [] as $row) {
			if (is_array($row)) {
				$out[] = ['id' => (string)($row['id'] ?? ''), 'name' => (string)($row['name'] ?? '')];
			}
		}
		return $out;
	}

	/**
	 * Open project versions as options.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	private function versionOptions(Connection $connection, string $projectId): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/projects/' . rawurlencode($projectId) . '/versions.json', ['headers' => $this->defaultHeaders($connection)], $connection),
				'Versions',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['versions'] ?? [] as $v) {
			if (is_array($v) && (string)($v['status'] ?? 'open') === 'open') {
				$out[] = ['id' => (string)($v['id'] ?? ''), 'name' => (string)($v['name'] ?? '')];
			}
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $inlineCustom
	 * @return list<array<string, mixed>>
	 */
	private function customFieldDescriptors(Connection $connection, string $projectId, ?string $trackerId, array $inlineCustom): array {
		// Preferred: full definitions with formats/possible_values (admin token only).
		$defs = $this->adminCustomFields($connection);
		if ($defs !== []) {
			$enabled = $this->projectCustomFieldIds($connection, $projectId, $inlineCustom);
			$out = [];
			foreach ($defs as $cf) {
				$cfId = (int)($cf['id'] ?? 0);
				if ($cfId <= 0 || (string)($cf['customized_type'] ?? '') !== 'issue') {
					continue;
				}
				if ($enabled !== [] && !in_array($cfId, $enabled, true)) {
					continue;
				}
				if ($trackerId !== null && $trackerId !== '' && !$this->customFieldAppliesToTracker($cf, $trackerId)) {
					continue;
				}
				$out[] = $this->buildCustomDescriptor($connection, $projectId, $cf);
			}
			return $out;
		}
		// Non-admin: only {id, name} is discoverable, so render each custom field as free text.
		$known = $inlineCustom !== [] ? $inlineCustom : $this->projectCustomFields($connection, $projectId);
		$out = [];
		foreach ($known as $cf) {
			$cfId = (int)($cf['id'] ?? 0);
			if ($cfId <= 0) {
				continue;
			}
			$out[] = $this->field('cf_' . $cfId, (string)($cf['name'] ?? ('Field ' . $cfId)), 'text');
		}
		return $out;
	}

	/**
	 * Full custom-field definitions via /custom_fields.json; [] unless the token is admin.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function adminCustomFields(Connection $connection): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/custom_fields.json', ['headers' => $this->defaultHeaders($connection)], $connection),
				'Custom fields',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['custom_fields'] ?? [] as $cf) {
			if (is_array($cf)) {
				$out[] = $cf;
			}
		}
		return $out;
	}

	/**
	 * The issue custom fields enabled for a project (id + name only), via project include.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function projectCustomFields(Connection $connection, string $projectId): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/projects/' . rawurlencode($projectId) . '.json', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['include' => 'issue_custom_fields'],
				], $connection),
				'Project',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['project']['issue_custom_fields'] ?? [] as $cf) {
			if (is_array($cf)) {
				$out[] = $cf;
			}
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $inlineCustom
	 * @return list<int>
	 */
	private function projectCustomFieldIds(Connection $connection, string $projectId, array $inlineCustom): array {
		$source = $inlineCustom !== [] ? $inlineCustom : $this->projectCustomFields($connection, $projectId);
		$ids = [];
		foreach ($source as $cf) {
			if ((int)($cf['id'] ?? 0) > 0) {
				$ids[] = (int)$cf['id'];
			}
		}
		return $ids;
	}

	/**
	 * @param array<string, mixed> $cf
	 */
	private function customFieldAppliesToTracker(array $cf, string $trackerId): bool {
		$trackers = $cf['trackers'] ?? null;
		if (!is_array($trackers) || $trackers === []) {
			return true;
		}
		foreach ($trackers as $tr) {
			if (is_array($tr) && (string)($tr['id'] ?? '') === $trackerId) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $cf
	 * @return array<string, mixed>
	 */
	private function buildCustomDescriptor(Connection $connection, string $projectId, array $cf): array {
		$format = (string)($cf['field_format'] ?? 'string');
		$multiple = (bool)($cf['multiple'] ?? false);
		$type = $this->redmineFieldType($format, $multiple);
		$extra = ['required' => (bool)($cf['is_required'] ?? false)];
		if ($type === 'select' || $type === 'multiselect') {
			if ($format === 'user') {
				$extra['options'] = $this->membershipOptions($connection, $projectId);
			} elseif ($format === 'version') {
				$extra['options'] = $this->versionOptions($connection, $projectId);
			} else {
				$options = [];
				foreach ($cf['possible_values'] ?? [] as $pv) {
					if (is_array($pv)) {
						$value = (string)($pv['value'] ?? '');
						$options[] = ['id' => $value, 'name' => (string)($pv['label'] ?? $value)];
					}
				}
				$extra['options'] = $options;
			}
		}
		return $this->field('cf_' . (int)($cf['id'] ?? 0), (string)($cf['name'] ?? ''), $type, $extra);
	}

	/**
	 * @return list<array{id: string, name: string}>
	 */
	private function membershipOptions(Connection $connection, string $projectId): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/projects/' . rawurlencode($projectId) . '/memberships.json', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['limit' => '100'],
				], $connection),
				'Memberships',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data['memberships'] ?? [] as $m) {
			if (is_array($m) && isset($m['user'])) {
				$out[] = ['id' => (string)($m['user']['id'] ?? ''), 'name' => (string)($m['user']['name'] ?? '')];
			}
		}
		return $out;
	}

	/**
	 * Extract current standard + custom field values from an issue JSON for edit preselect.
	 *
	 * @param array<string, mixed> $issue
	 * @return array<string, mixed>
	 */
	private function currentFieldValues(array $issue): array {
		$current = [];
		foreach (['priority' => 'priority_id', 'category' => 'category_id', 'fixed_version' => 'fixed_version_id'] as $src => $descId) {
			if (isset($issue[$src]['id'])) {
				$current[$descId] = (string)$issue[$src]['id'];
			}
		}
		if (isset($issue['parent']['id'])) {
			$current['parent_issue_id'] = (string)$issue['parent']['id'];
		}
		foreach (['start_date', 'due_date'] as $key) {
			if (isset($issue[$key]) && is_string($issue[$key])) {
				$current[$key] = $issue[$key];
			}
		}
		if (isset($issue['estimated_hours'])) {
			$current['estimated_hours'] = (string)$issue['estimated_hours'];
		}
		if (isset($issue['done_ratio'])) {
			$current['done_ratio'] = (string)$issue['done_ratio'];
		}
		if (isset($issue['is_private'])) {
			$current['is_private'] = (bool)$issue['is_private'];
		}
		foreach ($issue['custom_fields'] ?? [] as $cf) {
			if (is_array($cf) && isset($cf['id'])) {
				$current['cf_' . (int)$cf['id']] = $cf['value'] ?? '';
			}
		}
		return $current;
	}

	/**
	 * Fold dynamic field values into the Redmine issue payload.
	 *
	 * @param array<string, mixed> $issue
	 * @param array<string, mixed> $fields
	 */
	private function applyFields(array &$issue, array $fields): void {
		$custom = [];
		foreach ($fields as $id => $value) {
			$id = (string)$id;
			if (str_starts_with($id, 'cf_')) {
				$cfId = (int)substr($id, 3);
				if ($cfId > 0) {
					$custom[] = ['id' => $cfId, 'value' => is_array($value) ? array_map('strval', $value) : (string)$value];
				}
				continue;
			}
			switch ($id) {
				case 'priority_id':
				case 'category_id':
				case 'fixed_version_id':
				case 'parent_issue_id':
				case 'done_ratio':
					if ($value !== '' && $value !== null) {
						$issue[$id] = (int)$value;
					}
					break;
				case 'estimated_hours':
					if ($value !== '' && $value !== null) {
						$issue[$id] = (float)$value;
					}
					break;
				case 'start_date':
				case 'due_date':
					if ($value !== '' && $value !== null) {
						$issue[$id] = substr((string)$value, 0, 10);
					}
					break;
				case 'is_private':
					$issue[$id] = (bool)$value;
					break;
			}
		}
		if ($custom !== []) {
			$issue['custom_fields'] = $custom;
		}
	}

	private function redmineFieldType(string $format, bool $multiple): string {
		switch ($format) {
			case 'text':
				return 'textarea';
			case 'int':
				return 'int';
			case 'float':
				return 'float';
			case 'date':
				return 'date';
			case 'bool':
				return 'bool';
			case 'list':
			case 'enumeration':
			case 'user':
			case 'version':
				return $multiple ? 'multiselect' : 'select';
			default:
				return 'text';
		}
	}

	public function getTimeRecords(Connection $connection, array $refParts): array {
		$id = (string)($refParts['id'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->base($connection) . '/time_entries.json', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['issue_id' => $id, 'limit' => '100'],
			], $connection),
			'Get time entries',
		);
		$currentUserId = $this->currentUserId($connection);
		$records = [];
		foreach (($data['time_entries'] ?? []) as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$hours = (float)($raw['hours'] ?? 0);
			// Only the author may edit/delete their own entry.
			$own = $currentUserId !== '' && (string)($raw['user']['id'] ?? '') === $currentUserId;
			$records[] = new TimeRecord(
				(string)($raw['id'] ?? ''),
				(string)($raw['user']['name'] ?? ''),
				(int)round($hours * 3600),
				$raw['spent_on'] ?? null,
				(string)($raw['comments'] ?? ''),
				editable: $own,
				deletable: $own,
				createdAt: $raw['created_on'] ?? null,
			);
		}
		return $records;
	}

	/** The connection user's own Redmine user id, or '' if it can't be resolved. */
	private function currentUserId(Connection $connection): string {
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/users/current.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Get current user',
			);
			return (string)($data['user']['id'] ?? '');
		} catch (TrackerException $e) {
			return '';
		}
	}

	public function updateTime(Connection $connection, array $refParts, string $recordId, int $seconds, string $comment, ?string $startedAt): void {
		$entry = [
			'hours' => round($seconds / 3600, 2),
			'comments' => $comment,
		];
		if ($startedAt !== null && $startedAt !== '') {
			$entry['spent_on'] = substr($startedAt, 0, 10);
		}
		$this->json(
			$this->request('PUT', $this->base($connection) . '/time_entries/' . rawurlencode($recordId) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['time_entry' => $entry]),
			], $connection),
			'Update time',
		);
	}

	public function deleteTime(Connection $connection, array $refParts, string $recordId): void {
		$this->json(
			$this->request('DELETE', $this->base($connection) . '/time_entries/' . rawurlencode($recordId) . '.json', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete time',
		);
	}

	public function supportsAttachments(): bool {
		return true;
	}

	/**
	 * @param array $refParts
	 * @return Attachment[]
	 */
	public function getAttachments(Connection $connection, array $refParts): array {
		$id = (string)($refParts['id'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['include' => 'attachments'],
			], $connection),
			'Get attachments',
		);
		$attachments = [];
		foreach (($data['issue']['attachments'] ?? []) as $raw) {
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
		$thumb = $raw['thumbnail_url'] ?? null;
		return new Attachment(
			(string)($raw['id'] ?? ''),
			(string)($raw['filename'] ?? ''),
			(string)($raw['content_type'] ?? 'application/octet-stream'),
			(int)($raw['filesize'] ?? 0),
			(string)($raw['content_url'] ?? ''),
			is_string($thumb) && $thumb !== '' ? $thumb : null,
			(string)($raw['author']['name'] ?? ''),
			$raw['created_on'] ?? null,
		);
	}

	public function uploadAttachment(Connection $connection, array $refParts, string $filename, string $mimeType, string $content): Attachment {
		$id = (string)($refParts['id'] ?? '');
		$type = $mimeType !== '' ? $mimeType : 'application/octet-stream';
		// Step 1: upload the raw bytes to obtain a token.
		$upload = $this->json(
			$this->request('POST', $this->base($connection) . '/uploads.json?filename=' . rawurlencode($filename), [
				'headers' => ['X-Redmine-API-Key' => $connection->token, 'Content-Type' => 'application/octet-stream'],
				'body' => $content,
			], $connection),
			'Upload file',
		);
		$token = (string)($upload['upload']['token'] ?? '');
		if ($token === '') {
			throw new TrackerException('Upload failed: no token returned');
		}
		// Step 2: attach the token to the issue.
		$this->json(
			$this->request('PUT', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['issue' => ['uploads' => [[
					'token' => $token,
					'filename' => $filename,
					'content_type' => $type,
				]]]]),
			], $connection),
			'Attach file',
		);
		// Redmine's PUT returns no body; return the freshly-created attachment.
		foreach (array_reverse($this->getAttachments($connection, $refParts)) as $att) {
			if ($att->filename === $filename) {
				return $att;
			}
		}
		return new Attachment('', $filename, $type, strlen($content), '');
	}

	public function deleteAttachment(Connection $connection, array $refParts, string $attachmentId): void {
		$this->json(
			$this->request('DELETE', $this->base($connection) . '/attachments/' . rawurlencode($attachmentId) . '.json', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete attachment',
		);
	}

	// ---- Relations ---------------------------------------------------------

	/**
	 * Redmine's stored relation_type is always the "forward" form; these map it to
	 * a human label from the source side [0] and the target side [1].
	 */
	private const RELATION_LABELS = [
		'relates' => ['Related to', 'Related to'],
		'duplicates' => ['Duplicates', 'Duplicated by'],
		'blocks' => ['Blocks', 'Blocked by'],
		'precedes' => ['Precedes', 'Follows'],
		'copied_to' => ['Copied to', 'Copied from'],
	];

	/** Forward relation_type => its reverse-side key (for the normalized Relation::$type). */
	private const RELATION_REVERSE = [
		'relates' => 'relates',
		'duplicates' => 'duplicated',
		'blocks' => 'blocked',
		'precedes' => 'follows',
		'copied_to' => 'copied_from',
	];

	/** At most this many relations get a target lookup (bounds the N+1). */
	private const RELATION_LOOKUP_CAP = 25;

	public function supportsRelations(): bool {
		return true;
	}

	/**
	 * Every direction is offered: Redmine accepts the reverse relation_type on
	 * POST and normalizes it (swapping source/target), so "Blocked by" etc. work
	 * from the current issue's endpoint. Parent/child is intentionally excluded —
	 * it stays in the parent_issue_id dynamic field (getEditMeta), and the
	 * /relations endpoint never carries it, so there is no overlap.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public function getRelationTypes(Connection $connection, array $refParts): array {
		return [
			['id' => 'relates', 'name' => 'Related to'],
			['id' => 'blocks', 'name' => 'Blocks'],
			['id' => 'blocked', 'name' => 'Blocked by'],
			['id' => 'precedes', 'name' => 'Precedes'],
			['id' => 'follows', 'name' => 'Follows'],
			['id' => 'duplicates', 'name' => 'Duplicates'],
			['id' => 'duplicated', 'name' => 'Duplicated by'],
			['id' => 'copied_to', 'name' => 'Copied to'],
			['id' => 'copied_from', 'name' => 'Copied from'],
		];
	}

	/**
	 * @param array $refParts
	 * @return \OCA\Unity\Model\Relation[]
	 */
	public function getRelations(Connection $connection, array $refParts): array {
		$id = (string)($refParts['id'] ?? '');
		$data = $this->json(
			$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['include' => 'relations'],
			], $connection),
			'Get relations',
		);
		$relations = [];
		$raw = $data['issue']['relations'] ?? [];
		if (!is_array($raw)) {
			return [];
		}
		foreach (array_slice($raw, 0, self::RELATION_LOOKUP_CAP) as $rel) {
			if (is_array($rel)) {
				$relations[] = $this->buildRelation($connection, $id, $rel);
			}
		}
		return $relations;
	}

	/**
	 * @param array $refParts
	 * @param array $targetParts
	 */
	public function addRelation(Connection $connection, array $refParts, string $type, array $targetParts): \OCA\Unity\Model\Relation {
		$id = (string)($refParts['id'] ?? '');
		$targetId = (int)($targetParts['id'] ?? 0);
		if ($targetId <= 0) {
			throw new TrackerException('Invalid target issue');
		}
		$data = $this->json(
			$this->request('POST', $this->base($connection) . '/issues/' . rawurlencode($id) . '/relations.json', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['relation' => ['issue_to_id' => $targetId, 'relation_type' => $type]]),
			], $connection),
			'Add relation',
		);
		$rel = is_array($data['relation'] ?? null) ? $data['relation'] : [];
		return $this->buildRelation($connection, $id, $rel);
	}

	/**
	 * @param array $refParts
	 */
	public function deleteRelation(Connection $connection, array $refParts, string $relationId): void {
		$this->json(
			$this->request('DELETE', $this->base($connection) . '/relations/' . rawurlencode($relationId) . '.json', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Delete relation',
		);
	}

	/**
	 * Orient a raw Redmine relation relative to the current issue, resolve the
	 * target's subject/status, and build a normalized Relation.
	 *
	 * @param array<string, mixed> $rel
	 */
	private function buildRelation(Connection $connection, string $currentId, array $rel): \OCA\Unity\Model\Relation {
		$relType = (string)($rel['relation_type'] ?? 'relates');
		$labels = self::RELATION_LABELS[$relType] ?? ['Related to', 'Related to'];
		$fromSource = (string)($rel['issue_id'] ?? '') === $currentId;
		$targetId = $fromSource ? (string)($rel['issue_to_id'] ?? '') : (string)($rel['issue_id'] ?? '');
		$typeLabel = $fromSource ? $labels[0] : $labels[1];
		$typeKey = $fromSource ? $relType : (self::RELATION_REVERSE[$relType] ?? $relType);
		[$subject, $status] = $this->relationTargetInfo($connection, $targetId);
		return new \OCA\Unity\Model\Relation(
			(string)($rel['id'] ?? ''),
			$typeKey,
			$typeLabel,
			Ref::encode('redmine', $connection->id, ['id' => $targetId]),
			'#' . $targetId,
			$subject,
			$status,
			$this->base($connection) . '/issues/' . $targetId,
		);
	}

	/**
	 * Fetch a related issue's subject + status name (best effort).
	 *
	 * @return array{0: string, 1: string} [subject, statusName]
	 */
	private function relationTargetInfo(Connection $connection, string $targetId): array {
		if ($targetId === '') {
			return ['', ''];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($targetId) . '.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Get related issue',
			);
			$issue = is_array($data['issue'] ?? null) ? $data['issue'] : [];
			return [(string)($issue['subject'] ?? ''), (string)($issue['status']['name'] ?? '')];
		} catch (TrackerException $e) {
			return ['', ''];
		}
	}

	protected function fileHeaders(Connection $connection): array {
		return ['X-Redmine-API-Key' => $connection->token];
	}

	protected function resolveFileUrl(Connection $connection, array $refParts, string $src): string {
		$src = trim($src);
		if ($src === '') {
			throw new TrackerException('Empty file source');
		}
		if (preg_match('#^https?://#i', $src) !== 1) {
			// Relative Redmine attachment, e.g. /attachments/download/{id}/{file}.
			return $this->base($connection) . '/' . ltrim($src, '/');
		}
		$host = strtolower((string)parse_url($src, PHP_URL_HOST));
		$baseHost = strtolower((string)parse_url($connection->baseUrl, PHP_URL_HOST));
		if ($host === '' || $host !== $baseHost) {
			throw new TrackerException('File host not allowed');
		}
		return $src;
	}

	/**
	 * Detect a "#id" reference (optionally after a slash-free project name);
	 * Redmine issue ids are global, so this jumps straight to that issue.
	 *
	 * @return array{id: string}|null
	 */
	private function parseReference(string $term): ?array {
		if (preg_match('/^[^\/]*#(\d+)\s*$/', trim($term), $m) === 1) {
			return ['id' => $m[1]];
		}
		return null;
	}

	private function sort(IssueQuery $query): string {
		$field = match ($query->sort) {
			'created' => 'created_on',
			'title' => 'subject',
			'status' => 'status',
			default => 'updated_on',
		};
		$direction = strtolower($query->order) === 'asc' ? 'asc' : 'desc';
		return $field . ':' . $direction;
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeIssue(Connection $connection, array $raw): Issue {
		$id = (string)($raw['id'] ?? '');
		$labels = [];
		if (isset($raw['category']['name'])) {
			$labels[] = (string)$raw['category']['name'];
		}
		$spentHours = $raw['spent_hours'] ?? null;
		$format = ($connection->settings['textFormat'] ?? 'textile') === 'markdown' ? 'markdown' : 'textile';

		return new Issue(
			Ref::encode('redmine', $connection->id, ['id' => $id]),
			'redmine',
			$connection->id,
			$connection->label,
			'#' . $id,
			(string)($raw['subject'] ?? ''),
			(string)($raw['description'] ?? ''),
			(string)($raw['status']['name'] ?? ''),
			(string)($raw['author']['name'] ?? ''),
			(string)($raw['assigned_to']['name'] ?? ''),
			$labels,
			(string)($raw['project']['name'] ?? ''),
			$raw['created_on'] ?? null,
			$raw['updated_on'] ?? null,
			$this->base($connection) . '/issues/' . $id,
			is_numeric($spentHours) && $spentHours > 0 ? (int)round((float)$spentHours * 3600) : null,
			$format,
		);
	}
}
