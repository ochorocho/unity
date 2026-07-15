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

	public function testAddCommentConvertsMentionToken(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/markdown')) {
				return $this->response(200, ['html' => '<p>x</p>']);
			}
			$captured = $o;
			return $this->response(201, ['id' => 5, 'author' => ['name' => 'A'], 'body' => 'x', 'created_at' => '2026-03-01T00:00:00Z']);
		});
		$this->client->addComment($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app'], 'ping @"user/janedoe" now');
		$this->assertSame('ping @janedoe now', json_decode($captured['body'], true)['body']);
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
			if (str_contains($u, '/user')) {
				// REST GET /user → the connection's own account.
				return $this->response(200, ['username' => 'alice']);
			}
			return $this->response(200, [
				['id' => 1, 'author' => ['name' => 'A', 'username' => 'alice'], 'body' => 'hello', 'created_at' => 'x'],
				['id' => 2, 'author' => ['name' => 'B', 'username' => 'bob'], 'body' => '', 'created_at' => 'y'],
				['id' => 3, 'system' => true, 'body' => 'changed status'],
			]);
		});
		$comments = $this->client->getComments($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app']);
		$this->assertCount(2, $comments, 'system notes filtered out');
		$this->assertSame('<p>hello</p>', $comments[0]->renderedBody);
		$this->assertNull($comments[1]->renderedBody, 'empty body → no render');
		// alice authored the first note → editable/deletable; Bob's is not.
		$this->assertTrue($comments[0]->editable);
		$this->assertTrue($comments[0]->deletable);
		$this->assertFalse($comments[1]->editable);
		$this->assertFalse($comments[1]->deletable);
	}

	public function testDeleteCommentDeletesNote(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(200, '');
		});
		$this->client->deleteComment($this->connection, ['project' => '42', 'iid' => '7', 'path' => 'group/app'], '5');
		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/projects/42/issues/7/notes/5', $captured['url']);
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

	public function testGetCreateMetaPassesSearchQuery(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, [['id' => 42, 'name_with_namespace' => 'Group / App']]);
		});
		$this->client->getCreateMeta($this->connection, 'app');
		$this->assertSame('app', $captured['options']['query']['search']);
	}

	public function testGetCreateMetaDescribesProjectFields(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/milestones')) {
				return $this->response(200, [['id' => 12, 'title' => 'Sprint 1']]);
			}
			return $this->response(200, []);
		});
		$meta = $this->client->getCreateMeta($this->connection, null, '42', null);
		$byId = [];
		foreach ($meta['fields'] as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertSame('select', $byId['milestone_id']['type']);
		$this->assertSame([['id' => '12', 'name' => 'Sprint 1']], $byId['milestone_id']['options']);
		$this->assertSame('date', $byId['due_date']['type']);
		$this->assertSame('int', $byId['weight']['type']);
		$this->assertSame('bool', $byId['confidential']['type']);
	}

	public function testCreateIssueEncodesDynamicFields(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/issues')) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['id' => 1, 'iid' => 9, 'project_id' => 42, 'title' => 'New', 'references' => ['full' => 'g/a#9'], 'web_url' => 'u']);
			}
			return $this->response(200, []);
		});
		$this->client->createIssue($this->connection, [
			'project' => '42', 'title' => 'New',
			'fields' => ['milestone_id' => '12', 'weight' => '3', 'due_date' => '2026-08-01', 'confidential' => true],
		]);
		$this->assertSame(12, $captured['milestone_id']);
		$this->assertSame(3, $captured['weight']);
		$this->assertSame('2026-08-01', $captured['due_date']);
		$this->assertTrue($captured['confidential']);
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

	public function testSearchAssigneesQueriesProjectMembers(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, [
				['id' => 7, 'name' => 'Alice', 'username' => 'alice'],
				['id' => 8, 'name' => 'Bob', 'username' => 'bob'],
			]);
		});
		$out = $this->client->searchAssignees($this->connection, ['refParts' => ['project' => '12', 'iid' => '5']], 'al');
		$this->assertStringContainsString('/projects/12/members/all', $captured['url']);
		$this->assertSame('al', $captured['options']['query']['query']);
		$this->assertSame([
			['id' => '7', 'name' => 'Alice', 'mention' => 'alice'],
			['id' => '8', 'name' => 'Bob', 'mention' => 'bob'],
		], $out);
	}

	public function testCreateIssueEncodesAssignee(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = json_decode($o['body'], true);
			return $this->response(201, ['id' => 1, 'iid' => 9, 'project_id' => 42, 'title' => 'New', 'references' => ['full' => 'g/a#9'], 'web_url' => 'u']);
		});
		$this->client->createIssue($this->connection, ['project' => '42', 'title' => 'New', 'assignee' => '7']);
		$this->assertSame([7], $captured['assignee_ids']);
	}

	public function testGetRelationTypesOffersOnlyRelatesOnCommunityEdition(): void {
		// Community Edition (enterprise:false) doesn't support blocking links.
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			return $this->response(200, ['version' => '16.0.0', 'enterprise' => false]);
		});
		$ids = array_column($this->client->getRelationTypes($this->connection, ['project' => '12', 'iid' => '5']), 'id');
		$this->assertSame(['relates_to'], $ids);
	}

	public function testGetRelationTypesIncludesBlockingOnEnterprise(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			return $this->response(200, ['version' => '16.0.0-ee', 'enterprise' => true]);
		});
		$ids = array_column($this->client->getRelationTypes($this->connection, ['project' => '12', 'iid' => '5']), 'id');
		$this->assertSame(['relates_to', 'blocks', 'is_blocked_by'], $ids);
	}

	public function testAddRelationIsBlockedByPostsBlocksFromTarget(): void {
		// GitLab's is_blocked_by POST is buggy, so create `blocks` from the target.
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/links')) {
				$captured = ['url' => $u, 'options' => $o];
				return $this->response(201, ['link_type' => 'blocks']);
			}
			// read-back on the current issue's links
			return $this->response(200, [
				['iid' => 9, 'project_id' => 34, 'title' => 'Blocker', 'state' => 'opened',
					'web_url' => 'https://gitlab.com/grp/other/-/issues/9', 'references' => ['full' => 'grp/other#9'],
					'link_type' => 'is_blocked_by', 'issue_link_id' => 303],
			]);
		});

		$relation = $this->client->addRelation(
			$this->connection,
			['project' => '12', 'iid' => '5', 'path' => 'grp/app'],
			'is_blocked_by',
			['project' => '34', 'iid' => '9', 'path' => 'grp/other'],
		);

		// POST goes to the TARGET issue's links, creating a `blocks` link back to current.
		$this->assertStringContainsString('/projects/34/issues/9/links', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('blocks', $body['link_type']);
		$this->assertSame('12', $body['target_project_id']);
		$this->assertSame('5', $body['target_issue_iid']);
		$this->assertSame('303', $relation->id);
	}

	public function testGetRelationsMapsLinks(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $u;
			return $this->response(200, [
				['iid' => 7, 'project_id' => 12, 'title' => 'Sibling', 'state' => 'opened',
					'web_url' => 'https://gitlab.com/grp/app/-/issues/7', 'references' => ['full' => 'grp/app#7'],
					'link_type' => 'relates_to', 'issue_link_id' => 100],
				['iid' => 9, 'project_id' => 34, 'title' => 'Blocker', 'state' => 'closed',
					'web_url' => 'https://gitlab.com/grp/other/-/issues/9', 'references' => ['full' => 'grp/other#9'],
					'link_type' => 'is_blocked_by', 'issue_link_id' => 101],
			]);
		});

		$relations = $this->client->getRelations($this->connection, ['project' => '12', 'iid' => '5', 'path' => 'grp/app']);

		$this->assertStringContainsString('/projects/12/issues/5/links', $captured);
		$this->assertCount(2, $relations);
		$this->assertSame('100', $relations[0]->id);
		$this->assertSame('relates_to', $relations[0]->type);
		$this->assertSame('Relates to', $relations[0]->typeLabel);
		$this->assertSame('#7', $relations[0]->targetDisplayId);
		$this->assertSame('Sibling', $relations[0]->targetTitle);
		$this->assertSame('opened', $relations[0]->targetStatus);
		$this->assertSame('https://gitlab.com/grp/app/-/issues/7', $relations[0]->targetUrl);
		$this->assertSame(
			['t' => 'gitlab', 'c' => 'g1', 'p' => ['project' => '12', 'iid' => '7', 'path' => 'grp/app']],
			Ref::decode($relations[0]->targetRef),
		);
		$this->assertSame('101', $relations[1]->id);
		$this->assertSame('is_blocked_by', $relations[1]->type);
		$this->assertSame('Is blocked by', $relations[1]->typeLabel);
		$this->assertSame('grp/other', Ref::decode($relations[1]->targetRef)['p']['path']);
	}

	public function testAddRelationPostsLinkAndReadsBack(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/links')) {
				$captured = ['method' => $m, 'url' => $u, 'options' => $o];
				return $this->response(201, ['link_type' => 'blocks']);
			}
			// getRelations read-back
			return $this->response(200, [
				['iid' => 9, 'project_id' => 34, 'title' => 'Blocker', 'state' => 'opened',
					'web_url' => 'https://gitlab.com/grp/other/-/issues/9', 'references' => ['full' => 'grp/other#9'],
					'link_type' => 'blocks', 'issue_link_id' => 202],
			]);
		});

		$relation = $this->client->addRelation(
			$this->connection,
			['project' => '12', 'iid' => '5', 'path' => 'grp/app'],
			'blocks',
			['project' => '34', 'iid' => '9', 'path' => 'grp/other'],
		);

		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/projects/12/issues/5/links', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('34', $body['target_project_id']);
		$this->assertSame('9', $body['target_issue_iid']);
		$this->assertSame('blocks', $body['link_type']);
		// The read-back resolves the issue_link_id needed for later deletion.
		$this->assertSame('202', $relation->id);
		$this->assertSame('Blocks', $relation->typeLabel);
		$this->assertSame('#9', $relation->targetDisplayId);
	}

	public function testAddRelationSynthesizesWhenReadBackEmpty(): void {
		// A successful POST whose read-back doesn't surface the link must not error —
		// return a best-effort relation; the UI refetches the authoritative list.
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if ($m === 'POST' && str_contains($u, '/links')) {
				return $this->response(201, ['link_type' => 'relates_to']);
			}
			return $this->response(200, []); // read-back empty
		});

		$relation = $this->client->addRelation(
			$this->connection,
			['project' => '12', 'iid' => '5', 'path' => 'grp/app'],
			'relates_to',
			['project' => '34', 'iid' => '9', 'path' => 'grp/other'],
		);

		$this->assertSame('relates_to', $relation->type);
		$this->assertSame('#9', $relation->targetDisplayId);
		$this->assertSame(
			['t' => 'gitlab', 'c' => 'g1', 'p' => ['project' => '34', 'iid' => '9', 'path' => 'grp/other']],
			Ref::decode($relation->targetRef),
		);
	}

	public function testDeleteRelation(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(204, '');
		});
		$this->client->deleteRelation($this->connection, ['project' => '12', 'iid' => '5', 'path' => 'grp/app'], '100');
		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/projects/12/issues/5/links/100', $captured['url']);
	}
}
