<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Tests;

use OCA\Unity\Model\Ref;
use OCA\Unity\Service\ConnectionService;
use OCA\Unity\Service\IssueNotifier;
use OCA\Unity\Service\IssueService;
use OCA\Unity\Service\IssueSyncService;
use OCA\Unity\Service\SyncStateService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IssueSyncServiceTest extends TestCase {

	private IssueSyncService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new IssueSyncService(
			$this->createMock(IUserManager::class),
			$this->createMock(ConnectionService::class),
			$this->createMock(IssueService::class),
			$this->createMock(SyncStateService::class),
			$this->createMock(IssueNotifier::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function ref(string $conn, string $key): string {
		return Ref::encode('jira', $conn, ['key' => $key]);
	}

	/** A freshly-fetched issue entry (shape produced by entryFor()). */
	private function cur(string $status, string $hash, ?string $updated, string $displayId = 'ABC-1'): array {
		return ['h' => $hash, 'u' => $updated, 's' => $status, 'd' => $displayId, 'ti' => 'Title', 'tr' => 'jira', 'self' => 'me'];
	}

	/** A stored snapshot entry (shape produced by stateFor()). */
	private function old(string $status, string $hash, ?string $updated, ?string $marker = null): array {
		return ['h' => $hash, 'u' => $updated, 's' => $status, 'c' => $marker, 'd' => 'ABC-1', 'ti' => 'Title', 'tr' => 'jira'];
	}

	private function noComments(): callable {
		return fn (string $ref, ?string $cutoff, string $self): array => [0, $cutoff];
	}

	private function types(array $notes): array {
		return array_map(static fn (array $n): string => $n[0], $notes);
	}

	public function testFirstRunSeedsSilently(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$current = [$ref => $this->cur('open', 'h1', '2026-07-09T10:00:00Z')];
		[$notes, $snapshot] = $this->service->computeNotifications([], $current, ['c1' => true], ['c1'], true, $this->noComments());

		$this->assertSame([], $notes);
		$this->assertArrayHasKey($ref, $snapshot);
		$this->assertSame('h1', $snapshot[$ref]['h']);
		$this->assertNull($snapshot[$ref]['c']);
	}

	public function testNewAssignmentNotifies(): void {
		$existing = $this->ref('c1', 'ABC-1');
		$fresh = $this->ref('c1', 'ABC-2');
		$old = [$existing => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		$current = [
			$existing => $this->cur('open', 'h1', '2026-07-09T10:00:00Z'),
			$fresh => $this->cur('open', 'h9', '2026-07-09T11:00:00Z', 'ABC-2'),
		];
		[$notes] = $this->service->computeNotifications($old, $current, ['c1' => true], ['c1'], false, $this->noComments());

		$this->assertSame(['issue_new'], $this->types($notes));
		$this->assertSame($fresh, $notes[0][1]);
	}

	public function testStatusChangeNotifies(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		// same updatedAt (no comment check), status differs → hash also differs
		$current = [$ref => $this->cur('in progress', 'h2', '2026-07-09T10:00:00Z')];
		[$notes] = $this->service->computeNotifications($old, $current, ['c1' => true], ['c1'], false, $this->noComments());

		$this->assertSame(['issue_status'], $this->types($notes));
		$this->assertSame('in progress', $notes[0][2]['status']);
	}

	public function testFieldUpdateNotifies(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		// updatedAt moved, status unchanged, hash changed, no new comments
		$current = [$ref => $this->cur('open', 'h2', '2026-07-09T12:00:00Z')];
		[$notes] = $this->service->computeNotifications($old, $current, ['c1' => true], ['c1'], false, $this->noComments());

		$this->assertSame(['issue_updated'], $this->types($notes));
	}

	public function testNewCommentTakesPriorityOverUpdate(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		$current = [$ref => $this->cur('open', 'h2', '2026-07-09T12:00:00Z')];
		$counter = fn (string $r, ?string $cutoff, string $self): array => [2, '2026-07-09T12:00:00Z'];
		[$notes, $snapshot] = $this->service->computeNotifications($old, $current, ['c1' => true], ['c1'], false, $counter);

		$this->assertSame(['issue_comment'], $this->types($notes));
		$this->assertSame(2, $notes[0][2]['count']);
		$this->assertSame('2026-07-09T12:00:00Z', $snapshot[$ref]['c']);
	}

	public function testNoChangeProducesNoNotifications(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z', 'm')];
		$current = [$ref => $this->cur('open', 'h1', '2026-07-09T10:00:00Z')];
		[$notes, $snapshot] = $this->service->computeNotifications($old, $current, ['c1' => true], ['c1'], false, $this->noComments());

		$this->assertSame([], $notes);
		$this->assertSame('m', $snapshot[$ref]['c']);
	}

	public function testSelfTouchedIssueIsSuppressedButSnapshotUpdated(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		// status change that would normally notify, but the user just edited it in-app
		$current = [$ref => $this->cur('in progress', 'h2', '2026-07-09T10:00:00Z')];
		[$notes, $snapshot] = $this->service->computeNotifications($old, $current, ['c1' => true], ['c1'], false, $this->noComments(), [$ref => true]);

		$this->assertSame([], $notes);
		// snapshot still advances so a later genuine change is detected
		$this->assertSame('h2', $snapshot[$ref]['h']);
		$this->assertSame('in progress', $snapshot[$ref]['s']);
	}

	public function testSelfTouchedClosedIssueIsSuppressed(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		[$notes, $snapshot] = $this->service->computeNotifications($old, [], ['c1' => true], ['c1'], false, $this->noComments(), [$ref => true]);

		$this->assertSame([], $notes);
		$this->assertArrayNotHasKey($ref, $snapshot);
	}

	public function testClosedIssueNotifiesAndDropsWhenConnectionSucceeded(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		[$notes, $snapshot] = $this->service->computeNotifications($old, [], ['c1' => true], ['c1'], false, $this->noComments());

		$this->assertSame(['issue_closed'], $this->types($notes));
		$this->assertArrayNotHasKey($ref, $snapshot);
	}

	public function testFailedConnectionPreservesSnapshotAndDoesNotNotifyClosed(): void {
		$ref = $this->ref('c1', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		// c1 not in succeeded (it errored this run), but still a known connection
		[$notes, $snapshot] = $this->service->computeNotifications($old, [], [], ['c1'], false, $this->noComments());

		$this->assertSame([], $notes);
		$this->assertArrayHasKey($ref, $snapshot);
	}

	public function testRemovedConnectionDropsSilently(): void {
		$ref = $this->ref('gone', 'ABC-1');
		$old = [$ref => $this->old('open', 'h1', '2026-07-09T10:00:00Z')];
		// connection 'gone' is no longer in the user's connection ids
		[$notes, $snapshot] = $this->service->computeNotifications($old, [], ['c1' => true], ['c1'], false, $this->noComments());

		$this->assertSame([], $notes);
		$this->assertArrayNotHasKey($ref, $snapshot);
	}
}
