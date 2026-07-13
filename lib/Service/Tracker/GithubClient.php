<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use OCA\Unity\Model\Comment;
use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Model\TrackerSearchResult;

/**
 * GitHub REST API client. Auth via Bearer token. Uses the /search/issues
 * endpoint (stricter 30 req/min limit — results are cached upstream); pull
 * requests are filtered out. GitHub has no time-tracking API. Text is Markdown.
 */
class GithubClient extends AbstractTrackerClient {

	public function getTrackerId(): string {
		return 'github';
	}

	public function supportsTimeTracking(): bool {
		return false;
	}

	private function apiRoot(Connection $connection): string {
		$base = $connection->baseUrl !== '' ? rtrim($connection->baseUrl, '/') : 'https://api.github.com';
		return $base;
	}

	protected function authHeaders(Connection $connection): array {
		return [
			'Authorization' => 'Bearer ' . $connection->token,
			'X-GitHub-Api-Version' => '2022-11-28',
			'Accept' => 'application/vnd.github+json',
		];
	}

	public function testConnection(Connection $connection): array {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/user', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Authentication',
			);
			return ['ok' => true, 'message' => 'Connected', 'user' => (string)($data['login'] ?? '')];
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
					// not a resolvable owner/repo/number here — fall through to search
				}
			}
		}
		$params = [
			'q' => $this->buildQuery($connection, $query),
			'per_page' => (string)$query->limit,
		];
		$sort = $this->sortField($query->sort);
		if ($sort !== '') {
			$params['sort'] = $sort;
			$params['order'] = strtolower($query->order) === 'asc' ? 'asc' : 'desc';
		}
		if ($cursor !== null && $cursor !== '') {
			$params['page'] = $cursor;
		}
		$response = $this->request('GET', $this->apiRoot($connection) . '/search/issues', [
			'headers' => $this->defaultHeaders($connection),
			'query' => $params,
		], $connection);
		$data = $this->json($response, 'Search');

		$issues = [];
		foreach (($data['items'] ?? []) as $raw) {
			if (!is_array($raw) || isset($raw['pull_request'])) {
				continue;
			}
			$issues[] = $this->normalizeIssue($connection, $raw);
		}
		$next = $this->nextPageFromLink($response->getHeader('Link'));
		return new TrackerSearchResult($issues, $next);
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
			$this->request('GET', $this->issueUrl($connection, $refParts) . '/comments', [
				'headers' => $this->defaultHeaders($connection),
			], $connection),
			'Get comments',
		);
		$currentLogin = $this->currentUserLogin($connection);
		$comments = [];
		foreach ($data as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$login = (string)($raw['user']['login'] ?? '');
			// Only the comment author may edit/delete it (GitHub logins are case-insensitive).
			$own = $currentLogin !== '' && strcasecmp($login, $currentLogin) === 0;
			$comments[] = new Comment(
				(string)($raw['id'] ?? ''),
				$login,
				$raw['user']['avatar_url'] ?? null,
				(string)($raw['body'] ?? ''),
				$raw['created_at'] ?? null,
				$raw['html_url'] ?? null,
				editable: $own,
				deletable: $own,
			);
		}
		return $comments;
	}

	/** The connection user's own GitHub login, or '' if it can't be resolved. */
	private function currentUserLogin(Connection $connection): string {
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/user', [
					'headers' => $this->defaultHeaders($connection),
				], $connection),
				'Get current user',
			);
			return (string)($data['login'] ?? '');
		} catch (TrackerException $e) {
			return '';
		}
	}

	public function addComment(Connection $connection, array $refParts, string $body): Comment {
		$raw = $this->json(
			$this->request('POST', $this->issueUrl($connection, $refParts) . '/comments', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $body]),
			], $connection),
			'Add comment',
		);
		return new Comment(
			(string)($raw['id'] ?? ''),
			(string)($raw['user']['login'] ?? ''),
			$raw['user']['avatar_url'] ?? null,
			(string)($raw['body'] ?? $body),
			$raw['created_at'] ?? null,
			$raw['html_url'] ?? null,
		);
	}

	public function logTime(Connection $connection, array $refParts, int $seconds, string $comment, ?string $startedAt): void {
		throw new TrackerException('GitHub does not support time tracking');
	}

	public function updateComment(Connection $connection, array $refParts, string $commentId, string $body): Comment {
		$owner = rawurlencode((string)($refParts['owner'] ?? ''));
		$repo = rawurlencode((string)($refParts['repo'] ?? ''));
		$raw = $this->json(
			$this->request('PATCH', $this->apiRoot($connection) . '/repos/' . $owner . '/' . $repo . '/issues/comments/' . rawurlencode($commentId), [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode(['body' => $body]),
			], $connection),
			'Update comment',
		);
		return new Comment(
			(string)($raw['id'] ?? $commentId),
			(string)($raw['user']['login'] ?? ''),
			$raw['user']['avatar_url'] ?? null,
			(string)($raw['body'] ?? $body),
			$raw['created_at'] ?? null,
			$raw['html_url'] ?? null,
		);
	}

	public function deleteComment(Connection $connection, array $refParts, string $commentId): void {
		$owner = rawurlencode((string)($refParts['owner'] ?? ''));
		$repo = rawurlencode((string)($refParts['repo'] ?? ''));
		$this->json(
			$this->request('DELETE', $this->apiRoot($connection) . '/repos/' . $owner . '/' . $repo . '/issues/comments/' . rawurlencode($commentId), [
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
			$body['body'] = (string)$changes['description'];
		}
		if (array_key_exists('status', $changes)) {
			$status = (string)$changes['status'];
			if ($status === 'open' || $status === 'closed') {
				$body['state'] = $status;
			}
		}
		if (array_key_exists('assignee', $changes)) {
			$assignee = (string)$changes['assignee'];
			$body['assignees'] = $assignee === '' ? [] : [$assignee];
		}
		if (array_key_exists('labels', $changes) && is_array($changes['labels'])) {
			$body['labels'] = array_values(array_map('strval', $changes['labels']));
		}
		if (isset($changes['fields']) && is_array($changes['fields'])) {
			$this->applyFields($body, $changes['fields']);
		}
		$data = $this->json(
			$this->request('PATCH', $this->issueUrl($connection, $refParts), [
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

	/** Hard cap on /user/repos pages walked during a search (100 repos each). */
	private const REPO_SEARCH_MAX_PAGES = 10;

	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array {
		// A repository context asks only for that repo's field descriptors.
		if ($project !== null && str_contains($project, '/')) {
			[$owner, $repo] = explode('/', $project, 2);
			return [
				'projects' => [],
				'capabilities' => ['type' => false, 'typeRequired' => false],
				'fields' => $this->describeFields($connection, $owner, $repo),
			];
		}
		$hasQuery = $query !== null && trim($query) !== '';
		// /user/repos returns the 100 most-recently-updated repos per page and has no
		// text-search param. Without a term the first page is plenty for the dropdown;
		// with a term we page through the full list so a match outside the recent 100
		// (e.g. an older repo) is still found, then filter locally.
		$maxPages = $hasQuery ? self::REPO_SEARCH_MAX_PAGES : 1;
		$projects = [];
		$page = '1';
		$pagesWalked = 0;
		do {
			$response = $this->request('GET', $this->apiRoot($connection) . '/user/repos', [
				'headers' => $this->defaultHeaders($connection),
				'query' => [
					'per_page' => '100',
					'page' => $page,
					'sort' => 'updated',
					'affiliation' => 'owner,collaborator,organization_member',
				],
			], $connection);
			$data = $this->json($response, 'Repositories');
			foreach ($data as $raw) {
				if (!is_array($raw)) {
					continue;
				}
				// Only repos that have issues enabled and where the user can write.
				if (($raw['has_issues'] ?? true) !== true || ($raw['permissions']['push'] ?? true) !== true) {
					continue;
				}
				$full = (string)($raw['full_name'] ?? '');
				if ($full !== '') {
					$projects[] = ['id' => $full, 'name' => $full, 'types' => []];
				}
			}
			$pagesWalked++;
			$page = $this->nextPageFromLink($response->getHeader('Link')) ?? '';
		} while ($page !== '' && $pagesWalked < $maxPages);
		return ['projects' => $this->filterProjectsByQuery($projects, $query), 'capabilities' => ['type' => false, 'typeRequired' => false], 'fields' => []];
	}

	public function createIssue(Connection $connection, array $target): Issue {
		$full = (string)$target['project'];
		if (!str_contains($full, '/')) {
			throw new TrackerException('A repository is required');
		}
		[$owner, $repo] = explode('/', $full, 2);
		$body = [
			'title' => (string)$target['title'],
			'body' => (string)($target['description'] ?? ''),
		];
		$this->applyFields($body, is_array($target['fields'] ?? null) ? $target['fields'] : []);
		$data = $this->json(
			$this->request('POST', $this->apiRoot($connection) . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/issues', [
				'headers' => $this->defaultHeaders($connection),
				'body' => json_encode($body),
			], $connection),
			'Create issue',
		);
		return $this->normalizeIssue($connection, $data);
	}

	public function getEditMeta(Connection $connection, array $refParts, ?string $type = null): array {
		$owner = rawurlencode((string)($refParts['owner'] ?? ''));
		$repo = rawurlencode((string)($refParts['repo'] ?? ''));
		$repoBase = $this->apiRoot($connection) . '/repos/' . $owner . '/' . $repo;
		$assignees = [];
		try {
			$users = $this->json(
				$this->request('GET', $repoBase . '/assignees', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['per_page' => '50'],
				], $connection),
				'Assignees',
			);
			foreach ($users as $user) {
				if (is_array($user) && isset($user['login'])) {
					$assignees[] = ['id' => (string)$user['login'], 'name' => (string)$user['login']];
				}
			}
		} catch (TrackerException $e) {
		}
		$labels = [];
		try {
			$found = $this->json(
				$this->request('GET', $repoBase . '/labels', [
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
		$fields = [];
		try {
			$owner = (string)($refParts['owner'] ?? '');
			$repo = (string)($refParts['repo'] ?? '');
			$current = [];
			$issue = $this->json(
				$this->request('GET', $this->issueUrl($connection, $refParts), ['headers' => $this->defaultHeaders($connection)], $connection),
				'Issue',
			);
			if (isset($issue['milestone']['number'])) {
				$current['milestone'] = (string)$issue['milestone']['number'];
			}
			$fields = $this->describeFields($connection, $owner, $repo, $current);
		} catch (TrackerException $e) {
		}
		return [
			'capabilities' => ['status' => true, 'assignee' => true, 'assigneeMulti' => true, 'labels' => true, 'labelsFreeText' => false],
			'statuses' => [['id' => 'open', 'name' => 'Open'], ['id' => 'closed', 'name' => 'Closed']],
			'assignees' => $assignees,
			'labels' => $labels,
			'fields' => $fields,
		];
	}

	// ---- Dynamic fields ----------------------------------------------------

	/**
	 * @param array<string, mixed> $current
	 * @return list<array<string, mixed>>
	 */
	private function describeFields(Connection $connection, string $owner, string $repo, array $current = []): array {
		$milestones = $this->milestoneOptions($connection, $owner, $repo);
		if ($milestones === []) {
			return [];
		}
		$extra = ['options' => $milestones];
		if (array_key_exists('milestone', $current)) {
			$extra['value'] = $current['milestone'];
		}
		return [$this->field('milestone', 'Milestone', 'select', $extra)];
	}

	/**
	 * @return list<array{id: string, name: string}>
	 */
	private function milestoneOptions(Connection $connection, string $owner, string $repo): array {
		if ($owner === '' || $repo === '') {
			return [];
		}
		try {
			$data = $this->json(
				$this->request('GET', $this->apiRoot($connection) . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/milestones', [
					'headers' => $this->defaultHeaders($connection),
					'query' => ['state' => 'open', 'per_page' => '100'],
				], $connection),
				'Milestones',
			);
		} catch (TrackerException $e) {
			return [];
		}
		$out = [];
		foreach ($data as $m) {
			if (is_array($m) && isset($m['number'])) {
				$out[] = ['id' => (string)$m['number'], 'name' => (string)($m['title'] ?? $m['number'])];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $body issue payload (mutated)
	 * @param array<string, mixed> $fields
	 */
	private function applyFields(array &$body, array $fields): void {
		if (array_key_exists('milestone', $fields)) {
			$value = $fields['milestone'];
			if ($value !== '' && $value !== null) {
				$body['milestone'] = (int)$value;
			}
		}
	}

	protected function fileHeaders(Connection $connection): array {
		// Drop the JSON Accept so asset hosts return the raw image bytes.
		return ['Authorization' => 'Bearer ' . $connection->token];
	}

	protected function resolveFileUrl(Connection $connection, array $refParts, string $src): string {
		$src = trim($src);
		if ($src === '' || preg_match('#^https?://#i', $src) !== 1) {
			throw new TrackerException('Unsupported file source');
		}
		$host = strtolower((string)parse_url($src, PHP_URL_HOST));
		$baseHost = strtolower((string)parse_url($connection->baseUrl, PHP_URL_HOST));
		$allowed = $host === 'github.com'
			|| ($baseHost !== '' && $host === $baseHost)
			|| str_ends_with($host, '.githubusercontent.com')
			|| str_ends_with($host, '.github.com');
		if (!$allowed) {
			throw new TrackerException('File host not allowed');
		}
		return $src;
	}

	/**
	 * Detect an "owner/repo #number" reference (the detail-link text) so it
	 * jumps straight to that issue.
	 *
	 * @return array{owner: string, repo: string, number: string}|null
	 */
	private function parseReference(string $term): ?array {
		if (preg_match('~^([^/\s]+/[^/\s]+?)\s*#?(\d+)$~', trim($term), $m) === 1) {
			[$owner, $repo] = explode('/', $m[1], 2);
			return ['owner' => $owner, 'repo' => $repo, 'number' => $m[2]];
		}
		return null;
	}

	private function issueUrl(Connection $connection, array $refParts): string {
		$owner = rawurlencode((string)($refParts['owner'] ?? ''));
		$repo = rawurlencode((string)($refParts['repo'] ?? ''));
		$number = rawurlencode((string)($refParts['number'] ?? ''));
		return $this->apiRoot($connection) . '/repos/' . $owner . '/' . $repo . '/issues/' . $number;
	}

	private function buildQuery(Connection $connection, IssueQuery $query): string {
		$parts = [];
		$term = trim($query->term);
		if ($term !== '') {
			$parts[] = $term;
		}
		$parts[] = 'is:issue';
		if (!$query->showClosed) {
			$parts[] = 'state:open';
		}
		$repo = (string)($connection->settings['repo'] ?? '');
		if ($repo !== '') {
			$parts[] = 'repo:' . $repo;
		}
		if ($query->assignedToMe) {
			$parts[] = 'assignee:@me';
		}
		// /search/issues needs at least one non-qualifier term or a scoping
		// qualifier; fall back to "issues created by me" when nothing else set.
		if ($term === '' && $repo === '' && !$query->assignedToMe) {
			$parts[] = 'author:@me';
		}
		return implode(' ', $parts);
	}

	private function sortField(string $sort): string {
		return match ($sort) {
			'created' => 'created',
			'updated' => 'updated',
			default => '',
		};
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function normalizeIssue(Connection $connection, array $raw): Issue {
		$number = (string)($raw['number'] ?? '');
		[$owner, $repo] = $this->ownerRepo($raw);
		$labels = [];
		foreach (($raw['labels'] ?? []) as $label) {
			if (is_array($label) && isset($label['name'])) {
				$labels[] = (string)$label['name'];
			} elseif (is_string($label)) {
				$labels[] = $label;
			}
		}
		$assignee = (string)($raw['assignee']['login'] ?? ($raw['assignees'][0]['login'] ?? ''));

		return new Issue(
			Ref::encode('github', $connection->id, ['owner' => $owner, 'repo' => $repo, 'number' => $number]),
			'github',
			$connection->id,
			$connection->label,
			'#' . $number,
			(string)($raw['title'] ?? ''),
			(string)($raw['body'] ?? ''),
			(string)($raw['state'] ?? ''),
			(string)($raw['user']['login'] ?? ''),
			$assignee,
			$labels,
			$owner !== '' ? $owner . '/' . $repo : '',
			$raw['created_at'] ?? null,
			$raw['updated_at'] ?? null,
			(string)($raw['html_url'] ?? ''),
			null,
			'markdown',
		);
	}

	/**
	 * @param array<mixed> $raw
	 * @return array{0: string, 1: string}
	 */
	private function ownerRepo(array $raw): array {
		// Prefer repository_url (https://api.github.com/repos/owner/repo).
		$url = (string)($raw['repository_url'] ?? '');
		if ($url !== '' && preg_match('#/repos/([^/]+)/([^/]+)$#', $url, $m) === 1) {
			return [$m[1], $m[2]];
		}
		// Fall back to html_url (https://github.com/owner/repo/issues/1).
		$html = (string)($raw['html_url'] ?? '');
		if ($html !== '' && preg_match('#github[^/]*/([^/]+)/([^/]+)/issues/#', $html, $m) === 1) {
			return [$m[1], $m[2]];
		}
		return ['', ''];
	}
}
