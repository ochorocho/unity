<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Connection;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Service\Tracker\RedmineClient;
use OCA\Unity\Service\Tracker\TrackerException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RedmineClientTest extends TestCase {

	private IClient&MockObject $http;
	private RedmineClient $client;
	private Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->http = $this->createMock(IClient::class);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->http);
		$this->client = new RedmineClient($service, new NullLogger());
		$this->connection = new Connection('r1', 'redmine', 'RM', 'https://redmine.example.com/', '', 'key');
	}

	private function response(int $status, array|string $body): IResponse&MockObject {
		$r = $this->createMock(IResponse::class);
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn(is_array($body) ? (string)json_encode($body) : $body);
		$r->method('getHeader')->willReturn('');
		return $r;
	}

	public function testSearchMapsIssuesAndPaginates(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, [
				'issues' => [[
					'id' => 55, 'subject' => 'Do the thing', 'description' => 'because',
					'status' => ['name' => 'New'], 'author' => ['name' => 'Bob'],
					'assigned_to' => ['name' => 'Alice'], 'project' => ['name' => 'Website'],
					'created_on' => '2026-01-01T00:00:00Z', 'updated_on' => '2026-02-01T00:00:00Z',
					'spent_hours' => 2,
				]],
				'total_count' => 40, 'offset' => 0, 'limit' => 30,
			]);
		});

		$result = $this->client->search($this->connection, new IssueQuery(sort: 'updated', order: 'desc'));

		$this->assertStringContainsString('/issues.json', $captured['url']);
		$this->assertSame('updated_on:desc', $captured['options']['query']['sort']);
		$this->assertSame('key', $captured['options']['headers']['X-Redmine-API-Key']);
		$this->assertSame('30', $result->nextCursor, 'offset+limit < total_count → next offset');
		$issue = $result->issues[0];
		$this->assertSame('#55', $issue->displayId);
		$this->assertSame('Do the thing', $issue->title);
		$this->assertSame('New', $issue->status);
		$this->assertSame('Website', $issue->project);
		$this->assertSame(7200, $issue->timeSpentSeconds);
		$this->assertSame('textile', $issue->bodyFormat);
		$this->assertSame('https://redmine.example.com/issues/55', $issue->url);
		$this->assertSame(['t' => 'redmine', 'c' => 'r1', 'p' => ['id' => '55']], Ref::decode($issue->ref));
	}

	public function testSearchStatusFilterHonorsShowClosed(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, ['issues' => [], 'total_count' => 0]);
		});
		$this->client->search($this->connection, new IssueQuery());
		$this->assertSame('open', $captured['query']['status_id'], 'closed hidden by default');

		$this->client->search($this->connection, new IssueQuery(showClosed: true));
		$this->assertSame('*', $captured['query']['status_id'], 'all statuses when showing closed');
	}

	public function testSearchByReferenceFetchesIssue(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(200, ['issue' => ['id' => 55, 'subject' => 'X']]);
		});
		$result = $this->client->search($this->connection, new IssueQuery(term: '#55'));
		$this->assertSame('GET', $captured['method']);
		$this->assertStringContainsString('/issues/55.json', $captured['url']);
		$this->assertCount(1, $result->issues);
	}

	public function testAddCommentPutsNotes(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(204, '');
		});
		$this->client->addComment($this->connection, ['id' => '55'], 'My note');
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringContainsString('/issues/55.json', $captured['url']);
		$this->assertSame('My note', json_decode($captured['options']['body'], true)['issue']['notes']);
	}

	public function testLogTimePostsTimeEntry(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, ['time_entry' => ['id' => 1]]);
		});
		$this->client->logTime($this->connection, ['id' => '55'], 5400, 'did work', '2026-07-08');
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/time_entries.json', $captured['url']);
		$entry = json_decode($captured['options']['body'], true)['time_entry'];
		$this->assertSame(55, $entry['issue_id']);
		$this->assertSame(1.5, $entry['hours']);
		$this->assertSame('did work', $entry['comments']);
		$this->assertSame('2026-07-08', $entry['spent_on']);
	}

	public function testUpdateCommentPutsJournalNotes(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(204, '');
		});
		$comment = $this->client->updateComment($this->connection, ['id' => '55'], '99', 'edited');
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringContainsString('/journals/99.json', $captured['url']);
		$this->assertSame('edited', json_decode($captured['options']['body'], true)['journal']['notes']);
		$this->assertSame('edited', $comment->body);
	}

	public function testUpdateIssuePutsIssue(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'PUT') {
				$captured = ['method' => $m, 'url' => $u, 'options' => $o];
				return $this->response(204, '');
			}
			return $this->response(200, ['issue' => ['id' => 55, 'subject' => 'X']]);
		});
		$this->client->updateIssue($this->connection, ['id' => '55'], [
			'title' => 'New', 'status' => '3', 'assignee' => '9',
		]);
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringContainsString('/issues/55.json', $captured['url']);
		$issue = json_decode($captured['options']['body'], true)['issue'];
		$this->assertSame('New', $issue['subject']);
		$this->assertSame(3, $issue['status_id']);
		$this->assertSame(9, $issue['assigned_to_id']);
	}

	public function testBodyFormatMarkdownWhenConfigured(): void {
		$conn = new Connection('r1', 'redmine', 'RM', 'https://redmine.example.com', '', 'key', '', ['textFormat' => 'markdown']);
		$this->http->method('request')->willReturn($this->response(200, ['issues' => [['id' => 1, 'subject' => 'X']], 'total_count' => 1]));
		$result = $this->client->search($conn, new IssueQuery());
		$this->assertSame('markdown', $result->issues[0]->bodyFormat);
	}

	public function testGetTimeRecordsMapsEntriesAndGatesOwnership(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/users/current.json')) {
				return $this->response(200, ['user' => ['id' => 42, 'name' => 'Alice']]);
			}
			return $this->response(200, [
				'time_entries' => [
					['id' => 7, 'user' => ['id' => 42, 'name' => 'Alice'], 'hours' => 1.5,
						'spent_on' => '2026-02-01', 'comments' => 'did work'],
					['id' => 8, 'user' => ['id' => 99, 'name' => 'Bob'], 'hours' => 1,
						'spent_on' => '2026-02-02', 'comments' => 'other work'],
				],
				'total_count' => 2,
			]);
		});
		$records = $this->client->getTimeRecords($this->connection, ['id' => '55']);
		$this->assertCount(2, $records);
		$this->assertSame('Alice', $records[0]->author);
		$this->assertSame(5400, $records[0]->seconds);
		$this->assertSame('2026-02-01', $records[0]->date);
		$this->assertSame('did work', $records[0]->comment);
		// Alice is the connection user → her own entry is editable/deletable.
		$this->assertTrue($records[0]->editable);
		$this->assertTrue($records[0]->deletable);
		// Bob's entry is not owned by the connection user.
		$this->assertFalse($records[1]->editable);
		$this->assertFalse($records[1]->deletable);
	}

	public function testFetchFileResolvesRelativeAttachment(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, 'BYTES');
		});
		$file = $this->client->fetchFile($this->connection, ['id' => '55'], '/attachments/download/9/pic.png');
		$this->assertSame('https://redmine.example.com/attachments/download/9/pic.png', $captured['url']);
		$this->assertSame('key', $captured['options']['headers']['X-Redmine-API-Key']);
		$this->assertSame('BYTES', $file['body']);
	}

	public function testFetchFileRejectsForeignHost(): void {
		$this->expectException(TrackerException::class);
		$this->client->fetchFile($this->connection, ['id' => '55'], 'https://evil.example/x.png');
	}
}
