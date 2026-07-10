<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Periodically fetches each user's assigned issues, diffs them against a stored
 * snapshot, and raises Nextcloud notifications for changes. Kept API-gentle:
 * one list call per connection per run, and comment fetches only for issues
 * whose `updatedAt` actually moved. Notifications for issues the user just
 * changed in-app are suppressed. The pure decision logic lives in
 * {@see computeNotifications()} so it can be unit-tested without live services.
 */
class IssueSyncService {

	private const LIMIT = 50;

	public function __construct(
		private IUserManager $userManager,
		private ConnectionService $connections,
		private IssueService $issues,
		private SyncStateService $state,
		private IssueNotifier $notifier,
		private LoggerInterface $logger,
	) {
	}

	/** Entry point for the background job: sync every seen user with connections. */
	public function run(): void {
		$this->userManager->callForSeenUsers(function (IUser $user): void {
			try {
				$this->syncUser($user->getUID());
			} catch (\Throwable $e) {
				$this->logger->warning('Unity issue sync failed for ' . $user->getUID() . ': ' . $e->getMessage(), ['exception' => $e]);
			}
		});
	}

	public function syncUser(string $userId): void {
		$conns = $this->connections->list($userId);
		if ($conns === []) {
			return;
		}
		[$old, $firstRun] = $this->state->getSnapshot($userId);
		$suppress = $this->state->getTouchedRefs($userId);

		// Fetch assigned issues per connection so a failing tracker is isolated
		// (its snapshot refs are left untouched → no false "closed" alerts).
		$query = new IssueQuery('', 'updated', 'desc', true, self::LIMIT);
		$current = [];
		$succeeded = [];
		foreach ($conns as $conn) {
			$result = $this->issues->search($userId, $query, [$conn->id]);
			if (!empty($result['errors'])) {
				continue;
			}
			$succeeded[$conn->id] = true;
			foreach ($result['issues'] as $issue) {
				/** @var Issue $issue */
				$current[$issue->ref] = $this->entryFor($issue, $conn);
			}
		}

		$connIds = array_map(static fn (Connection $c): string => $c->id, $conns);
		$commentCounter = fn (string $ref, ?string $cutoff, string $self): array
			=> $this->countNewComments($userId, $ref, $cutoff, $self);

		[$notes, $snapshot] = $this->computeNotifications($old, $current, $succeeded, $connIds, $firstRun, $commentCounter, $suppress);

		foreach ($notes as [$type, $ref, $params]) {
			$this->notifier->notify($userId, $type, $ref, $params);
		}

		$this->state->setSnapshot($userId, $snapshot);
	}

	/**
	 * Pure diff. Decides the notifications to send and the snapshot to persist.
	 *
	 * @param array<string, array<string, mixed>> $old previous snapshot: ref => {h,u,s,c,d,ti,tr}
	 * @param array<string, array<string, mixed>> $current freshly fetched: ref => {h,u,s,d,ti,tr,self} (succeeded conns only)
	 * @param array<string, bool> $succeeded connectionId => true for connections that fetched OK this run
	 * @param list<string> $connIds all of the user's current connection ids
	 * @param bool $firstRun seed silently when true (no notifications)
	 * @param callable(string, ?string, string): array{0: int, 1: ?string} $commentCounter (ref, cutoff, self) => [newCount, newMarker]
	 * @param array<string, bool> $suppress refs the user just changed in-app (update snapshot but do not notify)
	 * @return array{0: list<array{0: string, 1: string, 2: array<string, mixed>}>, 1: array<string, array<string, mixed>>}
	 */
	public function computeNotifications(array $old, array $current, array $succeeded, array $connIds, bool $firstRun, callable $commentCounter, array $suppress = []): array {
		$notes = [];
		$snapshot = $old;

		foreach ($current as $ref => $cur) {
			$prev = $old[$ref] ?? null;
			$marker = $prev['c'] ?? null;

			if ($prev === null) {
				// Newly-assigned issue (no comment fetch — next update uses updatedAt as the cutoff).
				if (!$firstRun && !isset($suppress[$ref])) {
					$notes[] = ['issue_new', $ref, $this->paramsFor($cur)];
				}
				$snapshot[$ref] = $this->stateFor($cur, null);
				continue;
			}

			$event = null;
			$updatedChanged = (string)($cur['u'] ?? '') !== (string)($prev['u'] ?? '');
			if ($updatedChanged && !$firstRun) {
				// A new comment bumps updatedAt; use the stored marker, else the
				// previous updatedAt, as the "already seen" cutoff. The counter is
				// run even when suppressed so the marker advances past our own comment.
				$cutoff = $prev['c'] ?? ($prev['u'] ?? null);
				[$newCount, $marker] = $commentCounter($ref, $cutoff, (string)($cur['self'] ?? ''));
				if ($newCount > 0) {
					$event = ['issue_comment', $ref, ['count' => $newCount] + $this->paramsFor($cur)];
				}
			}
			if ($event === null && !$firstRun) {
				if ((string)($prev['s'] ?? '') !== (string)$cur['s']) {
					$event = ['issue_status', $ref, $this->paramsFor($cur)];
				} elseif ((string)($prev['h'] ?? '') !== (string)$cur['h']) {
					$event = ['issue_updated', $ref, $this->paramsFor($cur)];
				}
			}
			if ($event !== null && !isset($suppress[$ref])) {
				$notes[] = $event;
			}
			$snapshot[$ref] = $this->stateFor($cur, $marker);
		}

		// Removals: a tracked issue gone from a SUCCEEDED connection was closed or
		// reassigned away. Failed connections are left untouched; removed
		// connections are dropped silently.
		foreach ($old as $ref => $prev) {
			if (isset($current[$ref])) {
				continue;
			}
			$connId = $this->connectionOf($ref);
			if ($connId !== null && isset($succeeded[$connId])) {
				if (!$firstRun && !isset($suppress[$ref])) {
					$notes[] = ['issue_closed', $ref, $this->paramsFor($prev)];
				}
				unset($snapshot[$ref]);
			} elseif ($connId === null || !in_array($connId, $connIds, true)) {
				unset($snapshot[$ref]);
			}
		}

		return [$notes, $snapshot];
	}

	/** Fetch comments for a changed issue and count those newer than the cutoff. */
	private function countNewComments(string $userId, string $ref, ?string $cutoff, string $self): array {
		try {
			$comments = $this->issues->getComments($userId, $ref);
		} catch (\Throwable $e) {
			return [0, $cutoff];
		}
		$cutoffTs = ($cutoff !== null && $cutoff !== '') ? strtotime($cutoff) : 0;
		$cutoffTs = $cutoffTs === false ? 0 : $cutoffTs;
		$count = 0;
		$maxTs = $cutoffTs;
		$marker = $cutoff;
		foreach ($comments as $comment) {
			if ($comment->createdAt === null) {
				continue;
			}
			$ts = strtotime($comment->createdAt);
			if ($ts === false) {
				continue;
			}
			if ($ts > $cutoffTs) {
				// Best-effort: don't notify a user about their own comment.
				if ($self === '' || strcasecmp(trim($comment->author), $self) !== 0) {
					$count++;
				}
			}
			if ($ts > $maxTs) {
				$maxTs = $ts;
				$marker = $comment->createdAt;
			}
		}
		return [$count, $marker];
	}

	/** Build the current-run snapshot/params source for an issue. */
	private function entryFor(Issue $issue, Connection $conn): array {
		$labels = $issue->labels;
		sort($labels);
		$hash = sha1(implode('|', [
			$issue->status,
			$issue->assignee,
			$issue->title,
			implode(',', $labels),
			(string)$issue->updatedAt,
			$issue->project,
		]));
		return [
			'h' => $hash,
			'u' => $issue->updatedAt,
			's' => $issue->status,
			'd' => $issue->displayId,
			'ti' => $issue->title,
			'tr' => $issue->tracker,
			'self' => trim($conn->username) !== '' ? trim($conn->username) : $conn->label,
		];
	}

	/** Persisted snapshot entry for an issue. */
	private function stateFor(array $entry, ?string $marker): array {
		return [
			'h' => (string)($entry['h'] ?? ''),
			'u' => $entry['u'] ?? null,
			's' => (string)($entry['s'] ?? ''),
			'c' => $marker,
			'd' => (string)($entry['d'] ?? ''),
			'ti' => (string)($entry['ti'] ?? ''),
			'tr' => (string)($entry['tr'] ?? ''),
		];
	}

	/** Notification subject parameters (all display data — the notifier needs no API call). */
	private function paramsFor(array $entry): array {
		return [
			'displayId' => (string)($entry['d'] ?? ''),
			'title' => (string)($entry['ti'] ?? ''),
			'tracker' => (string)($entry['tr'] ?? ''),
			'status' => (string)($entry['s'] ?? ''),
		];
	}

	private function connectionOf(string $ref): ?string {
		try {
			$decoded = Ref::decode($ref);
		} catch (\Throwable $e) {
			return null;
		}
		$connId = $decoded['c'] ?? null;
		return is_string($connId) && $connId !== '' ? $connId : null;
	}
}
