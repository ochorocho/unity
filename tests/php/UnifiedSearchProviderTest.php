<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Issue;
use OCA\Unity\Search\UnifiedSearchProvider;
use OCA\Unity\Service\IssueService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

class UnifiedSearchProviderTest extends TestCase {

	private function issue(): Issue {
		return new Issue('REF1', 'gitlab', 'c1', 'GL', '#7', 'Fix bug', '', 'Open', 'Bob', 'Alice', [], 'group/app', '2026-01-01', '2026-02-01', 'https://gitlab/x');
	}

	public function testSearchMapsIssuesToEntries(): void {
		$issueService = $this->createMock(IssueService::class);
		$issueService->method('search')->willReturn(['issues' => [$this->issue()], 'errors' => [], 'nextCursors' => []]);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/unity/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnArgument(0);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc/index.php/apps/unity/');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$provider = new UnifiedSearchProvider($issueService, $url, $l);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('bug');
		$query->method('getLimit')->willReturn(10);

		$json = $provider->search($user, $query)->jsonSerialize();
		$this->assertNotEmpty($json['entries']);
		$entry = $json['entries'][0]->jsonSerialize();
		$this->assertSame('#7 Fix bug', $entry['title']);
		$this->assertSame('group/app · Open', $entry['subline']);
		$this->assertSame('https://nc/index.php/apps/unity/#issue/REF1', $entry['resourceUrl']);
	}

	public function testProviderIdentity(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$provider = new UnifiedSearchProvider($this->createMock(IssueService::class), $this->createMock(IURLGenerator::class), $l);
		$this->assertSame('unity', $provider->getId());
		$this->assertSame(-1, $provider->getOrder('unity.page.index', []));
		$this->assertSame(25, $provider->getOrder('files.view.index', []));
	}
}
