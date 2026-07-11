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
}
