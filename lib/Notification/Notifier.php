<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Notification;

use OCA\Unity\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the bell notifications produced by {@see \OCA\Unity\Service\IssueSyncService}.
 * All display data is carried in the notification's subject parameters, so no
 * tracker API call happens here. The click target deep-links into the app via
 * the `#issue/<ref>` hash that src/App.vue already resolves.
 */
class Notifier implements INotifier {

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Unity';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$displayId = (string)($p['displayId'] ?? '');
		$title = (string)($p['title'] ?? '');
		$status = (string)($p['status'] ?? '');
		$count = (int)($p['count'] ?? 0);

		switch ($notification->getSubject()) {
			case 'issue_new':
				$subject = $l->t('New issue assigned: %s', [$displayId]);
				break;
			case 'issue_status':
				$subject = $l->t('%1$s changed status to %2$s', [$displayId, $status]);
				break;
			case 'issue_comment':
				$subject = $l->n('%n new comment on %s', '%n new comments on %s', $count, [$displayId]);
				break;
			case 'issue_closed':
				$subject = $l->t('%s was closed or is no longer assigned to you', [$displayId]);
				break;
			case 'issue_updated':
			default:
				$subject = $l->t('%s was updated', [$displayId]);
				break;
		}

		$link = $this->issueLink((string)($p['ref'] ?? ''));

		$notification
			->setParsedSubject($subject)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')))
			->setLink($link);

		if ($title !== '') {
			$notification->setParsedMessage($title);
		}

		$action = $notification->createAction();
		$action->setLabel('view')
			->setParsedLabel($l->t('View issue'))
			->setLink($link, IAction::TYPE_WEB)
			->setPrimary(true);
		$notification->addParsedAction($action);

		return $notification;
	}

	/** Absolute app URL that deep-links to a specific issue by its ref. */
	private function issueLink(string $ref): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index')
			. '#issue/' . rawurlencode($ref);
	}
}
