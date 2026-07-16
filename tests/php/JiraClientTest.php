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
use OCA\Unity\Service\Tracker\TrackerException;
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

	public function testSearchExcludesClosedByDefaultAndIncludesWhenRequested(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, ['issues' => [], 'nextPageToken' => null]);
		});
		$this->jira->search($this->connection, new IssueQuery());
		$this->assertStringContainsString('statusCategory != Done', $captured['query']['jql'], 'closed hidden by default');

		$this->jira->search($this->connection, new IssueQuery(showClosed: true));
		$this->assertStringNotContainsString('statusCategory', $captured['query']['jql'], 'closed included when requested');
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

	public function testAddCommentCloudEmitsAdfMentionNode(): void {
		$captured = null;
		$this->httpClient->method('request')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): IResponse {
				$captured = $options;
				return $this->response(201, [
					'id' => '5', 'author' => ['displayName' => 'A'], 'created' => '2026-03-01T10:00:00.000+0000',
					'body' => ['type' => 'doc', 'version' => 1, 'content' => []],
				]);
			});
		$this->jira->addComment($this->connection, ['key' => 'ABC-1'], 'hi @"user/557058:abc" there');
		$nodes = json_decode($captured['body'], true)['body']['content'][0]['content'];
		$mention = null;
		foreach ($nodes as $node) {
			if (($node['type'] ?? '') === 'mention') {
				$mention = $node;
			}
		}
		$this->assertNotNull($mention);
		$this->assertSame('557058:abc', $mention['attrs']['id']);
	}

	public function testAddCommentCloudReturnsEditableCommentWithMentionTokens(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o): IResponse {
			return $this->response(201, [
				'id' => '5', 'author' => ['displayName' => 'A'], 'created' => '2026-03-01T10:00:00.000+0000',
				'body' => ['type' => 'doc', 'version' => 1, 'content' => [
					['type' => 'paragraph', 'content' => [
						['type' => 'text', 'text' => 'hi '],
						['type' => 'mention', 'attrs' => ['id' => '557058:abc', 'text' => '@Jochen Roth']],
					]],
				]],
			]);
		});
		$comment = $this->jira->addComment($this->connection, ['key' => 'ABC-1'], 'x');
		$this->assertTrue($comment->editable, 'a just-added comment is editable without a reload');
		$this->assertTrue($comment->deletable);
		$this->assertSame('hi @"user/557058:abc"', $comment->body, 'editor body uses the token form');
		$this->assertSame([['id' => 'user/557058:abc', 'label' => 'Jochen Roth']], $comment->mentions);
		$this->assertStringContainsString('class="unity-mention"', (string)$comment->renderedBody);
	}

	public function testAddCommentServerConvertsMentionToWikiMarkup(): void {
		$captured = null;
		$this->dispatch('Server', [
			'id' => '5', 'author' => ['displayName' => 'A'], 'created' => '2026-03-01T10:00:00.000+0000',
			'body' => 'ping [~jdoe] now',
		], function (array $req) use (&$captured): void {
			$captured = $req['options'];
		});
		$this->jira->addComment($this->serverConnection(), ['key' => 'ABC-1'], 'ping @"user/jdoe" now');
		$this->assertSame('ping [~jdoe] now', json_decode($captured['body'], true)['body']);
	}

	public function testServerCommentRendersMentionAsProfileLink(): void {
		$this->dispatch('Server', [
			'id' => '5', 'author' => ['displayName' => 'A'], 'created' => '2026-03-01T10:00:00.000+0000',
			'body' => 'ping [~jdoe] now',
		]);
		$comment = $this->jira->addComment($this->serverConnection(), ['key' => 'ABC-1'], 'x');
		$this->assertStringContainsString(
			'<a class="unity-mention" href="https://pro.example.com/secure/ViewProfile.jspa?name=jdoe">jdoe</a>',
			(string)$comment->renderedBody,
		);
		$this->assertSame('ping [~jdoe] now', $comment->body, 'raw wiki kept for editing');
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
						'timeSpentSeconds' => 5400, 'started' => '2026-02-01T09:00:00.000+0000', 'created' => '2026-02-05T10:00:00.000+0000',
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
		$this->assertSame('2026-02-05T10:00:00.000+0000', $records[0]->createdAt);
		// The connection user (acc-me) authored the first worklog.
		$this->assertTrue($records[0]->editable);
		$this->assertTrue($records[0]->deletable);
		// Bob's worklog is not owned by the connection user.
		$this->assertFalse($records[1]->editable);
		$this->assertFalse($records[1]->deletable);
	}

	public function testGetCommentsGatesOwnership(): void {
		$adf = fn (string $text): array => ['type' => 'doc', 'version' => 1, 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u) use ($adf) {
			if (str_contains($u, '/myself')) {
				return $this->response(200, ['accountId' => 'acc-me']);
			}
			return $this->response(200, ['comments' => [
				['id' => '1', 'author' => ['displayName' => 'Alice', 'accountId' => 'acc-me'], 'body' => $adf('hello'), 'created' => 'x'],
				['id' => '2', 'author' => ['displayName' => 'Bob', 'accountId' => 'acc-bob'], 'body' => $adf('world'), 'created' => 'y'],
			]]);
		});
		$comments = $this->jira->getComments($this->connection, ['key' => 'ABC-1']);
		$this->assertCount(2, $comments);
		$this->assertSame('hello', $comments[0]->body);
		// The connection user (acc-me) authored the first comment.
		$this->assertTrue($comments[0]->editable);
		$this->assertTrue($comments[0]->deletable);
		$this->assertFalse($comments[1]->editable);
		$this->assertFalse($comments[1]->deletable);
	}

	public function testDeleteCommentDeletes(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(204, '');
		});
		$this->jira->deleteComment($this->connection, ['key' => 'ABC-1'], '5');
		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/issue/ABC-1/comment/5', $captured['url']);
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
		$this->assertSame('assignee = currentUser() AND statusCategory != Done ORDER BY updated DESC', $captured['options']['query']['jql']);
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

	public function testGetAttachmentsMapsFieldsAttachment(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, ['fields' => ['attachment' => [[
				'id' => '10050', 'filename' => 'shot.png', 'mimeType' => 'image/png', 'size' => 4096,
				'content' => 'https://acme.atlassian.net/rest/api/3/attachment/content/10050',
				'thumbnail' => 'https://acme.atlassian.net/rest/api/3/attachment/thumbnail/10050',
				'author' => ['displayName' => 'Alice'], 'created' => '2026-03-01T10:00:00.000+0000',
			]]]]);
		});

		$attachments = $this->jira->getAttachments($this->connection, ['key' => 'ABC-1']);

		$this->assertSame('attachment', $captured['options']['query']['fields']);
		$this->assertStringContainsString('/issue/ABC-1', $captured['url']);
		$this->assertCount(1, $attachments);
		$this->assertSame('10050', $attachments[0]->id);
		$this->assertSame('shot.png', $attachments[0]->filename);
		$this->assertSame('image/png', $attachments[0]->mimeType);
		$this->assertSame(4096, $attachments[0]->size);
		$this->assertSame('https://acme.atlassian.net/rest/api/3/attachment/content/10050', $attachments[0]->src);
		$this->assertSame('https://acme.atlassian.net/rest/api/3/attachment/thumbnail/10050', $attachments[0]->thumbnailSrc);
		$this->assertSame('Alice', $attachments[0]->author);
	}

	public function testUploadAttachmentPostsMultipartWithToken(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, [[
				'id' => '10060', 'filename' => 'doc.pdf', 'mimeType' => 'application/pdf', 'size' => 12,
				'content' => 'https://acme.atlassian.net/rest/api/3/attachment/content/10060',
			]]);
		});

		$att = $this->jira->uploadAttachment($this->connection, ['key' => 'ABC-1'], 'doc.pdf', 'application/pdf', 'BYTES');

		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/issue/ABC-1/attachments', $captured['url']);
		$this->assertSame('no-check', $captured['options']['headers']['X-Atlassian-Token']);
		// Multipart upload must not carry the JSON Content-Type header.
		$this->assertArrayNotHasKey('Content-Type', $captured['options']['headers']);
		$this->assertStringStartsWith('Basic ', $captured['options']['headers']['Authorization']);
		$part = $captured['options']['multipart'][0];
		$this->assertSame('file', $part['name']);
		$this->assertSame('doc.pdf', $part['filename']);
		$this->assertSame('BYTES', $part['contents']);
		$this->assertSame('10060', $att->id);
		$this->assertSame('doc.pdf', $att->filename);
	}

	public function testDeleteAttachmentIssuesDelete(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(204, '');
		});

		$this->jira->deleteAttachment($this->connection, ['key' => 'ABC-1'], '10050');

		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/rest/api/3/attachment/10050', $captured['url']);
		$this->assertStringStartsWith('Basic ', $captured['options']['headers']['Authorization']);
	}

	public function testGetCreateMetaMapsProjectsAndTypes(): void {
		$this->httpClient->method('request')->willReturnCallback(fn ($m, $u, $o) => $this->response(200, [
			'projects' => [[
				'key' => 'ABC', 'name' => 'Acme',
				'issuetypes' => [['id' => '1', 'name' => 'Bug'], ['id' => '2', 'name' => 'Sub', 'subtask' => true]],
			]],
		]));
		$meta = $this->jira->getCreateMeta($this->connection);
		$this->assertCount(1, $meta['projects']);
		$this->assertSame('ABC', $meta['projects'][0]['id']);
		$this->assertCount(1, $meta['projects'][0]['types'], 'subtask types filtered out');
		$this->assertSame('Bug', $meta['projects'][0]['types'][0]['name']);
		$this->assertTrue($meta['capabilities']['type']);
	}

	public function testCreateIssuePostsFieldsAndFetchesResult(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/issue') && !str_contains($u, 'createmeta')) {
				$captured = ['url' => $u, 'options' => $o];
				return $this->response(201, ['key' => 'ABC-5']);
			}
			// getIssue() after creation.
			return $this->response(200, ['key' => 'ABC-5', 'fields' => ['summary' => 'New', 'project' => ['name' => 'Acme']]]);
		});
		$issue = $this->jira->createIssue($this->connection, ['project' => 'ABC', 'type' => '1', 'title' => 'New', 'description' => 'Body']);
		$this->assertNotNull($captured);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('ABC', $body['fields']['project']['key']);
		$this->assertSame('1', $body['fields']['issuetype']['id']);
		$this->assertSame('New', $body['fields']['summary']);
		$this->assertSame('ABC-5', $issue->displayId);
	}

	public function testGetCreateMetaReturnsProjectIssueTypes(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/createmeta/AKE/issuetypes') && !preg_match('#/issuetypes/\d#', $u)) {
				return $this->response(200, ['issueTypes' => [
					['id' => '1', 'name' => 'Bug', 'subtask' => false],
					['id' => '5', 'name' => 'Sub-task', 'subtask' => true],
					['id' => '10000', 'name' => 'Story', 'subtask' => false],
				]]);
			}
			return $this->response(200, []);
		});
		$meta = $this->jira->getCreateMeta($this->connection, null, 'AKE', null);
		// Subtasks filtered out; {id,name} mapping.
		$this->assertSame([
			['id' => '1', 'name' => 'Bug'],
			['id' => '10000', 'name' => 'Story'],
		], $meta['types']);
	}

	public function testGetCreateMetaOmitsTypesWhenTypeChosen(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [
					['fieldId' => 'priority', 'name' => 'Priority', 'schema' => ['type' => 'priority'], 'allowedValues' => [['id' => '3', 'name' => 'High']]],
				]]);
			}
			// The per-project issuetypes endpoint must not be needed when a type is given.
			return $this->response(200, ['issueTypes' => [['id' => '99', 'name' => 'ShouldNotAppear', 'subtask' => false]]]);
		});
		$meta = $this->jira->getCreateMeta($this->connection, null, 'AKE', '1');
		$this->assertSame([], $meta['types']);
		$this->assertSame('priority', $meta['fields'][0]['id']);
	}

	public function testGetCreateMetaDescribesFieldsFromSchema(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [
					['fieldId' => 'summary', 'name' => 'Summary', 'required' => true, 'schema' => ['type' => 'string']],
					['fieldId' => 'priority', 'name' => 'Priority', 'required' => false, 'schema' => ['type' => 'priority'], 'allowedValues' => [['id' => '3', 'name' => 'High'], ['id' => '4', 'name' => 'Low']]],
					['fieldId' => 'duedate', 'name' => 'Due', 'required' => false, 'schema' => ['type' => 'date']],
					['fieldId' => 'customfield_rem', 'name' => 'Remind me on', 'required' => false, 'schema' => ['type' => 'datetime']],
					['fieldId' => 'customfield_1', 'name' => 'Sprints', 'required' => false, 'schema' => ['type' => 'array', 'items' => 'option'], 'allowedValues' => [['id' => '9', 'value' => 'S1']]],
					['fieldId' => 'customfield_epic', 'name' => 'Epic', 'required' => false, 'schema' => ['type' => 'any']],
					['fieldId' => 'attachment', 'name' => 'Attach', 'schema' => ['type' => 'array', 'items' => 'attachment']],
				]]);
			}
			return $this->response(200, []);
		});
		$meta = $this->jira->getCreateMeta($this->connection, null, 'AKE', '1');
		$byId = [];
		foreach ($meta['fields'] as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertArrayNotHasKey('summary', $byId);
		$this->assertArrayNotHasKey('attachment', $byId);
		$this->assertArrayNotHasKey('customfield_epic', $byId); // type "any" is unrenderable
		$this->assertSame('select', $byId['priority']['type']);
		$this->assertSame([['id' => '3', 'name' => 'High'], ['id' => '4', 'name' => 'Low']], $byId['priority']['options']);
		$this->assertSame('date', $byId['duedate']['type']);
		$this->assertSame('date', $byId['customfield_rem']['type']); // datetime → date picker
		$this->assertSame('multiselect', $byId['customfield_1']['type']);
		$this->assertSame([['id' => '9', 'name' => 'S1']], $byId['customfield_1']['options']);
	}

	public function testCreateIssueEncodesDynamicFieldsPerSchema(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [
					['fieldId' => 'priority', 'schema' => ['type' => 'priority'], 'allowedValues' => [['id' => '3']]],
					['fieldId' => 'customfield_1', 'schema' => ['type' => 'array', 'items' => 'option'], 'allowedValues' => [['id' => '9']]],
					['fieldId' => 'customfield_u', 'schema' => ['type' => 'user'], 'allowedValues' => [['id' => 'x']]],
					['fieldId' => 'duedate', 'schema' => ['type' => 'date']],
				]]);
			}
			if ($m === 'POST' && str_contains($u, '/issue') && !str_contains($u, 'createmeta')) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['key' => 'AKE-9']);
			}
			return $this->response(200, ['key' => 'AKE-9', 'fields' => ['summary' => 'T', 'project' => ['name' => 'AK-E']]]);
		});
		$this->jira->createIssue($this->connection, [
			'project' => 'AKE', 'type' => '1', 'title' => 'T', 'description' => '',
			'fields' => ['priority' => '3', 'customfield_1' => ['9'], 'customfield_u' => 'acc-1', 'duedate' => '2026-08-01'],
		]);
		$f = $captured['fields'];
		$this->assertSame(['id' => '3'], $f['priority']);
		$this->assertSame([['id' => '9']], $f['customfield_1']);
		$this->assertSame(['accountId' => 'acc-1'], $f['customfield_u']); // Cloud user encoding
		$this->assertSame('2026-08-01', $f['duedate']);
	}

	public function testGetEditMetaCarriesCurrentFieldValues(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/transitions')) {
				return $this->response(200, ['transitions' => []]);
			}
			if (str_contains($u, '/user/assignable/search')) {
				return $this->response(200, []);
			}
			if (str_contains($u, '/editmeta')) {
				return $this->response(200, ['fields' => [
					'priority' => ['name' => 'Priority', 'required' => false, 'schema' => ['type' => 'priority'], 'allowedValues' => [['id' => '4', 'name' => 'Low']]],
					'summary' => ['name' => 'Summary', 'schema' => ['type' => 'string']],
				]]);
			}
			if (preg_match('#/issue/AKE-4(\?|$)#', $u) === 1) {
				return $this->response(200, ['fields' => ['priority' => ['id' => '4', 'name' => 'Low']]]);
			}
			return $this->response(200, []);
		});
		$meta = $this->jira->getEditMeta($this->connection, ['key' => 'AKE-4']);
		$byId = [];
		foreach ($meta['fields'] as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertArrayHasKey('priority', $byId);
		$this->assertArrayNotHasKey('summary', $byId);
		$this->assertSame('4', $byId['priority']['value']);
	}

	public function testGetEditMetaOffersProjectIssueTypes(): void {
		// The edit form gets the same full project type list as the create dialog,
		// independent of the current issue's editmeta operations.
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/transitions')) {
				return $this->response(200, ['transitions' => []]);
			}
			if (str_contains($u, '/user/assignable/search')) {
				return $this->response(200, []);
			}
			if (str_contains($u, '/createmeta/AKE/issuetypes') && preg_match('#/issuetypes/\d#', $u) !== 1) {
				return $this->response(200, ['issueTypes' => [
					['id' => '1', 'name' => 'Bug', 'subtask' => false],
					['id' => '5', 'name' => 'Epic', 'subtask' => false],
					['id' => '99', 'name' => 'Sub', 'subtask' => true],
				]]);
			}
			if (str_contains($u, '/editmeta')) {
				return $this->response(200, ['fields' => ['issuetype' => ['operations' => []]]]);
			}
			if (preg_match('#/issue/AKE-4(\?|$)#', $u) === 1) {
				return $this->response(200, ['fields' => ['issuetype' => ['id' => '1', 'name' => 'Bug']]]);
			}
			return $this->response(200, []);
		});
		$meta = $this->jira->getEditMeta($this->connection, ['key' => 'AKE-4']);
		// Shown even though this issue's editmeta grants no `set` operation.
		$this->assertTrue($meta['capabilities']['type']);
		$this->assertSame([['id' => '1', 'name' => 'Bug'], ['id' => '5', 'name' => 'Epic']], $meta['types']);
		$this->assertSame('1', $meta['typeId']);
	}

	public function testGetEditMetaTypeHiddenWhenProjectHasNoTypes(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/transitions')) {
				return $this->response(200, ['transitions' => []]);
			}
			if (str_contains($u, '/editmeta')) {
				return $this->response(200, ['fields' => ['issuetype' => ['operations' => []]]]);
			}
			if (preg_match('#/issue/AKE-4(\?|$)#', $u) === 1) {
				return $this->response(200, ['fields' => ['issuetype' => ['id' => '1']]]);
			}
			return $this->response(200, []); // no issue types available
		});
		$meta = $this->jira->getEditMeta($this->connection, ['key' => 'AKE-4']);
		$this->assertFalse($meta['capabilities']['type']);
		$this->assertSame([], $meta['types']);
	}

	public function testGetEditMetaTypeOverrideDescribesNewTypeFields(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/transitions')) {
				return $this->response(200, ['transitions' => []]);
			}
			if (str_contains($u, '/user/assignable/search')) {
				return $this->response(200, []);
			}
			if (str_contains($u, '/editmeta')) {
				return $this->response(200, ['fields' => ['issuetype' => ['operations' => ['set'], 'allowedValues' => []]]]);
			}
			if (str_contains($u, '/createmeta/AKE/issuetypes/5')) {
				return $this->response(200, ['fields' => [
					['fieldId' => 'customfield_epicname', 'name' => 'Epic Name', 'schema' => ['type' => 'string']],
				]]);
			}
			return $this->response(200, ['fields' => ['issuetype' => ['id' => '1']]]);
		});
		$meta = $this->jira->getEditMeta($this->connection, ['key' => 'AKE-4'], '5');
		$this->assertContains('customfield_epicname', array_column($meta['fields'], 'id'));
	}

	public function testUpdateIssueEncodesType(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'PUT' && str_contains($u, '/issue/')) {
				$captured = json_decode($o['body'], true);
				return $this->response(204, '');
			}
			return $this->response(200, ['key' => 'AKE-4', 'fields' => ['summary' => 'X', 'project' => ['name' => 'AK-E']]]);
		});
		$this->jira->updateIssue($this->connection, ['key' => 'AKE-4'], ['type' => '5']);
		$this->assertSame(['id' => '5'], $captured['fields']['issuetype']);
	}

	public function testSearchAssigneesCloudPassesQueryAndIssueKey(): void {
		// Regression: Jira Cloud 400s on /user/assignable/search without a `query`.
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, [
				['accountId' => 'acc-1', 'displayName' => 'Alice'],
				['accountId' => 'acc-2', 'displayName' => 'Bob'],
			]);
		});
		$out = $this->jira->searchAssignees($this->connection, ['refParts' => ['key' => 'AKE-4']], 'al');
		$this->assertStringContainsString('/user/assignable/search', $captured['url']);
		$this->assertSame('al', $captured['options']['query']['query']);
		$this->assertSame('AKE-4', $captured['options']['query']['issueKey']);
		$this->assertSame([['id' => 'acc-1', 'name' => 'Alice'], ['id' => 'acc-2', 'name' => 'Bob']], $out);
	}

	public function testSearchAssigneesCloudEmptyQueryPreloadsList(): void {
		// Cloud lists all assignable users when `query` is present but empty (omitting
		// it 400s) — this pre-loads the picker before the user types.
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, [
				['accountId' => 'acc-1', 'displayName' => 'Alice'],
				['accountId' => 'acc-2', 'displayName' => 'Bob'],
			]);
		});
		$out = $this->jira->searchAssignees($this->connection, ['refParts' => ['key' => 'AKE-4']], '');
		$this->assertArrayHasKey('query', $captured['query'], 'query param is present (empty)');
		$this->assertSame('', $captured['query']['query']);
		$this->assertSame('AKE-4', $captured['query']['issueKey']);
		$this->assertSame([['id' => 'acc-1', 'name' => 'Alice'], ['id' => 'acc-2', 'name' => 'Bob']], $out);
	}

	public function testSearchAssigneesCreateContextUsesProject(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, [['accountId' => 'acc-1', 'displayName' => 'Alice']]);
		});
		$this->jira->searchAssignees($this->connection, ['project' => 'AKE'], 'al');
		$this->assertSame('AKE', $captured['query']['project']);
		$this->assertArrayNotHasKey('issueKey', $captured['query']);
	}

	public function testSearchAssigneesServerUsesNameAndAllowsEmptyQuery(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/serverInfo')) {
				return $this->response(200, ['deploymentType' => 'Server', 'version' => '9.17.5']);
			}
			$captured = $o;
			return $this->response(200, [['name' => 'carol', 'displayName' => 'Carol']]);
		});
		$out = $this->jira->searchAssignees($this->serverConnection(), ['refParts' => ['key' => 'DC-1']], '');
		$this->assertSame('DC-1', $captured['query']['issueKey']);
		$this->assertArrayNotHasKey('query', $captured['query'], 'empty query omitted');
		$this->assertSame([['id' => 'carol', 'name' => 'Carol']], $out);
	}

	public function testCreateIssueEncodesAssignee(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['key' => 'AKE-9']);
			}
			return $this->response(200, ['key' => 'AKE-9', 'fields' => ['summary' => 'X', 'project' => ['name' => 'P']]]);
		});
		$this->jira->createIssue($this->connection, ['project' => 'AKE', 'type' => '1', 'title' => 'New', 'assignee' => 'acc-1']);
		$this->assertSame('acc-1', $captured['fields']['assignee']['accountId']);
	}

	public function testGetEditMetaReturnsCurrentAssignee(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if (str_contains($u, '/transitions')) {
				return $this->response(200, ['transitions' => []]);
			}
			if (str_contains($u, '/editmeta')) {
				return $this->response(200, ['fields' => []]);
			}
			if (str_contains($u, '/issue/AKE-4') && ($o['query']['fields'] ?? '') === 'assignee') {
				return $this->response(200, ['fields' => ['assignee' => ['accountId' => 'acc-7', 'displayName' => 'Dana']]]);
			}
			return $this->response(200, ['fields' => []]);
		});
		$meta = $this->jira->getEditMeta($this->connection, ['key' => 'AKE-4']);
		$this->assertSame(['id' => 'acc-7', 'name' => 'Dana'], $meta['assignee']);
		$this->assertArrayNotHasKey('assignees', $meta);
	}

	public function testGetCreateMetaProjectContextSupportsAssignee(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u) {
			return $this->response(200, ['fields' => []]);
		});
		$meta = $this->jira->getCreateMeta($this->connection, null, 'AKE', '1');
		$this->assertTrue($meta['capabilities']['assignee']);
	}

	public function testCreateIssueSurfacesFieldErrors(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				return $this->response(400, ['errorMessages' => [], 'errors' => ['customfield_10011' => 'Epic Name is required.']]);
			}
			return $this->response(200, ['fields' => []]); // createMetaFields: nothing required
		});
		try {
			$this->jira->createIssue($this->connection, ['project' => 'AKE', 'type' => '1', 'title' => 'New']);
			$this->fail('expected TrackerException');
		} catch (TrackerException $e) {
			$this->assertStringContainsString('customfield_10011: Epic Name is required.', $e->getMessage());
		}
	}

	public function testCreateIssueAutoFillsRequiredOptionField(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['key' => 'AKE-9']);
			}
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [[
					'fieldId' => 'priority', 'name' => 'Priority', 'required' => true, 'hasDefaultValue' => false,
					'schema' => ['type' => 'priority'], 'allowedValues' => [['id' => '3', 'name' => 'High'], ['id' => '4', 'name' => 'Low']],
				]]]);
			}
			return $this->response(200, ['key' => 'AKE-9', 'fields' => ['summary' => 'X', 'project' => ['name' => 'P']]]);
		});
		$this->jira->createIssue($this->connection, ['project' => 'AKE', 'type' => '1', 'title' => 'New']);
		$this->assertSame(['id' => '3'], $captured['fields']['priority'], 'first allowed value auto-filled');
	}

	public function testCreateIssueThrowsOnUnsupportedRequiredField(): void {
		$posted = false;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$posted) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				$posted = true;
				return $this->response(201, ['key' => 'AKE-9']);
			}
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [[
					'fieldId' => 'customfield_x', 'name' => 'Steps to Reproduce', 'required' => true, 'hasDefaultValue' => false,
					'schema' => ['type' => 'string'], 'allowedValues' => [],
				]]]);
			}
			return $this->response(200, []);
		});
		try {
			$this->jira->createIssue($this->connection, ['project' => 'AKE', 'type' => '1', 'title' => 'New']);
			$this->fail('expected TrackerException');
		} catch (TrackerException $e) {
			$this->assertStringContainsString('Steps to Reproduce', $e->getMessage());
		}
		$this->assertFalse($posted, 'must not POST when a required field cannot be filled');
	}

	public function testCreateIssueDoesNotAutoFillCustomTypedField(): void {
		// A Tempo-account-style custom field (numeric value, non-{id} shape) must NOT
		// be auto-filled from its allowed values — report it instead of sending a bad
		// value that Jira rejects with "must be a number".
		$posted = false;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$posted) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				$posted = true;
				return $this->response(201, ['key' => 'AKE-9']);
			}
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [[
					'fieldId' => 'io.tempo.jira__account', 'name' => 'Account', 'required' => true, 'hasDefaultValue' => false,
					'schema' => ['type' => 'com.tempoplugin.tempo-accounts:accounts.customfield'],
					'allowedValues' => [['id' => '42', 'name' => 'Internal']],
				]]]);
			}
			return $this->response(200, []);
		});
		try {
			$this->jira->createIssue($this->connection, ['project' => 'AKE', 'type' => '1', 'title' => 'New']);
			$this->fail('expected TrackerException');
		} catch (TrackerException $e) {
			$this->assertStringContainsString('Account', $e->getMessage());
		}
		$this->assertFalse($posted, 'must not POST a guessed value for a custom-typed field');
	}

	public function testCreateIssueSkipsRequiredFieldWithDefault(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['key' => 'AKE-9']);
			}
			if (str_contains($u, '/createmeta/AKE/issuetypes/1')) {
				return $this->response(200, ['fields' => [[
					'fieldId' => 'priority', 'name' => 'Priority', 'required' => true, 'hasDefaultValue' => true,
					'schema' => ['type' => 'priority'], 'allowedValues' => [['id' => '3']],
				]]]);
			}
			return $this->response(200, ['key' => 'AKE-9', 'fields' => ['summary' => 'X', 'project' => ['name' => 'P']]]);
		});
		$this->jira->createIssue($this->connection, ['project' => 'AKE', 'type' => '1', 'title' => 'New']);
		$this->assertArrayNotHasKey('priority', $captured['fields'], 'Jira applies its own default');
	}

	public function testCreateIssueEncodesTempoAccountAsRawId(): void {
		// The Tempo Account field (option2 base type) must be sent as the raw account
		// id string, not a {id: …} object which Tempo rejects with "must be a number".
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && preg_match('#/issue$#', $u) === 1) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, ['key' => 'B13INTERN-9']);
			}
			if (str_contains($u, '/createmeta/B13INTERN/issuetypes/10000')) {
				return $this->response(200, ['fields' => [[
					'fieldId' => 'customfield_11400', 'name' => 'Account', 'required' => false, 'hasDefaultValue' => false,
					'schema' => ['type' => 'option2', 'custom' => 'com.atlassian.plugins.atlassian-connect-plugin:io.tempo.jira__account', 'customId' => 11400],
					'allowedValues' => [['id' => 15, 'value' => 'b13 intern']],
				]]]);
			}
			return $this->response(200, ['key' => 'B13INTERN-9', 'fields' => ['summary' => 'X', 'project' => ['name' => 'P']]]);
		});
		$this->jira->createIssue($this->connection, [
			'project' => 'B13INTERN', 'type' => '10000', 'title' => 'New',
			'fields' => ['customfield_11400' => '15'],
		]);
		$this->assertSame('15', $captured['fields']['customfield_11400'], 'raw account id, not {id: …}');
	}

	public function testGetRelationTypesEmitsDirectedEntriesAndDedupesSymmetric(): void {
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u) {
			return $this->response(200, ['issueLinkTypes' => [
				['id' => '1', 'name' => 'Blocks', 'inward' => 'is blocked by', 'outward' => 'blocks'],
				['id' => '2', 'name' => 'Relates', 'inward' => 'relates to', 'outward' => 'relates to'],
			]]);
		});
		$types = $this->jira->getRelationTypes($this->connection, ['key' => 'AKE-4']);
		$byId = [];
		foreach ($types as $t) {
			$byId[$t['id']] = $t['name'];
		}
		$this->assertSame('Blocks', $byId['Blocks|outward']);
		$this->assertSame('Is blocked by', $byId['Blocks|inward']);
		// Symmetric type collapses to a single entry.
		$this->assertSame('Relates to', $byId['Relates|outward']);
		$this->assertArrayNotHasKey('Relates|inward', $byId);
	}

	public function testGetRelationsOrientsByInwardOutwardIssue(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, ['key' => 'AKE-4', 'fields' => ['issuelinks' => [
				[
					'id' => '100',
					'type' => ['name' => 'Blocks', 'inward' => 'is blocked by', 'outward' => 'blocks'],
					'outwardIssue' => ['key' => 'AKE-9', 'fields' => ['summary' => 'Downstream', 'status' => ['name' => 'To Do']]],
				],
				[
					'id' => '101',
					'type' => ['name' => 'Blocks', 'inward' => 'is blocked by', 'outward' => 'blocks'],
					'inwardIssue' => ['key' => 'AKE-2', 'fields' => ['summary' => 'Upstream', 'status' => ['name' => 'Done']]],
				],
			]]]);
		});

		$relations = $this->jira->getRelations($this->connection, ['key' => 'AKE-4']);

		$this->assertSame('issuelinks', $captured['query']['fields']);
		$this->assertCount(2, $relations);
		// outwardIssue → current is the source → outward label "blocks".
		$this->assertSame('100', $relations[0]->id);
		$this->assertSame('Blocks|outward', $relations[0]->type);
		$this->assertSame('Blocks', $relations[0]->typeLabel);
		$this->assertSame('AKE-9', $relations[0]->targetDisplayId);
		$this->assertSame('Downstream', $relations[0]->targetTitle);
		$this->assertSame('To Do', $relations[0]->targetStatus);
		$this->assertSame('https://acme.atlassian.net/browse/AKE-9', $relations[0]->targetUrl);
		$this->assertSame(['t' => 'jira', 'c' => 'c1', 'p' => ['key' => 'AKE-9']], Ref::decode($relations[0]->targetRef));
		// inwardIssue → current is the target → inward label "is blocked by".
		$this->assertSame('Blocks|inward', $relations[1]->type);
		$this->assertSame('Is blocked by', $relations[1]->typeLabel);
		$this->assertSame('AKE-2', $relations[1]->targetDisplayId);
	}

	public function testAddRelationPostsDirectedLinkAndReadsBack(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/issueLink')) {
				$captured = ['method' => $m, 'url' => $u, 'options' => $o];
				return $this->response(201, '');
			}
			// getRelations read-back
			return $this->response(200, ['key' => 'AKE-4', 'fields' => ['issuelinks' => [[
				'id' => '303',
				'type' => ['name' => 'Blocks', 'inward' => 'is blocked by', 'outward' => 'blocks'],
				'outwardIssue' => ['key' => 'AKE-9', 'fields' => ['summary' => 'Downstream', 'status' => ['name' => 'To Do']]],
			]]]]);
		});

		$relation = $this->jira->addRelation($this->connection, ['key' => 'AKE-4'], 'Blocks|outward', ['key' => 'AKE-9']);

		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/issueLink', $captured['url']);
		$body = json_decode($captured['options']['body'], true);
		$this->assertSame('Blocks', $body['type']['name']);
		// "outward" → current issue is the outwardIssue (source), target is inward.
		$this->assertSame('AKE-4', $body['outwardIssue']['key']);
		$this->assertSame('AKE-9', $body['inwardIssue']['key']);
		$this->assertSame('303', $relation->id);
		$this->assertSame('Blocks', $relation->typeLabel);
	}

	public function testAddRelationInwardSwapsIssues(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if ($m === 'POST' && str_contains($u, '/issueLink')) {
				$captured = json_decode($o['body'], true);
				return $this->response(201, '');
			}
			return $this->response(200, ['key' => 'AKE-4', 'fields' => ['issuelinks' => []]]);
		});
		// Read-back is empty here; a successful POST must not error — it returns a
		// synthesized relation (the link exists; the UI refetches).
		$relation = $this->jira->addRelation($this->connection, ['key' => 'AKE-4'], 'Blocks|inward', ['key' => 'AKE-2']);
		$this->assertSame('AKE-2', $relation->targetDisplayId);
		$this->assertSame('Blocks|inward', $relation->type);
		// "inward" (current is blocked by target) → target is the outwardIssue (source).
		$this->assertSame('AKE-2', $captured['outwardIssue']['key']);
		$this->assertSame('AKE-4', $captured['inwardIssue']['key']);
	}

	public function testAddRelationFallsBackToTargetMatchOnDirectionFlip(): void {
		// Jira normalized the symmetric link's direction, so the re-read type differs
		// ("Relates|inward" vs the requested "Relates|outward"); still match by target.
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) {
			if ($m === 'POST' && str_contains($u, '/issueLink')) {
				return $this->response(201, '');
			}
			return $this->response(200, ['key' => 'AKE-4', 'fields' => ['issuelinks' => [[
				'id' => '77',
				'type' => ['name' => 'Relates', 'inward' => 'relates to', 'outward' => 'relates to'],
				'inwardIssue' => ['key' => 'AKE-2', 'fields' => ['summary' => 'Other', 'status' => ['name' => 'To Do']]],
			]]]]);
		});
		$relation = $this->jira->addRelation($this->connection, ['key' => 'AKE-4'], 'Relates|outward', ['key' => 'AKE-2']);
		$this->assertSame('77', $relation->id, 'the real link is returned despite the flipped direction');
		$this->assertSame('AKE-2', $relation->targetDisplayId);
	}

	public function testDeleteRelation(): void {
		$captured = null;
		$this->httpClient->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(204, '');
		});
		$this->jira->deleteRelation($this->connection, ['key' => 'AKE-4'], '100');
		$this->assertSame('DELETE', $captured['method']);
		$this->assertStringContainsString('/issueLink/100', $captured['url']);
	}
}
