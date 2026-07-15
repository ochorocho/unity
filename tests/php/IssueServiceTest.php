<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Comment;
use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Model\Relation;
use OCA\Unity\Model\TimeRecord;
use OCA\Unity\Model\TrackerSearchResult;
use OCA\Unity\Service\ConnectionService;
use OCA\Unity\Service\IssueService;
use OCA\Unity\Service\SyncStateService;
use OCA\Unity\Service\Tracker\TrackerClientInterface;
use OCA\Unity\Service\Tracker\TrackerException;
use OCA\Unity\Service\TrackerManager;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class IssueServiceTest extends TestCase {

	private array $store;
	private ConnectionService $connections;
	private TrackerManager $trackers;
	private TrackerClientInterface $client;
	private IssueService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $k) => $this->store[$k] ?? null);
		$cache->method('set')->willReturnCallback(function (string $k, $v, $ttl = 0): bool {
			$this->store[$k] = $v;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->client = $this->createMock(TrackerClientInterface::class);
		$this->connections = $this->createMock(ConnectionService::class);
		$this->trackers = $this->createMock(TrackerManager::class);
		$this->trackers->method('get')->willReturn($this->client);

		$meta = new Connection('c1', 'jira', 'My Jira', 'https://acme.atlassian.net');
		$this->connections->method('list')->willReturn([$meta]);
		$this->connections->method('getWithSecrets')->willReturn($meta->withSecrets('token'));

		$this->service = new IssueService($this->connections, $this->trackers, $cacheFactory, $this->createMock(SyncStateService::class));
	}

	private function issue(string $id, string $title, string $updated): Issue {
		return new Issue("ref-$id", 'jira', 'c1', 'My Jira', $id, $title, '', 'Open', 'Bob', 'Alice', [], 'Acme', $updated, $updated, 'https://acme/browse/' . $id);
	}

	public function testCachesPerConnectionSearch(): void {
		$this->client->expects($this->once())
			->method('search')
			->willReturn(new TrackerSearchResult([$this->issue('ABC-1', 'One', '2026-01-01')], 'cursor2'));

		$query = new IssueQuery(term: 'x');
		$first = $this->service->search('admin', $query);
		$second = $this->service->search('admin', $query);

		$this->assertCount(1, $first['issues']);
		$this->assertCount(1, $second['issues']);
		$this->assertSame('One', $second['issues'][0]->title);
		$this->assertSame(['c1' => 'cursor2'], $second['nextCursors']);
	}

	public function testDifferentSortIsSeparateCacheEntry(): void {
		$this->client->expects($this->exactly(2))
			->method('search')
			->willReturn(new TrackerSearchResult([$this->issue('ABC-1', 'One', '2026-01-01')]));

		$this->service->search('admin', new IssueQuery(sort: 'updated'));
		$this->service->search('admin', new IssueQuery(sort: 'title'));
	}

	public function testLogTimeRejectsUnsupportedTracker(): void {
		$this->client->method('supportsTimeTracking')->willReturn(false);
		$this->client->expects($this->never())->method('logTime');
		$this->expectException(TrackerException::class);
		$this->service->logTime('admin', Ref::encode('github', 'c1', ['owner' => 'o']), 3600, '', null);
	}

	public function testUpdateTimeRejectsRecordNotOwnedByUser(): void {
		$this->client->method('supportsTimeTracking')->willReturn(true);
		$this->client->method('getTimeRecords')->willReturn([
			new TimeRecord('42', 'Bob', 3600, '2026-02-01', '', editable: false, deletable: false),
		]);
		$this->client->expects($this->never())->method('updateTime');
		$this->expectException(TrackerException::class);
		$this->service->updateTime('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '42', 3600, '', null);
	}

	public function testDeleteTimeRejectsMissingRecord(): void {
		$this->client->method('supportsTimeTracking')->willReturn(true);
		$this->client->method('getTimeRecords')->willReturn([]);
		$this->client->expects($this->never())->method('deleteTime');
		$this->expectException(TrackerException::class);
		$this->service->deleteTime('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '42');
	}

	public function testDeleteTimeForwardsWhenOwned(): void {
		$this->client->method('supportsTimeTracking')->willReturn(true);
		$this->client->method('getTimeRecords')->willReturn([
			new TimeRecord('42', 'Alice', 3600, '2026-02-01', '', editable: true, deletable: true),
		]);
		$this->client->expects($this->once())->method('deleteTime');
		$this->service->deleteTime('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '42');
	}

	public function testDeleteCommentRejectsCommentNotOwnedByUser(): void {
		$this->client->method('getComments')->willReturn([
			new Comment('5', 'Bob', null, 'hi', null, deletable: false),
		]);
		$this->client->expects($this->never())->method('deleteComment');
		$this->expectException(TrackerException::class);
		$this->service->deleteComment('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '5');
	}

	public function testDeleteCommentForwardsWhenOwned(): void {
		$this->client->method('getComments')->willReturn([
			new Comment('5', 'Alice', null, 'hi', null, deletable: true),
		]);
		$this->client->expects($this->once())->method('deleteComment');
		$this->service->deleteComment('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '5');
	}

	public function testAddCommentDecodesEditorEscapedAngleBrackets(): void {
		$captured = null;
		$this->client->method('addComment')->willReturnCallback(function ($conn, $parts, $body) use (&$captured): Comment {
			$captured = $body;
			return new Comment('1', 'Alice', null, $body, null);
		});
		$this->service->addComment('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '&gt; Quote with &lt;tag&gt;');
		$this->assertSame('> Quote with <tag>', $captured, 'editor-escaped < and > are restored before dispatch');
	}

	public function testMergesAndSortsByTitle(): void {
		$this->client->method('search')->willReturn(new TrackerSearchResult([
			$this->issue('ABC-2', 'Zebra', '2026-01-02'),
			$this->issue('ABC-1', 'Apple', '2026-01-01'),
		]));

		$result = $this->service->search('admin', new IssueQuery(sort: 'title', order: 'asc'));
		$titles = array_map(static fn (Issue $i): string => $i->title, $result['issues']);
		$this->assertSame(['Apple', 'Zebra'], $titles);
	}

	public function testDeleteAttachmentForwardsWhenSupported(): void {
		$this->client->method('supportsAttachments')->willReturn(true);
		$this->client->expects($this->once())->method('deleteAttachment');
		$this->service->deleteAttachment('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '9');
	}

	public function testDeleteAttachmentRejectsUnsupportedTracker(): void {
		$this->client->method('supportsAttachments')->willReturn(false);
		$this->client->expects($this->never())->method('deleteAttachment');
		$this->expectException(TrackerException::class);
		$this->service->deleteAttachment('admin', Ref::encode('github', 'c1', ['owner' => 'o']), '9');
	}

	public function testAddRelationRejectsCrossConnectionTarget(): void {
		$this->client->method('supportsRelations')->willReturn(true);
		$this->client->expects($this->never())->method('addRelation');
		$this->expectException(TrackerException::class);
		$this->service->addRelation(
			'admin',
			Ref::encode('jira', 'c1', ['key' => 'ABC-1']),
			'relates',
			Ref::encode('jira', 'c2', ['key' => 'DEF-2']),
		);
	}

	public function testAddRelationRejectsSelfRelation(): void {
		$this->client->method('supportsRelations')->willReturn(true);
		$this->client->expects($this->never())->method('addRelation');
		$this->expectException(TrackerException::class);
		$this->service->addRelation(
			'admin',
			Ref::encode('jira', 'c1', ['key' => 'ABC-1']),
			'relates',
			Ref::encode('jira', 'c1', ['key' => 'ABC-1']),
		);
	}

	public function testAddRelationForwardsWhenValid(): void {
		$this->client->method('supportsRelations')->willReturn(true);
		$expected = new Relation('9', 'relates', 'Relates to', 'tref', '#2', 'Other', 'Open', 'https://x/2');
		$this->client->expects($this->once())->method('addRelation')->willReturn($expected);
		$relation = $this->service->addRelation(
			'admin',
			Ref::encode('jira', 'c1', ['key' => 'ABC-1']),
			'relates',
			Ref::encode('jira', 'c1', ['key' => 'DEF-2']),
		);
		$this->assertSame($expected, $relation);
	}

	public function testDeleteRelationRejectsNonDeletable(): void {
		$this->client->method('supportsRelations')->willReturn(true);
		$this->client->method('getRelations')->willReturn([
			new Relation('9', 'parent', 'Parent', 'tref', '#2', 'Other', 'Open', 'https://x/2', deletable: false),
		]);
		$this->client->expects($this->never())->method('deleteRelation');
		$this->expectException(TrackerException::class);
		$this->service->deleteRelation('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '9');
	}

	public function testDeleteRelationForwardsWhenDeletable(): void {
		$this->client->method('supportsRelations')->willReturn(true);
		$this->client->method('getRelations')->willReturn([
			new Relation('9', 'relates', 'Relates to', 'tref', '#2', 'Other', 'Open', 'https://x/2', deletable: true),
		]);
		$this->client->expects($this->once())->method('deleteRelation');
		$this->service->deleteRelation('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), '9');
	}

	public function testSearchAssigneesDelegatesWithRefContext(): void {
		$this->client->expects($this->once())->method('searchAssignees')
			->with($this->anything(), ['refParts' => ['key' => 'ABC-1']], 'al')
			->willReturn([['id' => 'acc-1', 'name' => 'Alice']]);
		$out = $this->service->searchAssignees('admin', Ref::encode('jira', 'c1', ['key' => 'ABC-1']), 'al');
		$this->assertSame([['id' => 'acc-1', 'name' => 'Alice']], $out);
	}

	public function testSearchCreateAssigneesDelegatesWithProjectContext(): void {
		$this->client->expects($this->once())->method('searchAssignees')
			->with($this->anything(), ['project' => 'PROJ'], 'bo')
			->willReturn([['id' => 'acc-2', 'name' => 'Bob']]);
		$out = $this->service->searchCreateAssignees('admin', 'c1', 'PROJ', 'bo');
		$this->assertSame([['id' => 'acc-2', 'name' => 'Bob']], $out);
	}

	public function testCreateIssueForwardsAssignee(): void {
		$this->client->method('supportsCreate')->willReturn(true);
		$this->client->expects($this->once())->method('createIssue')
			->with($this->anything(), $this->callback(static fn (array $t): bool => ($t['assignee'] ?? null) === 'acc-1'))
			->willReturn($this->issue('ABC-1', 'One', '2026-01-01'));
		$this->service->createIssue('admin', 'c1', ['project' => 'P', 'title' => 'T', 'assignee' => 'acc-1']);
	}
}
