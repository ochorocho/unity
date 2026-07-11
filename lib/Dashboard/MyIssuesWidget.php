<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Dashboard;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Service\IssueService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * "My issues" dashboard widget — the current user's issues assigned to them,
 * merged across all their tracker connections.
 */
class MyIssuesWidget implements IAPIWidget, IIconWidget {

	public function __construct(
		private IssueService $issueService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '_my_issues';
	}

	public function getTitle(): string {
		return $this->l->t('My issues');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-unity';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');
	}

	public function load(): void {
	}

	/**
	 * @return list<WidgetItem>
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$query = new IssueQuery('', 'updated', 'desc', true, false, $limit);
		$result = $this->issueService->search($userId, $query);

		$icon = $this->getIconUrl();
		$appUrl = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');

		$items = [];
		foreach (array_slice($result['issues'], 0, $limit) as $issue) {
			/** @var Issue $issue */
			$items[] = new WidgetItem(
				trim($issue->displayId . ' ' . $issue->title),
				trim(implode(' · ', array_filter([$issue->project, $issue->status]))),
				$appUrl . '#issue/' . $issue->ref,
				$icon,
				(string)($issue->updatedAt ?? ''),
			);
		}
		return $items;
	}
}
