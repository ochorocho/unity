<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Connection;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Service\AdfConverter;
use OCA\Unity\Service\Tracker\JiraClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class JiraClientTest extends TestCase {

	private IClient&MockObject $httpClient;
	private JiraClient $jira;
	private Connection $connection;
	private array $cacheStore = [];

	protected function setUp(): void {
		parent::setUp();
		$this->httpClient = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->httpClient);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $k) => $this->cacheStore[$k] ?? null);
		$cache->method('set')->willReturnCallback(function (string $k, $v, $ttl = 0): bool {
			$this->cacheStore[$k] = $v;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$this->jira = new JiraClient($clientService, new NullLogger(), new AdfConverter(), $cacheFactory);
		// Jira Cloud (host is *.atlassian.net → no deployment probe).
		$this->connection = new Connection('c1', 'jira', 'My Jira', 'https://acme.atlassian.net', 'me@acme.io', 'secret-token');
	}

	/** A Jira Server / DC connection (custom domain, PAT token). */
	private function serverConnection(): Connection {
		return new Connection('c2', 'jira', 'Example', 'https://pro.example.com', '', 'pat-token');
	}

	/** Dispatch httpClient by URL: /serverInfo → deploymentType, everything else → $body. */
	private function dispatch(string $deploymentType, array|string $body, ?callable $capture = null): void {
		$this->httpClient->method('request')->willReturnCallback(function (string $m, string $u, array $o) use ($deploymentType, $body, $capture): IResponse {
			if (str_contains($u, '/serverInfo')) {
				return $this->response(200, ['deploymentType' => $deploymentType, 'version' => '9.17.5']);
			}
			if ($capture !== null) {
				$capture(['method' => $m, 'url' => $u, 'options' => $o]);
			}
			return $this->response(200, $body);
		});
	}

	private function response(int $status, array|string $body, array $headers = []): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(is_array($body) ? (string)json_encode($body) : $body);
		$response->method('getHeader')->willReturnCallback(static fn (string $k): string => $headers[$k] ?? '');
		return $response;
	}

	private function sampleIssue(): array {
		return [
			'key' => 'ABC-1',
			'fields' => [
				'summary' => 'Fix the thing',
				'status' => ['name' => 'In Progress'],
				'assignee' => ['displayName' => 'Alice'],
				'reporter' => ['displayName' => 'Bob'],
				'labels' => ['backend', 'urgent'],
				'project' => ['name' => 'Acme', 'key' => 'ABC'],
				'created' => '2026-01-01T10:00:00.000+0000',
				'updated' => '2026-02-01T10:00:00.000+0000',
				'description' => [
					'type' => 'doc', 'version' => 1,
					'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Details here']]]],
				],
			],
		];
	}

	public function testSearchBuildsJqlAndMapsIssues(): void {
		$captured = null;
		$this->httpClient->expects($this->once())
			->method('request')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): IResponse {
				$captured = ['method' => $method, 'url' => $url, 'options' => $options];
				return $this->response(200, ['issues' => [$this->sampleIssue()], 'nextPageToken' => 'tok2']);
			});

		$result = $this->jira->search($this->connection, new IssueQuery(term: 'boom', sort: 'updated', order: 'desc'));

		$this->assertSame('GET', $captured['method']);
		$this->assertStringContainsString('/rest/api/3/search/jql', $captured['url']);
		$this->assertStringContainsString('text ~ "boom"', $captured['options']['query']['jql']);
		$this->assertStringContainsString('ORDER BY updated DESC', $captured['options']['query']['jql']);
		$this->assertStringStartsWith('Basic ', $captured['options']['headers']['Authorization']);

		$this->assertCount(1, $result->issues);
		$this->assertSame('tok2', $result->nextCursor);
		$issue = $result->issues[0];
		$this->assertSame('ABC-1', $issue->displayId);
		$this->assertSame('Fix the thing', $issue->title);
		$this->assertSame('In Progress', $issue->status);
		$this->assertSame('Alice', $issue->assignee);
		$this->assertSame('Bob', $issue->author);
		$this->assertSame(['backend', 'urgent'], $issue->labels);
		$this->assertSame('Acme', $issue->project);
		$this->assertSame('Details here', $issue->description);
		$this->assertSame('markdown', $issue->bodyFormat);
		$this->assertSame('https://acme.atlassian.net/browse/ABC-1', $issue->url);
		$this->assertSame(['t' => 'jira', 'c' => 'c1', 'p' => ['key' => 'ABC-1']], Ref::decode($issue->ref));
	}

	public function testSearchAddsDefaultBoundWhenNoFilters(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, ['issues' => [], 'nextPageToken' => null]);
		});
		$this->jira->search($this->connection, new IssueQuery());
		// Bounded query required by Jira Cloud — no bare ORDER BY.
		$this->assertStringContainsString('updated >= -365d', $captured['query']['jql']);
		$this->assertStringContainsString('ORDER BY updated DESC', $captured['query']['jql']);
	}

	public function testSearchBoundsTextOnlySearch(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, ['issues' => [], 'nextPageToken' => null]);
		});
		$this->jira->search($this->connection, new IssueQuery(term: 'boom'));
		// A text-only search is also unbounded on Jira → must gain a bound.
		$this->assertStringContainsString('text ~ "boom"', $captured['query']['jql']);
		$this->assertStringContainsString('updated >= -365d', $captured['query']['jql']);
	}

	public function testSearchByKeyReferenceFetchesIssueDirectly(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(200, $this->sampleIssue());
		});
		$result = $this->jira->search($this->connection, new IssueQuery(term: 'abc-1'));
		$this->assertSame('GET', $captured['method']);
		$this->assertStringContainsString('/issue/ABC-1', $captured['url']);
		$this->assertStringNotContainsString('/search/jql', $captured['url']);
		$this->assertCount(1, $result->issues);
	}

	public function testGetIssueMapsSingleIssue(): void {
		$this->httpClient->method('request')->willReturn($this->response(200, $this->sampleIssue()));
		$issue = $this->jira->getIssue($this->connection, ['key' => 'ABC-1']);
		$this->assertSame('ABC-1', $issue->displayId);
		$this->assertSame('Details here', $issue->description);
	}

	public function testAddCommentPostsAdfBody(): void {
		$captured = null;
		$this->httpClient->expects($this->once())
			->method('request')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): IResponse {
				$captured = ['method' => $method, 'url' => $url, 'options' => $options];
				return $this->response(201, [
					'id' => '10001',
					'author' => ['displayName' => 'Alice'],
					'created' => '2026-03-01T10:00:00.000+0000',
					'body' => ['type' => 'doc', 'version' => 1, 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nice']]]]],
				]);
			});

		$comment = $this->jira->addComment($this->connection, ['key' => 'ABC-1'], 'Nice');

		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/issue/ABC-1/comment', $captured['url']);
		$decoded = json_decode($captured['options']['body'], true);
		$this->assertSame('doc', $decoded['body']['type']);
		$this->assertSame('Nice', $decoded['body']['content'][0]['content'][0]['text']);
		$this->assertSame('10001', $comment->id);
		$this->assertSame('Nice', $comment->body);
	}

	public function testRetriesOnce(): void {
		$this->httpClient->expects($this->exactly(2))
			->method('request')
			->willReturnOnConsecutiveCalls(
				$this->response(429, '', ['Retry-After' => '1']),
				$this->response(200, ['issues' => [], 'nextPageToken' => null]),
			);
		$result = $this->jira->search($this->connection, new IssueQuery());
		$this->assertSame([], $result->issues);
	}

	public function testTestConnectionOk(): void {
		$this->httpClient->method('request')->willReturn($this->response(200, ['displayName' => 'Alice']));
		$result = $this->jira->testConnection($this->connection);
		$this->assertTrue($result['ok']);
		$this->assertSame('Alice', $result['user']);
	}

	public function testTestConnectionFailure(): void {
		$this->httpClient->method('request')->willReturn($this->response(401, ['errorMessages' => ['Unauthorized']]));
		$result = $this->jira->testConnection($this->connection);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('Unauthorized', $result['message']);
	}

	public function testAllowLocalAddressOptionSetWhenEnabled(): void {
		$captured = null;
		$this->dispatch('Server', ['displayName' => 'Alice'], function (array $c) use (&$captured): void {
			$captured = $c;
		});
		// A internal Jira Server with the per-connection opt-in enabled.
		$connection = new Connection('c2', 'jira', 'Example', 'https://test.example.com', '', 'pat-token', '', ['allowLocalAddress' => true]);
		$result = $this->jira->testConnection($connection);
		$this->assertTrue($result['ok']);
		$this->assertTrue($captured['options']['nextcloud']['allow_local_address']);
	}

	public function testAllowLocalAddressOptionAbsentByDefault(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, array $o) use (&$captured): IResponse {
			$captured = $o;
			return $this->response(200, ['displayName' => 'Alice']);
		});
		// Default connection (no opt-in) must not relax Nextcloud's SSRF protection.
		$this->jira->testConnection($this->connection);
		$this->assertArrayNotHasKey('nextcloud', $captured);
	}

	public function testLogTimePostsWorklogWithFormattedStart(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, ['id' => '1']);
		});
		$this->jira->logTime($this->connection, ['key' => 'ABC-1'], 3600, 'work', '2026-07-08');
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/issue/ABC-1/worklog', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame(3600, $body['timeSpentSeconds']);
		$this->assertSame('2026-07-08T09:00:00.000+0000', $body['started']);
		$this->assertSame('doc', $body['comment']['type']);
	}

	public function testUpdateCommentPutsAdfBody(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, ['id' => '10001', 'author' => ['displayName' => 'Alice'], 'body' => ['type' => 'doc', 'version' => 1, 'content' => []]]);
		});
		$this->jira->updateComment($this->connection, ['key' => 'ABC-1'], '10001', 'done **x**');
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringContainsString('/issue/ABC-1/comment/10001', $captured['url']);
		$this->assertSame('doc', json_decode($captured['options']['body'], true)['body']['type']);
	}

	public function testUpdateIssuePutsFieldsAndTransition(): void {
		$calls = [];
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$calls) {
			$calls[] = ['method' => $m, 'url' => $u, 'options' => $o];
			if (str_contains($u, '/transitions')) {
				return $this->response(204, '');
			}
			if ($m === 'PUT') {
				return $this->response(204, '');
			}
			return $this->response(200, $this->sampleIssue());
		});

		$this->jira->updateIssue($this->connection, ['key' => 'ABC-1'], [
			'title' => 'New', 'description' => '**b**', 'status' => '31', 'assignee' => 'acc-1', 'labels' => ['x'],
		]);

		$put = null;
		$transition = null;
		foreach ($calls as $c) {
			if ($c['method'] === 'PUT') {
				$put = $c;
			}
			if (str_contains($c['url'], '/transitions')) {
				$transition = $c;
			}
		}
		$this->assertNotNull($put);
		$fields = json_decode($put['options']['body'], true)['fields'];
		$this->assertSame('New', $fields['summary']);
		$this->assertSame('doc', $fields['description']['type']);
		$this->assertSame('acc-1', $fields['assignee']['accountId']);
		$this->assertSame(['x'], $fields['labels']);
		$this->assertNotNull($transition);
		$this->assertSame('31', json_decode($transition['options']['body'], true)['transition']['id']);
	}

	public function testGetTimeRecordsMapsWorklogsAndGatesOwnership(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/myself')) {
				return $this->response(200, ['accountId' => 'acc-me']);
			}
			return $this->response(200, [
				'worklogs' => [
					['id' => '100', 'author' => ['displayName' => 'Alice', 'accountId' => 'acc-me'],
						'timeSpentSeconds' => 5400, 'started' => '2026-02-01T09:00:00.000+0000',
						'comment' => ['type' => 'doc', 'version' => 1, 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'did work']]]]]],
					['id' => '101', 'author' => ['displayName' => 'Bob', 'accountId' => 'acc-bob'],
						'timeSpentSeconds' => 3600, 'started' => '2026-02-02T09:00:00.000+0000'],
				],
			]);
		});
		$records = $this->jira->getTimeRecords($this->connection, ['key' => 'ABC-1']);
		$this->assertCount(2, $records);
		$this->assertSame('Alice', $records[0]->author);
		$this->assertSame(5400, $records[0]->seconds);
		$this->assertSame('did work', $records[0]->comment);
		// The connection user (acc-me) authored the first worklog.
		$this->assertTrue($records[0]->editable);
		$this->assertTrue($records[0]->deletable);
		// Bob's worklog is not owned by the connection user.
		$this->assertFalse($records[1]->editable);
		$this->assertFalse($records[1]->deletable);
	}

	public function testLogTimeViaTempoWhenTempoTokenPresent(): void {
		$conn = new Connection('c1', 'jira', 'My Jira', 'https://acme.atlassian.net', 'me@acme.io', 'secret-token', 'tempo-secret');
		$tempo = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$tempo) {
			if (str_contains($u, 'api.tempo.io/4/worklogs')) {
				$tempo = ['method' => $m, 'url' => $u, 'options' => $o];
				return $this->response(200, ['tempoWorklogId' => 1]);
			}
			if (str_contains($u, '/myself')) {
				return $this->response(200, ['accountId' => 'acc-123']);
			}
			return $this->response(200, ['id' => '10001', 'key' => 'ABC-1']);
		});

		$this->jira->logTime($conn, ['key' => 'ABC-1'], 3600, 'work', '2026-07-08');

		$this->assertNotNull($tempo, 'should post to the Tempo API');
		$this->assertSame('POST', $tempo['method']);
		$this->assertStringStartsWith('Bearer tempo-secret', $tempo['options']['headers']['Authorization']);
		$body = json_decode($tempo['options']['body'], true);
		$this->assertSame(10001, $body['issueId']);
		$this->assertSame(3600, $body['timeSpentSeconds']);
		$this->assertSame('2026-07-08', $body['startDate']);
		$this->assertSame('acc-123', $body['authorAccountId']);
		$this->assertSame('work', $body['description']);
	}

	// --- Jira Server / Data Center ------------------------------------------------

	public function testServerGetIssueUsesV2BearerAndPlaintext(): void {
		$captured = null;
		$server = [
			'key' => 'DC-1',
			'fields' => [
				'summary' => 'Server issue',
				'status' => ['name' => 'Open'],
				'assignee' => ['displayName' => 'Carol'],
				'project' => ['name' => 'DataCenter'],
				'description' => "h2. Heading\n* wiki markup",
			],
		];
		$this->dispatch('Server', $server, function ($c) use (&$captured) {
			$captured = $c;
		});

		$issue = $this->jira->getIssue($this->serverConnection(), ['key' => 'DC-1']);

		$this->assertStringContainsString('/rest/api/2/issue/DC-1', $captured['url']);
		$this->assertStringNotContainsString('/rest/api/3/', $captured['url']);
		$this->assertSame('Bearer pat-token', $captured['options']['headers']['Authorization']);
		$this->assertSame('html', $issue->bodyFormat);
		$this->assertSame("h2. Heading\n* wiki markup", $issue->description);
		$this->assertSame('Carol', $issue->assignee);
	}

	public function testServerSearchUsesOffsetPagination(): void {
		$captured = null;
		$this->dispatch('Server', ['issues' => [], 'total' => 120, 'startAt' => 0, 'maxResults' => 50], function ($c) use (&$captured) {
			$captured = $c;
		});

		$result = $this->jira->search($this->serverConnection(), new IssueQuery(assignedToMe: true, limit: 50));

		$this->assertStringContainsString('/rest/api/2/search', $captured['url']);
		$this->assertStringNotContainsString('/search/jql', $captured['url']);
		$this->assertSame('0', $captured['options']['query']['startAt']);
		$this->assertSame('assignee = currentUser() ORDER BY updated DESC', $captured['options']['query']['jql']);
		// 0 + 50 < 120 → next page cursor is the next offset
		$this->assertSame('50', $result->nextCursor);
	}

	public function testServerAddCommentSendsPlainStringBody(): void {
		$captured = null;
		$this->dispatch('Server', ['id' => '5', 'author' => ['displayName' => 'Carol'], 'body' => 'thanks', 'created' => '2026-03-01T10:00:00.000+0000'], function ($c) use (&$captured) {
			$captured = $c;
		});

		$comment = $this->jira->addComment($this->serverConnection(), ['key' => 'DC-1'], 'thanks');

		$this->assertStringContainsString('/rest/api/2/issue/DC-1/comment', $captured['url']);
		// Server comment body is a plain wiki-markup string, not an ADF document.
		$this->assertSame('thanks', json_decode($captured['options']['body'], true)['body']);
		$this->assertSame('thanks', $comment->body);
	}

	public function testServerUpdateIssueUsesNameForAssignee(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured): IResponse {
			if (str_contains($u, '/serverInfo')) {
				return $this->response(200, ['deploymentType' => 'Server']);
			}
			if ($m === 'PUT') {
				$captured = ['url' => $u, 'options' => $o];
				return $this->response(204, '');
			}
			return $this->response(200, ['key' => 'DC-1', 'fields' => []]);
		});

		$this->jira->updateIssue($this->serverConnection(), ['key' => 'DC-1'], ['assignee' => 'carol', 'description' => 'plain text']);

		$fields = json_decode($captured['options']['body'], true)['fields'];
		$this->assertSame('carol', $fields['assignee']['name']);
		$this->assertArrayNotHasKey('accountId', $fields['assignee']);
		$this->assertSame('plain text', $fields['description']);
	}

	public function testServerConnectionReportsFlavour(): void {
		$this->dispatch('Server', ['name' => 'carol', 'displayName' => 'Carol']);
		$result = $this->jira->testConnection($this->serverConnection());
		$this->assertTrue($result['ok']);
		$this->assertStringContainsString('Jira Server', $result['message']);
		$this->assertSame('Carol', $result['user']);
	}
}
