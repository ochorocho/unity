<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Search;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Service\IssueService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Surfaces Unity issues in Nextcloud's global (unified) search. Each hit links
 * back into the Unity app via the shareable #issue/<ref> deep link.
 */
class UnifiedSearchProvider implements IProvider {

	public function __construct(
		private IssueService $issueService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Issues');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// Rank our own app's routes highest, otherwise a mid priority.
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 25;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$limit = max(5, min($query->getLimit(), 20));
		$issueQuery = new IssueQuery(
			$query->getTerm(),
			'updated',
			'desc',
			false,
			false,
			$limit,
		);
		$result = $this->issueService->search($user->getUID(), $issueQuery);

		$icon = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
		$appUrl = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');

		$entries = [];
		foreach (array_slice($result['issues'], 0, $limit) as $issue) {
			/** @var Issue $issue */
			$entries[] = new SearchResultEntry(
				'',
				trim($issue->displayId . ' ' . $issue->title),
				trim(implode(' · ', array_filter([$issue->project, $issue->status]))),
				$appUrl . '#issue/' . $issue->ref,
				$icon,
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}
}
