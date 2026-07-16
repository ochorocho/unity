<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Connection;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Service\Tracker\GithubClient;
use OCA\Unity\Service\Tracker\TrackerException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GithubClientTest extends TestCase {

	private IClient&MockObject $http;
	private GithubClient $client;
	private Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->http = $this->createMock(IClient::class);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->http);
		$this->client = new GithubClient($service, new NullLogger());
		$this->connection = new Connection('h1', 'github', 'GH', 'https://api.github.com', '', 'tok');
	}

	private function response(int $status, array|string $body, array $headers = []): IResponse&MockObject {
		$r = $this->createMock(IResponse::class);
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn(is_array($body) ? (string)json_encode($body) : $body);
		$r->method('getHeader')->willReturnCallback(static fn (string $k): string => $headers[$k] ?? '');
		return $r;
	}

	public function testSearchFiltersPullRequestsAndMaps(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, ['items' => [
				[
					'id' => 1, 'number' => 10, 'title' => 'A bug', 'body' => 'text', 'state' => 'open',
					'user' => ['login' => 'octocat'], 'assignee' => ['login' => 'hubot'],
					'labels' => [['name' => 'bug']],
					'repository_url' => 'https://api.github.com/repos/octocat/Hello-World',
					'html_url' => 'https://github.com/octocat/Hello-World/issues/10',
					'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-02-01T00:00:00Z',
				],
				[
					'id' => 2, 'number' => 11, 'title' => 'A PR', 'pull_request' => ['url' => 'x'],
					'repository_url' => 'https://api.github.com/repos/octocat/Hello-World',
				],
			]], ['Link' => '<https://api.github.com/search/issues?q=x&page=2>; rel="next"']);
		});

		$result = $this->client->search($this->connection, new IssueQuery(assignedToMe: true));

		$this->assertStringContainsString('/search/issues', $captured['url']);
		$this->assertStringContainsString('is:issue', $captured['options']['query']['q']);
		$this->assertStringContainsString('assignee:@me', $captured['options']['query']['q']);
		$this->assertCount(1, $result->issues, 'pull requests must be filtered out');
		$this->assertSame('2', $result->nextCursor);
		$issue = $result->issues[0];
		$this->assertSame('#10', $issue->displayId);
		$this->assertSame('octocat/Hello-World', $issue->project);
		$this->assertSame('hubot', $issue->assignee);
		$this->assertSame(['bug'], $issue->labels);
		$this->assertSame(['t' => 'github', 'c' => 'h1', 'p' => ['owner' => 'octocat', 'repo' => 'Hello-World', 'number' => '10']], Ref::decode($issue->ref));
	}

	public function testSearchStateQualifierHonorsShowClosed(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, ['items' => []]);
		});
		$this->client->search($this->connection, new IssueQuery(assignedToMe: true));
		$this->assertStringContainsString('state:open', $captured['query']['q'], 'closed hidden by default');

		$this->client->search($this->connection, new IssueQuery(assignedToMe: true, showClosed: true));
		$this->assertStringNotContainsString('state:open', $captured['query']['q'], 'all states when showing closed');
	}

	public function testUpdateCommentPatchesComment(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['id' => 5, 'user' => ['login' => 'octo'], 'body' => 'x']);
		});
		$this->client->updateComment($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '1'], '5', 'x');
		$this->assertSame('PATCH', $captured['method']);
		$this->assertStringContainsString('/repos/o/r/issues/comments/5', $captured['url']);
		$this->assertSame('x', json_decode($captured['options']['body'], true)['body']);
	}

	public function testGetCommentsGatesOwnership(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/user')) {
				// GET /user → the connection's own account.
				return $this->response(200, ['login' => 'Octo']);
			}
			return $this->response(200, [
				['id' => 1, 'user' => ['login' => 'octo'], 'body' => 'hello', 'created_at' => 'x', 'html_url' => 'h1'],
				['id' => 2, 'user' => ['login' => 'mona'], 'body' => 'world', 'created_at' => 'y', 'html_url' => 'h2'],
			]);
		});
		$comments = $this->client->getComments($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '10']);
		$this->assertCount(2, $comments);
		// octo authored the first comment (login match is case-insensitive); mona did not.
		$this->assertTrue($comments[0]->editable);
		$this->assertTrue($comments[0]->deletable);
		$this->assertFalse($comments[1]->editable);
		$this->assertFalse($comments[1]->deletable);
	}

	public function testGetCommentsRequestsRenderedHtmlAndKeepsRawBody(): void {
		$accept = '';
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$accept) {
			if (str_contains($u, '/user')) {
				return $this->response(200, ['login' => 'Octo']);
			}
			$accept = $o['headers']['Accept'] ?? '';
			return $this->response(200, [[
				'id' => 1, 'user' => ['login' => 'octo'],
				'body' => 'hi @mona',
				'body_html' => '<p>hi <a class="user-mention" href="https://github.com/mona">@mona</a></p>',
				'created_at' => 'x', 'html_url' => 'h1',
			]]);
		});
		$comments = $this->client->getComments($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '10']);
		$this->assertSame('application/vnd.github.full+json', $accept, 'asks GitHub for the rendered body');
		$this->assertSame('hi @mona', $comments[0]->body, 'raw markdown kept for editing');
		$this->assertStringContainsString('class="user-mention"', (string)$comments[0]->renderedBody);
	}

	public function testDeleteCommentDeletes(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(204, '');
		});
		$this->client->deleteComment($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '1'], '5');
		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/repos/o/r/issues/comments/5', $captured['url']);
	}

	public function testUpdateIssuePatchesFields(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['id' => 1, 'number' => 10, 'title' => 'New', 'state' => 'closed', 'repository_url' => 'https://api.github.com/repos/o/r']);
		});
		$this->client->updateIssue($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '10'], [
			'title' => 'New', 'status' => 'closed', 'assignee' => 'hubot', 'labels' => ['bug'],
		]);
		$this->assertSame('PATCH', $captured['method']);
		$this->assertStringContainsString('/repos/o/r/issues/10', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('New', $body['title']);
		$this->assertSame('closed', $body['state']);
		$this->assertSame(['hubot'], $body['assignees']);
		$this->assertSame(['bug'], $body['labels']);
	}

	public function testSearchByReferenceFetchesIssue(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(200, ['id' => 1, 'number' => 10, 'title' => 'X', 'repository_url' => 'https://api.github.com/repos/octo/repo', 'html_url' => 'h']);
		});
		$result = $this->client->search($this->connection, new IssueQuery(term: 'octo/repo #10'));
		$this->assertSame('GET', $captured['method']);
		$this->assertStringContainsString('/repos/octo/repo/issues/10', $captured['url']);
		$this->assertCount(1, $result->issues);
	}

	public function testNoTimeTracking(): void {
		$this->assertFalse($this->client->supportsTimeTracking());
		$this->expectException(TrackerException::class);
		$this->client->logTime($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '1'], 3600, '', null);
	}

	public function testFetchFileAllowsGithubUserImagesWithBearer(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, 'IMG', ['Content-Type' => 'image/jpeg']);
		});
		$file = $this->client->fetchFile(
			$this->connection,
			['owner' => 'o', 'repo' => 'r', 'number' => '1'],
			'https://user-images.githubusercontent.com/1/x.jpg',
		);
		$this->assertSame('https://user-images.githubusercontent.com/1/x.jpg', $captured['url']);
		$this->assertStringStartsWith('Bearer ', $captured['options']['headers']['Authorization']);
		$this->assertArrayNotHasKey('Accept', $captured['options']['headers']);
		$this->assertSame('IMG', $file['body']);
		$this->assertSame('image/jpeg', $file['contentType']);
	}

	public function testFetchFileRejectsForeignHost(): void {
		$this->expectException(TrackerException::class);
		$this->client->fetchFile($this->connection, ['owner' => 'o', 'repo' => 'r', 'number' => '1'], 'https://evil.example/x.png');
	}

	public function testGetCreateMetaFiltersRepos(): void {
		$this->http->method('request')->willReturnCallback(fn ($m, $u, $o) => $this->response(200, [
			['full_name' => 'octocat/Hello-World', 'has_issues' => true, 'permissions' => ['push' => true]],
			['full_name' => 'octocat/ReadOnly', 'has_issues' => true, 'permissions' => ['push' => false]],
			['full_name' => 'octocat/NoIssues', 'has_issues' => false, 'permissions' => ['push' => true]],
		]));
		$meta = $this->client->getCreateMeta($this->connection);
		$this->assertCount(1, $meta['projects'], 'only writable repos with issues enabled');
		$this->assertSame('octocat/Hello-World', $meta['projects'][0]['id']);
		$this->assertFalse($meta['capabilities']['type']);
	}

	public function testGetCreateMetaFiltersReposByQuery(): void {
		$this->http->method('request')->willReturnCallback(fn ($m, $u, $o) => $this->response(200, [
			['full_name' => 'octocat/Hello-World', 'has_issues' => true, 'permissions' => ['push' => true]],
			['full_name' => 'octocat/Spoon-Knife', 'has_issues' => true, 'permissions' => ['push' => true]],
		]));
		$meta = $this->client->getCreateMeta($this->connection, 'spoon');
		$this->assertCount(1, $meta['projects'], 'case-insensitive substring match on the repo name');
		$this->assertSame('octocat/Spoon-Knife', $meta['projects'][0]['id']);
	}

	public function testGetCreateMetaPagesThroughReposWhenSearching(): void {
		// A search must follow the Link header past the recent-100 window so an older
		// repo (here on page 2) is still found.
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			$page = $o['query']['page'] ?? '1';
			if ($page === '1') {
				return $this->response(
					200,
					[['full_name' => 'ochorocho/recent', 'has_issues' => true, 'permissions' => ['push' => true]]],
					['Link' => '<https://api.github.com/user/repos?page=2>; rel="next"'],
				);
			}
			return $this->response(200, [['full_name' => 'ochorocho/shippy', 'has_issues' => true, 'permissions' => ['push' => true]]]);
		});
		$meta = $this->client->getCreateMeta($this->connection, 'shippy');
		$this->assertCount(1, $meta['projects']);
		$this->assertSame('ochorocho/shippy', $meta['projects'][0]['id']);
	}

	public function testGetCreateMetaDescribesMilestoneField(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/milestones')) {
				return $this->response(200, [['number' => 3, 'title' => 'v1.0'], ['number' => 4, 'title' => 'v2.0']]);
			}
			return $this->response(200, []);
		});
		$meta = $this->client->getCreateMeta($this->connection, null, 'octocat/Hello-World', null);
		$this->assertCount(1, $meta['fields']);
		$this->assertSame('milestone', $meta['fields'][0]['id']);
		$this->assertSame('select', $meta['fields'][0]['type']);
		$this->assertSame([['id' => '3', 'name' => 'v1.0'], ['id' => '4', 'name' => 'v2.0']], $meta['fields'][0]['options']);
	}

	public function testCreateIssueEncodesMilestone(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/issues')) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, [
					'number' => 5, 'title' => 'T',
					'repository_url' => 'https://api.github.com/repos/octocat/Hello-World',
					'html_url' => 'https://github.com/octocat/Hello-World/issues/5',
				]);
			}
			return $this->response(200, []);
		});
		$this->client->createIssue($this->connection, ['project' => 'octocat/Hello-World', 'title' => 'T', 'fields' => ['milestone' => '3']]);
		$this->assertSame(3, $captured['milestone']);
	}

	public function testCreateIssuePostsToRepo(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, [
				'number' => 12, 'title' => 'New',
				'repository_url' => 'https://api.github.com/repos/octocat/Hello-World',
				'html_url' => 'https://github.com/octocat/Hello-World/issues/12',
			]);
		});
		$issue = $this->client->createIssue($this->connection, ['project' => 'octocat/Hello-World', 'title' => 'New', 'description' => 'Body']);
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/repos/octocat/Hello-World/issues', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('New', $body['title']);
		$this->assertSame('Body', $body['body']);
		$this->assertSame('#12', $issue->displayId);
	}

	private const REF = ['owner' => 'octocat', 'repo' => 'Hello-World', 'number' => '1'];

	public function testSearchAssigneesFiltersRepoAssignees(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u) use (&$captured) {
			$captured = $u;
			return $this->response(200, [['login' => 'alice'], ['login' => 'bob']]);
		});
		$out = $this->client->searchAssignees($this->connection, ['refParts' => self::REF], 'ali');
		$this->assertStringContainsString('/repos/octocat/Hello-World/assignees', $captured);
		$this->assertSame([['id' => 'alice', 'name' => 'alice']], $out);
	}

	public function testSearchAssigneesCreateContextSplitsProject(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u) use (&$captured) {
			$captured = $u;
			return $this->response(200, [['login' => 'alice']]);
		});
		$this->client->searchAssignees($this->connection, ['project' => 'octocat/Hello-World'], '');
		$this->assertStringContainsString('/repos/octocat/Hello-World/assignees', $captured);
	}

	public function testCreateIssueEncodesAssignee(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = json_decode($o['body'], true);
			return $this->response(201, ['number' => 12, 'title' => 'New', 'repository_url' => 'https://api.github.com/repos/octocat/Hello-World']);
		});
		$this->client->createIssue($this->connection, ['project' => 'octocat/Hello-World', 'title' => 'New', 'assignee' => 'alice']);
		$this->assertSame(['alice'], $captured['assignees']);
	}

	public function testGetRelationsMapsSubIssuesAndParent(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/sub_issues')) {
				return $this->response(200, [
					['id' => 900, 'number' => 5, 'title' => 'Child A', 'state' => 'open',
						'html_url' => 'https://github.com/octocat/Hello-World/issues/5',
						'repository_url' => 'https://api.github.com/repos/octocat/Hello-World'],
				]);
			}
			if (str_contains($u, '/parent')) {
				return $this->response(200, ['id' => 700, 'number' => 2, 'title' => 'Epic', 'state' => 'open',
					'html_url' => 'https://github.com/octocat/Hello-World/issues/2',
					'repository_url' => 'https://api.github.com/repos/octocat/Hello-World']);
			}
			return $this->response(404, []);
		});

		$relations = $this->client->getRelations($this->connection, self::REF);

		$this->assertCount(2, $relations);
		$this->assertSame('sub-issue', $relations[0]->type);
		$this->assertSame('900', $relations[0]->id, 'relation id is the numeric database id');
		$this->assertSame('#5', $relations[0]->targetDisplayId);
		$this->assertSame('Child A', $relations[0]->targetTitle);
		$this->assertTrue($relations[0]->deletable);
		$this->assertSame(
			['t' => 'github', 'c' => 'h1', 'p' => ['owner' => 'octocat', 'repo' => 'Hello-World', 'number' => '5']],
			Ref::decode($relations[0]->targetRef),
		);
		// Parent is read-only.
		$this->assertSame('parent', $relations[1]->type);
		$this->assertFalse($relations[1]->deletable);
	}

	public function testGetRelationsToleratesNoParent(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/sub_issues')) {
				return $this->response(200, []);
			}
			return $this->response(404, ['message' => 'Not Found']);
		});
		$this->assertSame([], $this->client->getRelations($this->connection, self::REF));
	}

	public function testAddRelationResolvesNumericIdThenPosts(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/sub_issues')) {
				$captured = ['url' => $u, 'options' => $o];
				return $this->response(200, []);
			}
			// GET target issue → resolve its numeric id
			return $this->response(200, ['id' => 950, 'number' => 8, 'title' => 'Task', 'state' => 'open',
				'html_url' => 'https://github.com/octocat/Hello-World/issues/8',
				'repository_url' => 'https://api.github.com/repos/octocat/Hello-World']);
		});

		$relation = $this->client->addRelation($this->connection, self::REF, 'sub-issue',
			['owner' => 'octocat', 'repo' => 'Hello-World', 'number' => '8']);

		$this->assertStringContainsString('/repos/octocat/Hello-World/issues/1/sub_issues', $captured['url']);
		$this->assertSame(950, json_decode($captured['options']['body'], true)['sub_issue_id']);
		$this->assertSame('950', $relation->id);
		$this->assertSame('#8', $relation->targetDisplayId);
	}

	public function testDeleteRelationPostsNumericId(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, []);
		});
		$this->client->deleteRelation($this->connection, self::REF, '900');
		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/repos/octocat/Hello-World/issues/1/sub_issue', $captured['url']);
		$this->assertSame(900, json_decode($captured['options']['body'], true)['sub_issue_id']);
	}

	public function testDoesNotSupportInlineUpload(): void {
		// GitHub has no token-accessible upload API; inline upload stays disabled.
		$this->assertFalse($this->client->supportsInlineUpload());
	}
}
