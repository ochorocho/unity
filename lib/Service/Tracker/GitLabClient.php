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
		return $this->normalizeIssue($connection, $data);
	}

	public function getComments(Connection $connection, array $refParts): array {
		$data = $this->json(
			$this->request('GET', $this->issueUrl($connection, $refParts) . '/notes', [
				'headers' => $this->defaultHeaders($connection),
				'query' => ['sort' => 'asc', 'order_by' => 'created_at'],
			], $connection),
			'Get comments',
		);
		$comments = [];
		foreach ($data as $raw) {
			if (!is_array($raw) || ($raw['system'] ?? false) === true) {
				continue;
			}
			$comments[] = new Comment(
				(string)($raw['id'] ?? ''),
				(string)($raw['author']['name'] ?? $raw['author']['username'] ?? ''),
				$raw['author']['avatar_url'] ?? null,
				(string)($raw['body'] ?? ''),
				$raw['created_at'] ?? null,
			);
		}
		return $comments;
	}

	public function addComment(Connection $connection, array $refParts, string $body): Comment {
		$raw = $this->json(
			$this->request('POST', $this->issueUrl($connection, $refParts) . '/notes', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $body]),
			], $connection),
			'Add comment',
		);
		return new Comment(
			(string)($raw['id'] ?? ''),
			(string)($raw['author']['name'] ?? ''),
			$raw['author']['avatar_url'] ?? null,
			(string)($raw['body'] ?? $body),
			$raw['created_at'] ?? null,
		);
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
		$raw = $this->json(
			$this->request('PUT', $this->issueUrl($connection, $refParts) . '/notes/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $body]),
			], $connection),
			'Update comment',
		);
		return new Comment(
			(string)($raw['id'] ?? $commentId),
			(string)($raw['author']['name'] ?? ''),
			$raw['author']['avatar_url'] ?? null,
			(string)($raw['body'] ?? $body),
			$raw['created_at'] ?? null,
		);
	}

	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue {
		$body = [];
		if (array_key_exists('title', $changes)) {
			$body['title'] = (string)$changes['title'];
		}
		if (array_key_exists('description', $changes)) {
			$body['description'] = (string)$changes['description'];
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
		$data = $this->json(
			$this->request('PUT', $this->issueUrl($connection, $refParts), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($body),
			], $connection),
			'Update issue',
		);
		return $this->normalizeIssue($connection, $data);
	}

	public function getEditMeta(Connection $connection, array $refParts): array {
		$project = rawurlencode((string)($refParts['project'] ?? ''));
		$assignees = [];
		try {
			$members = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/projects/' . $project . '/members/all', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['per_page' => '50'],
				], $connection),
				'Members',
			);
			foreach ($members as $member) {
				if (is_array($member)) {
					$assignees[] = ['id' => (string)($member['id'] ?? ''), 'name' => (string)($member['name'] ?? $member['username'] ?? '')];
				}
			}
		} catch (TrackerException $e) {
		}
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
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => true, 'labels' => true, 'labelsFreeText' => false],
			'statuses' => [['id' => 'opened', 'name' => 'Open'], ['id' => 'closed', 'name' => 'Closed']],
			'assignees' => $assignees,
			'labels' => $labels,
		];
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
