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
		$comments = [];
		foreach (($data['issue']['journals'] ?? []) as $journal) {
			if (!is_array($journal)) {
				continue;
			}
			$notes = (string)($journal['notes'] ?? '');
			if (trim($notes) === '') {
				continue;
			}
			$comments[] = new Comment(
				(string)($journal['id'] ?? ''),
				(string)($journal['user']['name'] ?? ''),
				null,
				$notes,
				$journal['created_on'] ?? null,
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

	public function getEditMeta(Connection $connection, array $refParts): array {
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
		$assignees = [];
		try {
			$id = (string)($refParts['id'] ?? '');
			$issueData = $this->json(
				$this->request('GET', $this->base($connection) . '/issues/' . rawurlencode($id) . '.json', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Issue',
			);
			$projectId = (string)($issueData['issue']['project']['id'] ?? '');
			if ($projectId !== '') {
				$memberships = $this->json(
					$this->request('GET', $this->base($connection) . '/projects/' . rawurlencode($projectId) . '/memberships.json', [
						'headers' => $this->defaultHeaders($connection),
						'query' => ['limit' => '100'],
					], $connection),
					'Memberships',
				);
				foreach ($memberships['memberships'] ?? [] as $membership) {
					if (is_array($membership) && isset($membership['user'])) {
						$assignees[] = ['id' => (string)($membership['user']['id'] ?? ''), 'name' => (string)($membership['user']['name'] ?? '')];
					}
				}
			}
		} catch (TrackerException $e) {
		}
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => false, 'labels' => false, 'labelsFreeText' => false],
			'statuses' => $statuses,
			'assignees' => $assignees,
			'labels' => [],
		];
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
