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

	/** Whether this tracker supports creating new issues. */
	public function supportsCreate(): bool;

	/**
	 * Targets (projects/repos) the user can create an issue in, plus any required
	 * type list per target (Jira issue types, Redmine trackers). `capabilities.type`
	 * is true when a type must be chosen.
	 *
	 * When $project (and, where the tracker needs it, $type) is given, the result also
	 * carries a `fields` list describing the provider-native fields writable for that
	 * project/type combination. Without $project the `fields` list is empty.
	 *
	 * @param string|null $query optional case-insensitive search term to filter projects by
	 * @param string|null $project selected project/repo id, to resolve field descriptors for
	 * @param string|null $type selected type id, to resolve field descriptors for
	 * @return array{projects: list<array{id: string, name: string, types: list<array{id: string, name: string}>}>, capabilities: array{type: bool, typeRequired: bool}, fields: list<array<string, mixed>>}
	 */
	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array;

	/**
	 * Create a new issue and return it (normalized, with its ref).
	 *
	 * @param array{project: string, type?: string, title: string, description?: string, fields?: array<string, mixed>} $target
	 */
	public function createIssue(Connection $connection, array $target): Issue;

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
	 * @param array<string, mixed> $changes any of title|description|status|assignee|labels|fields
	 */
	public function updateIssue(Connection $connection, array $refParts, array $changes): Issue;

	/**
	 * Editable-field options for an issue (which fields are supported plus the
	 * available statuses/assignees/labels). The optional `fields` list describes
	 * provider-native fields writable on this issue, each carrying its current
	 * `value` for preselecting the edit form. When `capabilities.type` is true the
	 * issue's type can be changed: `types` lists the choices and `typeId` is the
	 * current one. Passing `$type` returns the `fields` for that prospective type
	 * (used to reload the form when the user switches type).
	 *
	 * @param array $refParts
	 * @param string|null $type prospective type id to describe fields for
	 * @return array{capabilities: array<string, bool>, statuses: list<array{id: string, name: string}>, assignees: list<array{id: string, name: string}>, labels: list<array{id: string, name: string}>, fields?: list<array<string, mixed>>, types?: list<array{id: string, name: string}>, typeId?: string}
	 */
	public function getEditMeta(Connection $connection, array $refParts, ?string $type = null): array;

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
