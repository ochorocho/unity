<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Dashboard\MyIssuesWidget;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Service\IssueService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class MyIssuesWidgetTest extends TestCase {

	public function testGetItemsMapsAssignedIssues(): void {
		$issue = new Issue('REF1', 'gitlab', 'c1', 'GL', '#7', 'Fix bug', '', 'Open', 'Bob', 'Alice', [], 'group/app', '2026-01-01', '2026-02-01', 'https://gitlab/x');

		$capturedQuery = null;
		$issueService = $this->createMock(IssueService::class);
		$issueService->method('search')->willReturnCallback(function (string $uid, IssueQuery $q) use (&$capturedQuery, $issue) {
			$capturedQuery = $q;
			return ['issues' => [$issue], 'errors' => [], 'nextCursors' => []];
		});

		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/unity/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnArgument(0);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc/index.php/apps/unity/');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$widget = new MyIssuesWidget($issueService, $url, $l);
		$items = $widget->getItems('admin', null, 7);

		$this->assertTrue($capturedQuery->assignedToMe, 'widget should query issues assigned to the user');
		$this->assertCount(1, $items);
		$this->assertSame('#7 Fix bug', $items[0]->getTitle());
		$this->assertSame('group/app · Open', $items[0]->getSubtitle());
		$this->assertSame('https://nc/index.php/apps/unity/#issue/REF1', $items[0]->getLink());
	}

	public function testWidgetIdentity(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$widget = new MyIssuesWidget($this->createMock(IssueService::class), $this->createMock(IURLGenerator::class), $l);
		$this->assertSame('unity_my_issues', $widget->getId());
	}
}
