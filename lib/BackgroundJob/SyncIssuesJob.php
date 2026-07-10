<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\BackgroundJob;

use OCA\Unity\Service\IssueSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Runs the issue sync every 5 minutes. Time-sensitive so it fires promptly and
 * keeps notifications close to real time.
 */
class SyncIssuesJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private IssueSyncService $syncService,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->syncService->run();
	}
}
