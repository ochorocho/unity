<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Comment;
use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Model\TrackerSearchResult;
use OCA\Unity\Service\Tracker\TrackerClientInterface;
use OCA\Unity\Service\Tracker\TrackerException;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Fan-out manager: runs a query across the user's connections, merges the
 * results, sorts them server-side, and reports per-connection failures as
 * partial errors so one failing tracker never breaks the whole list.
 *
 * Each per-connection result is cached in the distributed cache for ~2 minutes.
 * The cache key includes the full query (term, sort, order, cursor, …) plus a
 * per-user generation counter, so connection changes invalidate instantly while
 * repeated identical queries are served from cache.
 */
class IssueService {

	private const TTL = 120;

	private ICache $cache;

	public function __construct(
		private ConnectionService $connections,
		private TrackerManager $trackers,
		ICacheFactory $cacheFactory,
		private SyncStateService $syncState,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/**
	 * @param string[] $connectionIds restrict to these connections (empty = all)
	 * @param array<string, string> $cursors per-connection continuation cursors
	 * @return array{issues: Issue[], errors: list<array{connectionId: string, label: string, message: string}>, nextCursors: array<string, string>}
	 */
	public function search(string $userId, IssueQuery $query, array $connectionIds = [], array $cursors = []): array {
		$connections = $this->connections->list($userId);
		if ($connectionIds !== []) {
			$connections = array_filter(
				$connections,
				static fn ($c): bool => in_array($c->id, $connectionIds, true),
			);
		}

		$issues = [];
		$errors = [];
		$nextCursors = [];

		foreach ($connections as $meta) {
			$client = $this->trackers->get($meta->tracker);
			if ($client === null) {
				continue;
			}
			try {
				$result = $this->cachedSearch($userId, $meta, $client, $query, $cursors[$meta->id] ?? null);
				$issues = array_merge($issues, $result->issues);
				if ($result->nextCursor !== null) {
					$nextCursors[$meta->id] = $result->nextCursor;
				}
			} catch (TrackerException $e) {
				$errors[] = [
					'connectionId' => $meta->id,
					'label' => $meta->label,
					'message' => $e->getMessage(),
				];
			}
		}

		$this->sortIssues($issues, $query->sort, $query->order);

		return ['issues' => $issues, 'errors' => $errors, 'nextCursors' => $nextCursors];
	}

	public function getIssue(string $userId, string $ref): Issue {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		return $client->getIssue($connection, $parts);
	}

	/**
	 * @return Comment[]
	 */
	public function getComments(string $userId, string $ref): array {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		return $client->getComments($connection, $parts);
	}

	public function addComment(string $userId, string $ref, string $body): Comment {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		$comment = $client->addComment($connection, $parts, $body);
		$this->syncState->markTouched($userId, $ref);
		return $comment;
	}

	public function updateComment(string $userId, string $ref, string $commentId, string $body): Comment {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		$comment = $client->updateComment($connection, $parts, $commentId, $body);
		$this->syncState->markTouched($userId, $ref);
		return $comment;
	}

	/**
	 * Fetch an issue-referenced file/image via the tracker's credentials.
	 *
	 * @return array{body: string, contentType: string}
	 * @throws TrackerException
	 */
	public function fetchFile(string $userId, string $ref, string $src): array {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		return $client->fetchFile($connection, $parts, $src);
	}

	/**
	 * @return \OCA\Unity\Model\TimeRecord[]
	 * @throws TrackerException
	 */
	public function getTimeRecords(string $userId, string $ref): array {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		return $client->getTimeRecords($connection, $parts);
	}

	/**
	 * @param array<string, mixed> $changes
	 * @throws TrackerException
	 */
	public function updateIssue(string $userId, string $ref, array $changes): Issue {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		$issue = $client->updateIssue($connection, $parts, $changes);
		$this->syncState->markTouched($userId, $ref);
		return $issue;
	}

	/**
	 * @return array<string, mixed>
	 * @throws TrackerException
	 */
	public function getEditMeta(string $userId, string $ref): array {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		return $client->getEditMeta($connection, $parts);
	}

	/**
	 * @throws TrackerException
	 */
	public function logTime(string $userId, string $ref, int $seconds, string $comment, ?string $startedAt): void {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		if (!$client->supportsTimeTracking()) {
			throw new TrackerException('Time tracking is not supported for this tracker');
		}
		$client->logTime($connection, $parts, $seconds, $comment, $startedAt);
		$this->syncState->markTouched($userId, $ref);
	}

	/**
	 * @throws TrackerException
	 */
	public function updateTime(string $userId, string $ref, string $recordId, int $seconds, string $comment, ?string $startedAt): void {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		if (!$client->supportsTimeTracking()) {
			throw new TrackerException('Time tracking is not supported for this tracker');
		}
		$client->updateTime($connection, $parts, $recordId, $seconds, $comment, $startedAt);
		$this->syncState->markTouched($userId, $ref);
	}

	/**
	 * @throws TrackerException
	 */
	public function deleteTime(string $userId, string $ref, string $recordId): void {
		[$client, $connection, $parts] = $this->resolve($userId, $ref);
		if (!$client->supportsTimeTracking()) {
			throw new TrackerException('Time tracking is not supported for this tracker');
		}
		$client->deleteTime($connection, $parts, $recordId);
		$this->syncState->markTouched($userId, $ref);
	}

	private function cachedSearch(
		string $userId,
		Connection $meta,
		TrackerClientInterface $client,
		IssueQuery $query,
		?string $cursor,
	): TrackerSearchResult {
		$key = $this->cacheKey($userId, $meta->id, $query, $cursor);
		$cached = $this->hydrate($this->cache->get($key));
		if ($cached !== null) {
			return $cached;
		}
		$connection = $this->connections->getWithSecrets($userId, $meta->id);
		if ($connection === null) {
			return new TrackerSearchResult([]);
		}
		$result = $client->search($connection, $query, $cursor);
		$this->cache->set($key, $this->dehydrate($result), self::TTL);
		return $result;
	}

	private function cacheKey(string $userId, string $connectionId, IssueQuery $query, ?string $cursor): string {
		$generation = (int)($this->cache->get(self::generationKey($userId)) ?? 0);
		return 'search:' . sha1(implode('|', [
			$generation,
			$userId,
			$connectionId,
			$query->term,
			$query->assignedToMe ? '1' : '0',
			$query->sort,
			$query->order,
			(string)$cursor,
			(string)$query->limit,
		]));
	}

	public static function generationKey(string $userId): string {
		return 'gen:' . $userId;
	}

	/**
	 * @return array{issues: list<array<string, mixed>>, nextCursor: ?string}
	 */
	private function dehydrate(TrackerSearchResult $result): array {
		return [
			'issues' => array_map(static fn (Issue $i): array => $i->jsonSerialize(), $result->issues),
			'nextCursor' => $result->nextCursor,
		];
	}

	private function hydrate(mixed $data): ?TrackerSearchResult {
		if (!is_array($data) || !isset($data['issues']) || !is_array($data['issues'])) {
			return null;
		}
		$issues = [];
		foreach ($data['issues'] as $raw) {
			if (is_array($raw)) {
				$issues[] = Issue::fromArray($raw);
			}
		}
		$next = $data['nextCursor'] ?? null;
		return new TrackerSearchResult($issues, is_string($next) ? $next : null);
	}

	/**
	 * @return array{0: TrackerClientInterface, 1: Connection, 2: array}
	 * @throws TrackerException
	 */
	private function resolve(string $userId, string $ref): array {
		$decoded = Ref::decode($ref);
		$client = $this->trackers->get($decoded['t']);
		if ($client === null) {
			throw new TrackerException('Unknown tracker: ' . $decoded['t']);
		}
		$connection = $this->connections->getWithSecrets($userId, $decoded['c']);
		if ($connection === null) {
			throw new TrackerException('Connection not found');
		}
		return [$client, $connection, $decoded['p']];
	}

	/**
	 * @param Issue[] $issues
	 */
	private function sortIssues(array &$issues, string $sort, string $order): void {
		usort($issues, static function (Issue $a, Issue $b) use ($sort): int {
			return match ($sort) {
				'title' => strcasecmp($a->title, $b->title),
				'status' => strcasecmp($a->status, $b->status),
				'created' => strcmp((string)$a->createdAt, (string)$b->createdAt),
				default => strcmp((string)$a->updatedAt, (string)$b->updatedAt),
			};
		});
		if (strtolower($order) === 'desc') {
			$issues = array_reverse($issues);
		}
	}
}
