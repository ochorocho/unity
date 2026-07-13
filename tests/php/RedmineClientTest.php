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

	public function testGetAttachmentsMapsList(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, ['issue' => ['id' => 55, 'attachments' => [[
				'id' => 9, 'filename' => 'diagram.png', 'filesize' => 2048, 'content_type' => 'image/png',
				'content_url' => 'https://redmine.example.com/attachments/download/9/diagram.png',
				'thumbnail_url' => 'https://redmine.example.com/attachments/thumbnail/9',
				'author' => ['name' => 'Alice'], 'created_on' => '2026-03-01T00:00:00Z',
			]]]]);
		});
		$attachments = $this->client->getAttachments($this->connection, ['id' => '55']);
		$this->assertSame('attachments', $captured['options']['query']['include']);
		$this->assertStringContainsString('/issues/55.json', $captured['url']);
		$this->assertCount(1, $attachments);
		$this->assertSame('diagram.png', $attachments[0]->filename);
		$this->assertSame('image/png', $attachments[0]->mimeType);
		$this->assertSame(2048, $attachments[0]->size);
		$this->assertSame('https://redmine.example.com/attachments/download/9/diagram.png', $attachments[0]->src);
		$this->assertSame('https://redmine.example.com/attachments/thumbnail/9', $attachments[0]->thumbnailSrc);
		$this->assertSame('Alice', $attachments[0]->author);
	}

	public function testUploadAttachmentTwoStep(): void {
		$calls = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$calls) {
			$calls[] = ['method' => $m, 'url' => $u, 'options' => $o];
			if (str_contains($u, '/uploads.json')) {
				return $this->response(201, ['upload' => ['token' => 'tok-123']]);
			}
			if ($m === 'PUT') {
				return $this->response(204, '');
			}
			// getAttachments re-fetch after the PUT
			return $this->response(200, ['issue' => ['id' => 55, 'attachments' => [[
				'id' => 12, 'filename' => 'notes.pdf', 'filesize' => 100, 'content_type' => 'application/pdf',
				'content_url' => 'https://redmine.example.com/attachments/download/12/notes.pdf',
			]]]]);
		});

		$att = $this->client->uploadAttachment($this->connection, ['id' => '55'], 'notes.pdf', 'application/pdf', 'BYTES');

		$upload = $calls[0];
		$this->assertSame('POST', $upload['method']);
		$this->assertStringContainsString('/uploads.json?filename=notes.pdf', $upload['url']);
		$this->assertSame('application/octet-stream', $upload['options']['headers']['Content-Type']);
		$this->assertSame('BYTES', $upload['options']['body']);

		$put = $calls[1];
		$this->assertSame('PUT', $put['method']);
		$body = json_decode($put['options']['body'], true);
		$this->assertSame('tok-123', $body['issue']['uploads'][0]['token']);
		$this->assertSame('notes.pdf', $body['issue']['uploads'][0]['filename']);
		$this->assertSame('application/pdf', $body['issue']['uploads'][0]['content_type']);

		$this->assertSame('notes.pdf', $att->filename);
		$this->assertSame(100, $att->size);
	}

	public function testDeleteAttachment(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(204, '');
		});

		$this->client->deleteAttachment($this->connection, ['id' => '55'], '9');

		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/attachments/9.json', $captured['url']);
		$this->assertSame('key', $captured['options']['headers']['X-Redmine-API-Key']);
	}

	public function testGetCreateMetaAttachesGlobalTrackers(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/trackers.json')) {
				return $this->response(200, ['trackers' => [['id' => 1, 'name' => 'Bug'], ['id' => 2, 'name' => 'Feature']]]);
			}
			return $this->response(200, ['projects' => [['id' => 3, 'name' => 'Website']], 'total_count' => 1]);
		});
		$meta = $this->client->getCreateMeta($this->connection);
		$this->assertCount(1, $meta['projects']);
		$this->assertSame('3', $meta['projects'][0]['id']);
		$this->assertCount(2, $meta['projects'][0]['types'], 'global trackers attached to the project');
		$this->assertTrue($meta['capabilities']['type']);
	}

	public function testCreateIssuePostsSubjectAndTracker(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['issue' => ['id' => 88, 'subject' => 'New', 'project' => ['name' => 'Website']]]);
		});
		$issue = $this->client->createIssue($this->connection, ['project' => '3', 'type' => '2', 'title' => 'New', 'description' => 'Body']);
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/issues.json', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame(3, $body['issue']['project_id']);
		$this->assertSame('New', $body['issue']['subject']);
		$this->assertSame(2, $body['issue']['tracker_id']);
		$this->assertSame('#88', $issue->displayId);
	}

	public function testGetCreateMetaDescribesProjectFields(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/custom_fields.json')) {
				return $this->response(403, ['error' => 'forbidden']); // non-admin
			}
			if (str_contains($u, '/issue_priorities.json')) {
				return $this->response(200, ['issue_priorities' => [['id' => 4, 'name' => 'Should have']]]);
			}
			if (str_contains($u, '/issue_categories.json')) {
				return $this->response(200, ['issue_categories' => [['id' => 9, 'name' => 'Backend']]]);
			}
			if (str_contains($u, '/versions.json')) {
				return $this->response(200, ['versions' => [
					['id' => 100, 'name' => 'v1', 'status' => 'open'],
					['id' => 101, 'name' => 'old', 'status' => 'closed'],
				]]);
			}
			if (str_contains($u, '/projects/27.json')) {
				return $this->response(200, ['project' => ['id' => 27, 'issue_custom_fields' => [['id' => 4, 'name' => 'TYPO3 Version']]]]);
			}
			return $this->response(200, []);
		});
		$meta = $this->client->getCreateMeta($this->connection, null, '27', '1');
		$byId = [];
		foreach ($meta['fields'] as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertSame('select', $byId['priority_id']['type']);
		$this->assertSame([['id' => '4', 'name' => 'Should have']], $byId['priority_id']['options']);
		// Closed versions are filtered out.
		$this->assertSame([['id' => '100', 'name' => 'v1']], $byId['fixed_version_id']['options']);
		$this->assertSame('date', $byId['due_date']['type']);
		$this->assertSame('bool', $byId['is_private']['type']);
		// Non-admin custom field degrades to a free-text descriptor.
		$this->assertSame('text', $byId['cf_4']['type']);
		$this->assertSame('TYPO3 Version', $byId['cf_4']['name']);
	}

	public function testCreateIssueEncodesDynamicFields(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/issues.json')) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['issue' => ['id' => 7, 'subject' => 'T', 'project' => ['name' => 'P']]]);
			}
			return $this->response(200, []);
		});
		$this->client->createIssue($this->connection, [
			'project' => '27', 'type' => '1', 'title' => 'T', 'description' => 'D',
			'fields' => [
				'priority_id' => '4', 'due_date' => '2026-08-01', 'done_ratio' => '50',
				'estimated_hours' => '2.5', 'is_private' => true, 'cf_4' => '15',
			],
		]);
		$issue = $captured['issue'];
		$this->assertSame(4, $issue['priority_id']);
		$this->assertSame('2026-08-01', $issue['due_date']);
		$this->assertSame(50, $issue['done_ratio']);
		$this->assertSame(2.5, $issue['estimated_hours']);
		$this->assertTrue($issue['is_private']);
		$this->assertSame([['id' => 4, 'value' => '15']], $issue['custom_fields']);
	}

	public function testGetEditMetaCarriesCurrentFieldValues(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/issue_statuses.json')) {
				return $this->response(200, ['issue_statuses' => [['id' => 1, 'name' => 'New']]]);
			}
			if (preg_match('#/issues/55\.json#', $u) === 1) {
				return $this->response(200, ['issue' => [
					'id' => 55, 'project' => ['id' => 27], 'tracker' => ['id' => 1],
					'priority' => ['id' => 4, 'name' => 'Should have'],
					'due_date' => '2026-08-01',
					'custom_fields' => [['id' => 4, 'name' => 'TYPO3 Version', 'value' => '15']],
				]]);
			}
			if (str_contains($u, '/memberships.json')) {
				return $this->response(200, ['memberships' => [['user' => ['id' => 2, 'name' => 'Alice']]]]);
			}
			if (str_contains($u, '/custom_fields.json')) {
				return $this->response(403, ['error' => 'forbidden']);
			}
			if (str_contains($u, '/issue_priorities.json')) {
				return $this->response(200, ['issue_priorities' => [['id' => 4, 'name' => 'Should have']]]);
			}
			return $this->response(200, []);
		});
		$meta = $this->client->getEditMeta($this->connection, ['id' => '55']);
		$byId = [];
		foreach ($meta['fields'] as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertSame('4', $byId['priority_id']['value']);
		$this->assertSame('2026-08-01', $byId['due_date']['value']);
		$this->assertSame('15', $byId['cf_4']['value']);
	}
}
