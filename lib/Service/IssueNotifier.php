<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

use OCA\Unity\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;

/**
 * Creates and dismisses the bell notifications for issue changes. Owns the
 * object-id convention (notification object ids are capped at 64 chars, so an
 * issue's long base64url ref is keyed by its hash and the full ref rides in the
 * subject parameters). Shared by the sync job (create) and the controller
 * (dismiss when the user views the issue).
 */
class IssueNotifier {

	public function __construct(
		private IManager $notifications,
		private ITimeFactory $time,
	) {
	}

	/** Stable, length-safe notification object id for an issue ref. */
	public function objectId(string $ref): string {
		return sha1($ref);
	}

	/**
	 * Send a notification, replacing any prior unread one for the same issue so
	 * the bell keeps at most one entry per issue.
	 *
	 * @param array<string, mixed> $params
	 */
	public function notify(string $userId, string $type, string $ref, array $params): void {
		$objectId = $this->objectId($ref);
		$params['ref'] = $ref;

		$this->dismiss($userId, $ref);

		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setObject('issue', $objectId)
			->setSubject($type, $params)
			->setDateTime($this->time->getDateTime());
		$this->notifications->notify($notification);
	}

	/** Remove any pending notification for an issue (e.g. once the user views it). */
	public function dismiss(string $userId, string $ref): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setObject('issue', $this->objectId($ref));
		try {
			$this->notifications->markProcessed($notification);
		} catch (\Throwable $e) {
		}
	}
}
