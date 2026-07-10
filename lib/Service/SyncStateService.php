<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

use OCA\Unity\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;

/**
 * Persists the per-user sync state in IUserConfig: the issue snapshot used to
 * diff between runs, and the set of issues the user just changed in-app (so the
 * next sync suppresses notifications about their own edits). Kept separate from
 * IssueSyncService so IssueService can mark touches without a circular dependency.
 */
class SyncStateService {

	private const SNAPSHOT_KEY = 'sync_state';
	private const TOUCHED_KEY = 'sync_touched';
	/** How long a self-edit suppresses notifications for that issue (covers API propagation + a couple of runs). */
	private const TOUCHED_TTL = 900;

	public function __construct(
		private IUserConfig $userConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{0: array<string, array<string, mixed>>, 1: bool} [snapshot, firstRun]
	 */
	public function getSnapshot(string $userId): array {
		$raw = $this->userConfig->getValueString($userId, Application::APP_ID, self::SNAPSHOT_KEY, '');
		if ($raw === '') {
			return [[], true];
		}
		$decoded = json_decode($raw, true);
		return [is_array($decoded) ? $decoded : [], false];
	}

	/**
	 * @param array<string, array<string, mixed>> $snapshot
	 */
	public function setSnapshot(string $userId, array $snapshot): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, self::SNAPSHOT_KEY, json_encode($snapshot));
	}

	/** Record that the user just changed this issue in-app. */
	public function markTouched(string $userId, string $ref): void {
		$touched = $this->loadTouched($userId);
		$touched[$ref] = $this->time->getTime();
		$this->userConfig->setValueString($userId, Application::APP_ID, self::TOUCHED_KEY, json_encode($touched));
	}

	/**
	 * Refs the user changed in-app within the TTL, as a set (ref => true). Prunes
	 * and persists expired entries as a side effect.
	 *
	 * @return array<string, bool>
	 */
	public function getTouchedRefs(string $userId): array {
		$touched = $this->loadTouched($userId);
		$cutoff = $this->time->getTime() - self::TOUCHED_TTL;
		$live = array_filter($touched, static fn (int $ts): bool => $ts >= $cutoff);
		if (count($live) !== count($touched)) {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::TOUCHED_KEY, json_encode($live));
		}
		return array_fill_keys(array_keys($live), true);
	}

	/**
	 * @return array<string, int>
	 */
	private function loadTouched(string $userId): array {
		$raw = $this->userConfig->getValueString($userId, Application::APP_ID, self::TOUCHED_KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $ref => $ts) {
			if (is_string($ref) && is_int($ts)) {
				$out[$ref] = $ts;
			}
		}
		return $out;
	}
}
