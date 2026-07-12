<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use OCA\Unity\Model\Attachment;
use OCA\Unity\Model\Comment;
use OCA\Unity\Model\Connection;
use OCA\Unity\Model\Issue;
use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Model\TimeRecord;
use OCA\Unity\Model\TrackerSearchResult;

interface TrackerClientInterface {

	/** e.g. 'jira', 'gitlab', 'redmine', 'github' */
	public function getTrackerId(): string;

	/**
	 * @return array{ok: bool, message: string, user?: string}
	 */
	public function testConnection(Connection $connection): array;

	public function search(Connection $connection, IssueQuery $query, ?string $cursor = null): TrackerSearchResult;

	/**
	 * @param array $refParts tracker-specific handle parts (from Ref::decode()['p'])
	 */
	public function getIssue(Connection $connection, array $refParts): Issue;

	/**
	 * @param array $refParts
	 * @return Comment[]
	 */
	public function getComments(Connection $connection, array $refParts): array;

	/**
	 * @param array $refParts
	 */
	public function addComment(Connection $connection, array $refParts, string $body): Comment;

	/**
	 * Update the body of an existing comment.
	 *
	 * @param array $refParts
	 */
	public function updateComment(Connection $connection, array $refParts, string $commentId, string $body): Comment;

	/**
	 * Fetch a file/image referenced from an issue (e.g. an inline attachment)
	 * using the connection's credentials. Implementations MUST restrict which
	 * hosts they fetch (SSRF guard).
	 *
	 * @param array $refParts
	 * @return array{body: string, contentType: string}
	 */
	public function fetchFile(Connection $connection, array $refParts, string $src): array;

	public function supportsTimeTracking(): bool;

	/** Whether this tracker exposes a structured attachment list + upload. */
	public function supportsAttachments(): bool;

	/**
	 * List an issue's attachments.
	 *
	 * @param array $refParts
	 * @return Attachment[]
	 */
	public function getAttachments(Connection $connection, array $refParts): array;

	/**
	 * Upload a new attachment to an issue and return it.
	 *
	 * @param array $refParts
	 * @param string $content raw file bytes
	 */
	public function uploadAttachment(Connection $connection, array $refParts, string $filename, string $mimeType, string $content): Attachment;

	/**
	 * Delete an attachment by its provider id.
	 *
	 * @param array $refParts
	 */
	public function deleteAttachment(Connection $connection, array $refParts, string $attachmentId): void;

	/**
	 * List stored time entries / worklogs for an issue.
	 *
	 * @param array $refParts
	 * @return TimeRecord[]
	 */
	public function getTimeRecords(Connection $connection, array $refParts): array;

	/**
	 * Apply changed fields to an issue and return the refreshed issue.
	 *
	 * @param array $refParts
	 * @param array<string, mixed> $changes any of title|description|status|assignee|labels
	 */
	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue;

	/**
	 * Editable-field options for an issue (which fields are supported plus the
	 * available statuses/assignees/labels).
	 *
	 * @param array $refParts
	 * @return array{capabilities: array<string, bool>, statuses: list<array{id: string, name: string}>, assignees: list<array{id: string, name: string}>, labels: list<array{id: string, name: string}>}
	 */
	public function getEditMeta(Connection $connection, array $refParts): array;

	/**
	 * @param array $refParts
	 * @param int $seconds spent time in seconds
	 * @param string $comment optional worklog comment
	 * @param string|null $startedAt ISO-8601 start datetime, or null for "now"
	 */
	public function logTime(Connection $connection, array $refParts, int $seconds, string $comment, ?string $startedAt): void;

	/**
	 * Update an existing time entry / worklog.
	 *
	 * @param array $refParts
	 * @param string $recordId provider-native record id (from a TimeRecord)
	 * @param int $seconds spent time in seconds
	 * @param string $comment optional worklog comment
	 * @param string|null $startedAt ISO-8601 start datetime, or null to keep
	 */
	public function updateTime(Connection $connection, array $refParts, string $recordId, int $seconds, string $comment, ?string $startedAt): void;

	/**
	 * Delete an existing time entry / worklog.
	 *
	 * @param array $refParts
	 * @param string $recordId provider-native record id (from a TimeRecord)
	 */
	public function deleteTime(Connection $connection, array $refParts, string $recordId): void;
}
