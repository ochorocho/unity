<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Connection;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\Ref;
use OCA\Unity\Service\AsanaHtmlConverter;
use OCA\Unity\Service\Tracker\AsanaClient;
use OCA\Unity\Service\Tracker\TrackerException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AsanaClientTest extends TestCase {

	private IClient&MockObject $http;
	private AsanaClient $client;
	private Connection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->http = $this->createMock(IClient::class);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->http);
		$this->client = new AsanaClient($service, new NullLogger(), new AsanaHtmlConverter());
		// A configured workspace avoids the extra GET /workspaces resolution call.
		$this->connection = new Connection('a1', 'asana', 'Asana', '', '', 'tok', '', ['workspace' => 'ws1']);
	}

	private function response(int $status, array|string $body, array $headers = []): IResponse&MockObject {
		$r = $this->createMock(IResponse::class);
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn(is_array($body) ? (string)json_encode($body) : $body);
		$r->method('getHeader')->willReturnCallback(static fn (string $k): string => $headers[$k] ?? '');
		return $r;
	}

	/** Wrap a payload in Asana's {"data": …[, "next_page": …]} envelope. */
	private function envelope(array $data, ?string $offset = null): array {
		$body = ['data' => $data];
		if ($offset !== null) {
			$body['next_page'] = ['offset' => $offset];
		}
		return $body;
	}

	public function testSupportsFlags(): void {
		$this->assertSame('asana', $this->client->getTrackerId());
		$this->assertTrue($this->client->supportsCreate());
		$this->assertTrue($this->client->supportsAttachments());
		$this->assertTrue($this->client->supportsTimeTracking());
	}

	public function testSearchMyTasksListsAssignedAndPaginates(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, $this->envelope([
				[
					'gid' => '111', 'name' => 'Fix bug', 'completed' => false,
					'assignee' => ['name' => 'Ada'],
					'projects' => [['name' => 'Website']],
					'tags' => [['name' => 'urgent']],
					'permalink_url' => 'https://app.asana.com/0/1/111',
					'created_at' => '2026-01-01T00:00:00Z', 'modified_at' => '2026-02-01T00:00:00Z',
				],
			], 'off2'));
		});

		$result = $this->client->search($this->connection, new IssueQuery());

		$this->assertStringContainsString('/tasks', $captured['url']);
		$this->assertSame('me', $captured['options']['query']['assignee']);
		$this->assertSame('ws1', $captured['options']['query']['workspace']);
		$this->assertSame('now', $captured['options']['query']['completed_since'], 'closed hidden by default');
		$this->assertSame('off2', $result->nextCursor);
		$this->assertCount(1, $result->issues);
		$issue = $result->issues[0];
		$this->assertSame('#111', $issue->displayId);
		$this->assertSame('incomplete', $issue->status);
		$this->assertSame('Ada', $issue->assignee);
		$this->assertSame(['urgent'], $issue->labels);
		$this->assertSame('Website', $issue->project);
		$this->assertSame(['t' => 'asana', 'c' => 'a1', 'p' => ['gid' => '111']], Ref::decode($issue->ref));
	}

	public function testSearchShowClosedOmitsCompletedSince(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = $o;
			return $this->response(200, $this->envelope([]));
		});
		$this->client->search($this->connection, new IssueQuery(showClosed: true));
		$this->assertArrayNotHasKey('completed_since', $captured['query']);
	}

	public function testSearchTypeaheadFiltersClosedClientSide(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['url' => $u, 'options' => $o];
			return $this->response(200, $this->envelope([
				['gid' => '1', 'name' => 'Open one', 'completed' => false],
				['gid' => '2', 'name' => 'Done one', 'completed' => true],
			]));
		});

		$result = $this->client->search($this->connection, new IssueQuery(term: 'one'));

		$this->assertStringContainsString('/workspaces/ws1/typeahead', $captured['url']);
		$this->assertSame('task', $captured['options']['query']['resource_type']);
		$this->assertSame('one', $captured['options']['query']['query']);
		$this->assertCount(1, $result->issues, 'completed task filtered client-side');
		$this->assertSame('#1', $result->issues[0]->displayId);
		$this->assertNull($result->nextCursor, 'typeahead is not paginated');
	}

	public function testSearchByNumericGidFetchesTask(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u];
			return $this->response(200, $this->envelope(['gid' => '999', 'name' => 'Direct', 'completed' => false]));
		});
		$result = $this->client->search($this->connection, new IssueQuery(term: '999'));
		$this->assertSame('GET', $captured['method']);
		$this->assertStringContainsString('/tasks/999', $captured['url']);
		$this->assertCount(1, $result->issues);
		$this->assertSame('#999', $result->issues[0]->displayId);
	}

	public function testGetIssueMapsRenderedDescription(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(200, $this->envelope([
				'gid' => '5', 'name' => 'T', 'notes' => 'raw', 'html_notes' => '<body>raw</body>',
				'completed' => true, 'memberships' => [['project' => ['name' => 'Proj']]],
			]));
		});
		$issue = $this->client->getIssue($this->connection, ['gid' => '5']);
		$this->assertSame('raw', $issue->description);
		$this->assertSame('<body>raw</body>', $issue->renderedDescription);
		$this->assertSame('markdown', $issue->bodyFormat);
		$this->assertSame('completed', $issue->status);
		$this->assertSame('Proj', $issue->project);
		$this->assertSame('', $issue->author, 'Asana tasks expose no creator');
	}

	public function testGetIssueRendersTaskListAsCheckboxes(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(200, $this->envelope([
				'gid' => '5', 'name' => 'T',
				'html_notes' => '<body><ul><li>[ ] todo</li><li>[x] done</li></ul></body>',
				'completed' => false, 'memberships' => [['project' => ['name' => 'Proj']]],
			]));
		});
		$issue = $this->client->getIssue($this->connection, ['gid' => '5']);
		// Editable source stays markdown task list…
		$this->assertSame("- [ ] todo\n- [x] done", $issue->description);
		// …while the rendered HTML carries real, checkbox markup for display + toggle.
		$this->assertStringContainsString('class="task-list-item"', (string)$issue->renderedDescription);
		$this->assertStringContainsString('<input type="checkbox" disabled checked>', (string)$issue->renderedDescription);
		$this->assertStringNotContainsString('[ ]', (string)$issue->renderedDescription);
	}

	public function testGetCommentsFiltersSystemStoriesAndGatesOwnership(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/users/me')) {
				return $this->response(200, $this->envelope(['gid' => 'me1', 'name' => 'Me']));
			}
			return $this->response(200, $this->envelope([
				['gid' => 'c1', 'resource_subtype' => 'comment_added', 'text' => 'mine', 'created_by' => ['gid' => 'me1', 'name' => 'Me'], 'created_at' => 'x'],
				['gid' => 'c2', 'resource_subtype' => 'comment_added', 'text' => 'theirs', 'created_by' => ['gid' => 'other', 'name' => 'Other'], 'created_at' => 'y'],
				['gid' => 's1', 'resource_subtype' => 'assigned', 'text' => 'assigned to X', 'created_by' => ['gid' => 'me1', 'name' => 'Me'], 'created_at' => 'z'],
			]));
		});
		$comments = $this->client->getComments($this->connection, ['gid' => '5']);
		$this->assertCount(2, $comments, 'system story dropped');
		$this->assertTrue($comments[0]->editable);
		$this->assertTrue($comments[0]->deletable);
		$this->assertFalse($comments[1]->editable);
		$this->assertFalse($comments[1]->deletable);
	}

	public function testAddCommentPostsHtmlText(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/users/me')) {
				return $this->response(200, $this->envelope(['gid' => 'me1']));
			}
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, $this->envelope(['gid' => 'c9', 'html_text' => '<body>hi</body>', 'created_by' => ['gid' => 'me1', 'name' => 'Me'], 'resource_subtype' => 'comment_added']));
		});
		$comment = $this->client->addComment($this->connection, ['gid' => '5'], 'hi');
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/tasks/5/stories', $captured['url']);
		// Markdown body is converted to Asana restricted HTML on write.
		$this->assertSame('<body>hi</body>', json_decode($captured['options']['body'], true)['data']['html_text']);
		// And the returned html_text is converted back to markdown for the body.
		$this->assertSame('hi', $comment->body);
		$this->assertTrue($comment->editable);
	}

	public function testAddCommentMultilineUsesNewlineNotBr(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/users/me')) {
				return $this->response(200, $this->envelope(['gid' => 'me1']));
			}
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, $this->envelope(['gid' => 'c9', 'html_text' => "<body>a\nb</body>", 'created_by' => ['gid' => 'me1', 'name' => 'Me'], 'resource_subtype' => 'comment_added']));
		});
		$this->client->addComment($this->connection, ['gid' => '5'], "a\nb");
		// Asana rejects <br>; a multi-line comment must serialize with a literal newline.
		$sent = json_decode($captured['options']['body'], true)['data']['html_text'];
		$this->assertSame("<body>a\nb</body>", $sent);
		$this->assertStringNotContainsString('<br', $sent);
	}

	public function testUpdateAndDeleteComment(): void {
		$calls = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o = []) use (&$calls) {
			if (str_contains($u, '/users/me')) {
				return $this->response(200, $this->envelope(['gid' => 'me1']));
			}
			$calls[] = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(200, $this->envelope(['gid' => 'c1', 'text' => 'edited', 'created_by' => ['gid' => 'me1'], 'resource_subtype' => 'comment_added']));
		});
		$this->client->updateComment($this->connection, ['gid' => '5'], 'c1', 'edited');
		$this->client->deleteComment($this->connection, ['gid' => '5'], 'c1');
		$this->assertSame('PUT', $calls[0]['method']);
		$this->assertStringContainsString('/stories/c1', $calls[0]['url']);
		$this->assertSame('<body>edited</body>', json_decode($calls[0]['options']['body'], true)['data']['html_text']);
		$this->assertSame('DELETE', $calls[1]['method']);
		$this->assertStringContainsString('/stories/c1', $calls[1]['url']);
	}

	public function testGetAttachmentsUsesMarkerSrc(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(200, $this->envelope([
				['gid' => 'att1', 'name' => 'diagram.png', 'size' => 42, 'created_at' => 'x'],
			]));
		});
		$attachments = $this->client->getAttachments($this->connection, ['gid' => '5']);
		$this->assertCount(1, $attachments);
		$this->assertSame('asana-attachment:att1', $attachments[0]->src);
		$this->assertSame('image/png', $attachments[0]->mimeType);
		$this->assertSame(42, $attachments[0]->size);
	}

	public function testFetchFileResolvesMarkerAndDropsAuth(): void {
		$captured = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured[] = ['url' => $u, 'options' => $o];
			if (str_contains($u, '/attachments/att1')) {
				return $this->response(200, $this->envelope([
					'download_url' => 'https://asana-user-private-us-east-1.s3.amazonaws.com/x?sig=1',
				]));
			}
			return $this->response(200, 'BYTES', ['Content-Type' => 'image/png']);
		});

		$file = $this->client->fetchFile($this->connection, ['gid' => '5'], 'asana-attachment:att1');

		// First request re-resolves the fresh download URL (authenticated).
		$this->assertStringContainsString('/attachments/att1', $captured[0]['url']);
		$this->assertStringStartsWith('Bearer ', $captured[0]['options']['headers']['Authorization']);
		// Second request fetches the pre-signed S3 URL with NO Authorization header.
		$this->assertStringContainsString('s3.amazonaws.com', $captured[1]['url']);
		$this->assertArrayNotHasKey('Authorization', $captured[1]['options']['headers']);
		$this->assertSame('BYTES', $file['body']);
		$this->assertSame('image/png', $file['contentType']);
	}

	public function testFetchFileRejectsForeignHost(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(200, 'X');
		});
		$this->expectException(TrackerException::class);
		$this->client->fetchFile($this->connection, ['gid' => '5'], 'https://evil.example.com/x.png');
	}

	public function testGetTimeRecordsConvertsMinutes(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/users/me')) {
				return $this->response(200, $this->envelope(['gid' => 'me1']));
			}
			return $this->response(200, $this->envelope([
				['gid' => 'te1', 'duration_minutes' => 90, 'entered_on' => '2026-07-01', 'created_by' => ['gid' => 'me1', 'name' => 'Me']],
			]));
		});
		$records = $this->client->getTimeRecords($this->connection, ['gid' => '5']);
		$this->assertCount(1, $records);
		$this->assertSame(5400, $records[0]->seconds);
		$this->assertSame('2026-07-01', $records[0]->date);
		$this->assertTrue($records[0]->editable);
	}

	public function testGetTimeRecordsDegradesOnPremiumGate(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(402, $this->envelope([]));
		});
		$this->assertSame([], $this->client->getTimeRecords($this->connection, ['gid' => '5']));
	}

	public function testLogTimeSendsMinutesAndDate(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, $this->envelope(['gid' => 'te1']));
		});
		$this->client->logTime($this->connection, ['gid' => '5'], 3600, 'ignored', '2026-07-10T09:00:00Z');
		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/tasks/5/time_tracking_entries', $captured['url']);
		$body = json_decode($captured['options']['body'], true)['data'];
		$this->assertSame(60, $body['duration_minutes']);
		$this->assertSame('2026-07-10', $body['entered_on']);
	}

	public function testLogTimeThrowsOnPremiumGate(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(402, $this->envelope([]));
		});
		$this->expectException(TrackerException::class);
		$this->client->logTime($this->connection, ['gid' => '5'], 3600, '', null);
	}

	public function testCreateIssueWithCustomFields(): void {
		$captured = null;
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o) use (&$captured) {
			if (str_contains($u, '/custom_field_settings')) {
				return $this->response(200, $this->envelope([
					['custom_field' => [
						'gid' => 'cf1', 'name' => 'Priority', 'resource_subtype' => 'enum',
						'enum_options' => [['gid' => 'opt-high', 'name' => 'High', 'enabled' => true]],
					]],
				]));
			}
			$captured = ['method' => $m, 'url' => $u, 'options' => $o];
			return $this->response(201, $this->envelope(['gid' => 'new1', 'name' => 'Task', 'completed' => false]));
		});

		$issue = $this->client->createIssue($this->connection, [
			'project' => 'proj1',
			'title' => 'Task',
			'description' => 'desc',
			'fields' => ['cf1' => 'opt-high'],
		]);

		$this->assertSame('POST', $captured['method']);
		$this->assertStringContainsString('/tasks', $captured['url']);
		$body = json_decode($captured['options']['body'], true)['data'];
		$this->assertSame('Task', $body['name']);
		$this->assertSame(['proj1'], $body['projects']);
		$this->assertSame('ws1', $body['workspace']);
		$this->assertSame(['cf1' => 'opt-high'], $body['custom_fields']);
		$this->assertSame('#new1', $issue->displayId);
	}

	public function testGetCreateMetaListsProjects(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			return $this->response(200, $this->envelope([
				['gid' => 'p1', 'name' => 'Alpha'],
				['gid' => 'p2', 'name' => 'Beta'],
			]));
		});
		$meta = $this->client->getCreateMeta($this->connection);
		$this->assertFalse($meta['capabilities']['type']);
		$this->assertSame([
			['id' => 'p1', 'name' => 'Alpha', 'types' => []],
			['id' => 'p2', 'name' => 'Beta', 'types' => []],
		], $meta['projects']);
	}

	public function testGetCreateMetaAdvertisesDueDateAheadOfCustomFields(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			return $this->response(200, $this->envelope([
				['custom_field' => [
					'gid' => 'cf1', 'name' => 'Priority', 'resource_subtype' => 'text',
				]],
			]));
		});

		$meta = $this->client->getCreateMeta($this->connection, null, 'proj1');

		$this->assertSame([], $meta['projects']);
		$this->assertSame('due_on', $meta['fields'][0]['id']);
		$this->assertSame('Due date', $meta['fields'][0]['name']);
		$this->assertSame('date', $meta['fields'][0]['type']);
		$this->assertArrayNotHasKey('value', $meta['fields'][0]);
		$this->assertSame('cf1', $meta['fields'][1]['id']);
	}

	public function testGetEditMetaAdvertisesDueDateWithCurrentValue(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/tasks/5')) {
				return $this->response(200, $this->envelope([
					'due_on' => '2026-07-20',
					'custom_fields' => [
						['gid' => 'cf1', 'name' => 'Priority', 'resource_subtype' => 'text', 'text_value' => 'high'],
					],
				]));
			}
			return $this->response(200, $this->envelope([]));
		});

		$meta = $this->client->getEditMeta($this->connection, ['gid' => '5']);

		$this->assertSame('due_on', $meta['fields'][0]['id']);
		$this->assertSame('date', $meta['fields'][0]['type']);
		$this->assertSame('2026-07-20', $meta['fields'][0]['value']);
		$this->assertSame('cf1', $meta['fields'][1]['id']);
	}

	public function testGetEditMetaDueDateHasNoValueWhenUnset(): void {
		$this->http->method('request')->willReturnCallback(function ($m, $u) {
			if (str_contains($u, '/tasks/5')) {
				return $this->response(200, $this->envelope([
					'due_on' => null,
					'custom_fields' => [],
				]));
			}
			return $this->response(200, $this->envelope([]));
		});

		$meta = $this->client->getEditMeta($this->connection, ['gid' => '5']);

		$this->assertSame('due_on', $meta['fields'][0]['id']);
		$this->assertArrayNotHasKey('value', $meta['fields'][0]);
	}

	public function testUpdateIssueTogglesStatusAndDiffsTagsByName(): void {
		$calls = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o = []) use (&$calls) {
			$calls[] = ['method' => $m, 'url' => $u, 'options' => $o];
			// The tag-diff read asks for gid + name.
			if ($m === 'GET' && ($o['query']['opt_fields'] ?? '') === 'tags.gid,tags.name') {
				return $this->response(200, $this->envelope(['tags' => [['gid' => 't-old', 'name' => 'OldTag']]]));
			}
			// Workspace tag list, used to resolve added names to gids.
			if ($m === 'GET' && str_contains($u, '/tags')) {
				return $this->response(200, $this->envelope([
					['gid' => 't-new', 'name' => 'NewTag'],
					['gid' => 't-old', 'name' => 'OldTag'],
				]));
			}
			if ($m === 'GET') {
				return $this->response(200, $this->envelope(['gid' => '5', 'name' => 'T', 'completed' => true]));
			}
			return $this->response(200, $this->envelope(['gid' => '5']));
		});

		$this->client->updateIssue($this->connection, ['gid' => '5'], [
			'status' => 'completed',
			'labels' => ['NewTag'],
		]);

		$put = array_values(array_filter($calls, static fn ($c) => $c['method'] === 'PUT'))[0];
		$this->assertStringContainsString('/tasks/5', $put['url']);
		$this->assertTrue(json_decode($put['options']['body'], true)['data']['completed']);

		$add = array_values(array_filter($calls, static fn ($c) => $c['method'] === 'POST' && str_contains($c['url'], '/tasks/5/addTag')));
		$remove = array_values(array_filter($calls, static fn ($c) => $c['method'] === 'POST' && str_contains($c['url'], '/tasks/5/removeTag')));
		$this->assertCount(1, $add);
		$this->assertCount(1, $remove);
		// The name 'NewTag' must be resolved to its gid before writing.
		$this->assertSame('t-new', json_decode($add[0]['options']['body'], true)['data']['tag']);
		$this->assertSame('t-old', json_decode($remove[0]['options']['body'], true)['data']['tag']);
	}

	public function testUpdateIssueLabelsNoOpWhenTagAlreadyPresent(): void {
		$calls = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o = []) use (&$calls) {
			$calls[] = ['method' => $m, 'url' => $u, 'options' => $o];
			if ($m === 'GET' && ($o['query']['opt_fields'] ?? '') === 'tags.gid,tags.name') {
				return $this->response(200, $this->envelope(['tags' => [['gid' => 't-old', 'name' => 'OldTag']]]));
			}
			return $this->response(200, $this->envelope(['gid' => '5']));
		});

		$this->client->updateIssue($this->connection, ['gid' => '5'], ['labels' => ['OldTag']]);

		$mutations = array_filter($calls, static fn ($c) => $c['method'] === 'POST' && (str_contains($c['url'], '/addTag') || str_contains($c['url'], '/removeTag')));
		$this->assertSame([], array_values($mutations));
	}

	public function testUpdateIssueLabelsSkipsUnknownNameButStillRemoves(): void {
		$calls = [];
		$this->http->method('request')->willReturnCallback(function ($m, $u, $o = []) use (&$calls) {
			$calls[] = ['method' => $m, 'url' => $u, 'options' => $o];
			if ($m === 'GET' && ($o['query']['opt_fields'] ?? '') === 'tags.gid,tags.name') {
				return $this->response(200, $this->envelope(['tags' => [['gid' => 't-old', 'name' => 'OldTag']]]));
			}
			if ($m === 'GET' && str_contains($u, '/tags')) {
				return $this->response(200, $this->envelope([['gid' => 't-old', 'name' => 'OldTag']]));
			}
			return $this->response(200, $this->envelope(['gid' => '5']));
		});

		$this->client->updateIssue($this->connection, ['gid' => '5'], ['labels' => ['Ghost']]);

		$add = array_filter($calls, static fn ($c) => $c['method'] === 'POST' && str_contains($c['url'], '/addTag'));
		$remove = array_filter($calls, static fn ($c) => $c['method'] === 'POST' && str_contains($c['url'], '/removeTag'));
		$this->assertSame([], array_values($add));
		$this->assertCount(1, $remove);
	}

	public function testExtractErrorReadsAsanaEnvelope(): void {
		$this->http->method('request')->willReturnCallback(function () {
			return $this->response(400, ['errors' => [['message' => 'Bad input: name is required']]]);
		});
		try {
			$this->client->getIssue($this->connection, ['gid' => '5']);
			$this->fail('expected TrackerException');
		} catch (TrackerException $e) {
			$this->assertStringContainsString('Bad input: name is required', $e->getMessage());
		}
	}
}
