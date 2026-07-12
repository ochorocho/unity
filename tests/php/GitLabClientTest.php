<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Connection;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Service\Tracker\GitLabClient;
use OCA\Unity\Service\Tracker\TrackerException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GitLabClientTest extends TestCase {

	private IClient&MockObject $http;
	private GitLabClient $client;
	private Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->http = $this->createMock(IClient::class);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->http);
		$this->client = new GitLabClient($service, new NullLogger());
		$this->connection = new Connection('g1', 'gitlab', 'GL', 'https://gitlab.com', '', 'tok');
	}

	private function response(int $status, array|string $body, array $headers = []): IResponse&MockObject {
		$r = $this->createMock(IResponse::class);
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn(is_array($body) ? (string)json_encode($body) : $body);
		$r->method('getHeader')->willReturnCallback(static fn (string $k): string => $headers[$k] ?? '');
		return $r;
	}

	public function testSearchMapsIssuesAndCursor(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, [[
				'id' => 100, 'iid' => 7, 'project_id' => 42,
				'title' => 'Broken', 'description' => 'desc', 'state' => 'opened',
				'author' => ['name' => 'Bob'], 'assignee' => ['name' => 'Alice'],
				'labels' => ['bug'], 'references' => ['full' => 'group/app#7'],
				'web_url' => 'https://gitlab.com/group/app/-/issues/7',
				'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-02-01T00:00:00Z',
				'time_stats' => ['total_time_spent' => 3600],
			]], ['X-Next-Page' => '2']);
		});

		$result = $this->client->search($this->connection, new IssueQuery(assignedToMe: true));

		$this->assertStringContainsString('/api/v4/issues', $captured['url']);
		$this->assertSame('assigned_to_me', $captured['options']['query']['scope']);
		$this->assertSame('tok', $captured['options']['headers']['PRIVATE-TOKEN']);
		$this->assertSame('2', $result->nextCursor);
		$issue = $result->issues[0];
		$this->assertSame('#7', $issue->displayId);
		$this->assertSame('group/app', $issue->project);
		$this->assertSame('Alice', $issue->assignee);
		$this->assertSame(['bug'], $issue->labels);
		$this->assertSame(3600, $issue->timeSpentSeconds);
		$this->assertSame('markdown', $issue->bodyFormat);
		$this->assertSame(['t' => 'gitlab', 'c' => 'g1', 'p' => ['project' => '42', 'iid' => '7', 'path' => 'group/app']], Ref::decode($issue->ref));
	}

	public function testSearchStateHonorsShowClosed(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, [], []);
		});
		$this->client->search($this->connection, new IssueQuery());
		$this->assertSame('opened', $captured['query']['state'], 'closed hidden by default');

		$this->client->search($this->connection, new IssueQuery(showClosed: true));
		$this->assertSame('all', $captured['query']['state'], 'all states when showing closed');
	}

	public function testGetTimeRecordsViaGraphQL(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (!str_contains($u, '/api/graphql')) {
				// REST GET /user → the connection's own account.
				return $this->response(200, ['username' => 'alice']);
			}
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['data' => ['project' => ['issue' => ['timelogs' => ['nodes' => [
				['id' => 'gid://gitlab/Timelog/5', 'timeSpent' => 3600, 'spentAt' => '2026-02-01T00:00:00Z', 'summary' => 'work', 'user' => ['name' => 'Alice', 'username' => 'alice']],
				['id' => 'gid://gitlab/Timelog/6', 'timeSpent' => 1800, 'spentAt' => '2026-02-02T00:00:00Z', 'summary' => 'other', 'user' => ['name' => 'Bob', 'username' => 'bob']],
			]]]]]]);
		});
		$records = $this->client->getTimeRecords($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app']);
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/api/graphql', $captured['url']);
		$this->assertStringStartsWith('Bearer ', $captured['options']['headers']['Authorization']);
		$this->assertCount(2, $records);
		$this->assertSame('Alice', $records[0]->author);
		$this->assertSame(3600, $records[0]->seconds);
		$this->assertSame('work', $records[0]->comment);
		// GitLab can't edit a timelog in place, so editable is always false.
		$this->assertFalse($records[0]->editable);
		// alice owns the first timelog → deletable; Bob's is not deletable.
		$this->assertTrue($records[0]->deletable);
		$this->assertFalse($records[1]->deletable);
	}

	public function testGetTimeRecordsEmptyWithoutPath(): void {
		$this->assertSame([], $this->client->getTimeRecords($this->connection, ['project' => '42', 'iid' => '7']));
	}

	public function testSearchByReferenceFetchesIssue(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(200, ['id' => 100, 'iid' => 7, 'project_id' => 42, 'title' => 'X', 'references' => ['full' => 'group/app#7'], 'web_url' => 'u']);
		});
		$result = $this->client->search($this->connection, new IssueQuery(term: 'group/app #7'));
		$this->assertSame('GET', $captured['method']);
		$this->assertStringContainsString('/projects/group%2Fapp/issues/7', $captured['url']);
		$this->assertCount(1, $result->issues);
	}

	public function testAddCommentPostsBody(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/markdown')) {
				return $this->response(200, ['html' => '<p>Hi</p>']);
			}
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, ['id' => 5, 'author' => ['name' => 'Alice'], 'body' => 'Hi', 'created_at' => '2026-03-01T00:00:00Z']);
		});
		$comment = $this->client->addComment($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app'], 'Hi');
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/projects/42/issues/7/notes', $captured['url']);
		$this->assertSame('Hi', json_decode($captured['options']['body'], true)['body']);
		$this->assertSame('Alice', $comment->author);
		$this->assertSame('<p>Hi</p>', $comment->renderedBody);
	}

	public function testFetchFileResolvesRelativeUploadViaApi(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, 'BYTES', ['Content-Type' => 'image/png']);
		});
		$file = $this->client->fetchFile($this->connection, ['project' => '42', 'iid' => '7'], '/uploads/deadbeef/pic.png');
		$this->assertStringContainsString('/api/v4/projects/42/uploads/deadbeef/pic.png', $captured['url']);
		$this->assertSame('tok', $captured['options']['headers']['PRIVATE-TOKEN']);
		$this->assertArrayNotHasKey('Content-Type', $captured['options']['headers']);
		$this->assertSame('BYTES', $file['body']);
		$this->assertSame('image/png', $file['contentType']);
	}

	public function testFetchFileRejectsForeignHost(): void {
		$this->expectException(TrackerException::class);
		$this->client->fetchFile($this->connection, ['project' => '42'], 'https://evil.example/x.png');
	}

	public function testUpdateCommentPutsNote(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/markdown')) {
				return $this->response(200, ['html' => '<p>x</p>']);
			}
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['id' => 5, 'author' => ['name' => 'Alice'], 'body' => 'x']);
		});
		$this->client->updateComment($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app'], '5', 'x');
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringContainsString('/projects/42/issues/7/notes/5', $captured['url']);
		$this->assertSame('x', json_decode($captured['options']['body'], true)['body']);
	}

	public function testGetIssueRendersDescriptionHtml(): void {
		$markdownReq = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$markdownReq) {
			if (str_contains($u, '/markdown')) {
				$markdownReq = ['method' => $m, 'url' => $u, 'options' => $o];
				return $this->response(200, ['html' => '<details><summary>More</summary>hidden</details>']);
			}
			return $this->response(200, [
				'id' => 100, 'iid' => 7, 'project_id' => 42, 'title' => 'X',
				'description' => '<details><summary>More</summary>hidden</details>',
				'references' => ['full' => 'group/app#7'], 'web_url' => 'u',
			]);
		});
		$issue = $this->client->getIssue($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app']);
		// Raw Markdown kept for editing; rendered HTML supplied for display.
		$this->assertStringContainsString('<details>', $issue->description);
		$this->assertSame('<details><summary>More</summary>hidden</details>', $issue->renderedDescription);
		$this->assertSame('POST', $markdownReq['method']);
		$this->assertStringContainsString('/api/v4/markdown', $markdownReq['url']);
		$payload = json_decode($markdownReq['options']['body'], true);
		$this->assertTrue($payload['gfm']);
		$this->assertSame('group/app', $payload['project']);
	}

	public function testGetIssueSkipsMarkdownForEmptyDescription(): void {
		$calls = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$calls) {
			$calls[] = $u;
			return $this->response(200, ['id' => 100, 'iid' => 7, 'project_id' => 42, 'title' => 'X', 'description' => '', 'references' => ['full' => 'group/app#7'], 'web_url' => 'u']);
		});
		$issue = $this->client->getIssue($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app']);
		$this->assertNull($issue->renderedDescription);
		$this->assertCount(1, $calls, 'no markdown render request for an empty description');
	}

	public function testGetCommentsRenderBodies(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/markdown')) {
				$body = json_decode($o['body'], true);
				return $this->response(200, ['html' => '<p>' . $body['text'] . '</p>']);
			}
			return $this->response(200, [
				['id' => 1, 'author' => ['name' => 'A'], 'body' => 'hello', 'created_at' => 'x'],
				['id' => 2, 'author' => ['name' => 'B'], 'body' => '', 'created_at' => 'y'],
				['id' => 3, 'system' => true, 'body' => 'changed status'],
			]);
		});
		$comments = $this->client->getComments($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app']);
		$this->assertCount(2, $comments, 'system notes filtered out');
		$this->assertSame('<p>hello</p>', $comments[0]->renderedBody);
		$this->assertNull($comments[1]->renderedBody, 'empty body → no render');
	}

	public function testUpdateIssuePutsFields(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['id' => 1, 'iid' => 7, 'project_id' => 42, 'title' => 'New', 'state' => 'closed', 'references' => ['full' => 'g/a#7']]);
		});
		$this->client->updateIssue($this->connection, ['project' => '42', 'iid' => '7'], [
			'title' => 'New', 'status' => 'closed', 'assignee' => '5', 'labels' => ['bug', 'ui'],
		]);
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringContainsString('/projects/42/issues/7', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('New', $body['title']);
		$this->assertSame('close', $body['state_event']);
		$this->assertSame([5], $body['assignee_ids']);
		$this->assertSame('bug,ui', $body['labels']);
	}

	public function testLogTimePostsSpentTime(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, ['id' => 1]);
		});
		$this->client->logTime($this->connection, ['project' => '42', 'iid' => '7'], 3600, 'work', null);
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/projects/42/issues/7/add_spent_time', $captured['url']);
		$this->assertSame('3600s', $captured['options']['query']['duration']);
	}

	public function testGetCreateMetaListsProjects(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, [
				['id' => 42, 'name_with_namespace' => 'Group / App'],
				['id' => 7, 'name_with_namespace' => 'Group / Lib'],
			]);
		});
		$meta = $this->client->getCreateMeta($this->connection);
		$this->assertStringContainsString('/api/v4/projects', $captured['url']);
		$this->assertSame('30', $captured['options']['query']['min_access_level']);
		$this->assertCount(2, $meta['projects']);
		$this->assertSame('42', $meta['projects'][0]['id']);
		$this->assertSame('Group / App', $meta['projects'][0]['name']);
		$this->assertFalse($meta['capabilities']['type']);
	}

	public function testCreateIssuePostsTitleAndDescription(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, ['id' => 1, 'iid' => 9, 'project_id' => 42, 'title' => 'New', 'references' => ['full' => 'group/app#9'], 'web_url' => 'u']);
		});
		$issue = $this->client->createIssue($this->connection, ['project' => '42', 'title' => 'New', 'description' => 'Body']);
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/projects/42/issues', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('New', $body['title']);
		$this->assertSame('Body', $body['description']);
		$this->assertSame('#9', $issue->displayId);
	}
}
