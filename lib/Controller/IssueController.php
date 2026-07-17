<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Controller;

use OCA\Unity\Model\IssueQuery;
use OCA\Unity\Service\IssueNotifier;
use OCA\Unity\Service\IssueService;
use OCA\Unity\Service\Tracker\TrackerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

class IssueController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IssueService $issueService,
		private IssueNotifier $notifier,
		private IRootFolder $rootFolder,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function search(
		string $term = '',
		string $sort = 'updated',
		string $order = 'desc',
		string $assignedToMe = 'false',
		string $showClosed = 'false',
		string $connections = '',
		string $cursors = '',
		int $limit = 30,
	): DataResponse {
		$query = new IssueQuery(
			$term,
			$sort,
			$order,
			$assignedToMe === 'true' || $assignedToMe === '1',
			$showClosed === 'true' || $showClosed === '1',
			$limit,
		);
		$connectionIds = $connections === '' ? [] : explode(',', $connections);
		$decodedCursors = $cursors === '' ? [] : json_decode($cursors, true);
		$result = $this->issueService->search(
			$this->userId ?? '',
			$query,
			$connectionIds,
			is_array($decodedCursors) ? $decodedCursors : [],
		);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function createMeta(string $connection, string $query = '', string $project = '', string $type = ''): DataResponse {
		try {
			$search = trim($query) === '' ? null : trim($query);
			return new DataResponse($this->issueService->getCreateMeta(
				$this->userId ?? '',
				$connection,
				$search,
				$project === '' ? null : $project,
				$type === '' ? null : $type,
			));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * @param string[]|null $labels
	 * @param array<string, mixed> $fields provider-native field values keyed by descriptor id
	 */
	#[NoAdminRequired]
	public function create(string $connection, string $project, string $title, string $description = '', string $type = '', string $assignee = '', ?array $labels = null, array $fields = []): DataResponse {
		if (trim($title) === '') {
			return new DataResponse(['error' => 'A title is required'], Http::STATUS_BAD_REQUEST);
		}
		if (trim($project) === '') {
			return new DataResponse(['error' => 'A project is required'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$target = [
				'project' => $project,
				'type' => $type,
				'title' => $title,
				'description' => $description,
				'assignee' => $assignee,
				'fields' => $fields,
			];
			if ($labels !== null) {
				$target['labels'] = $labels;
			}
			$issue = $this->issueService->createIssue($this->userId ?? '', $connection, $target);
			return new DataResponse($issue, Http::STATUS_CREATED);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function show(string $ref): DataResponse {
		try {
			return new DataResponse($this->issueService->getIssue($this->userId ?? '', $ref));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function comments(string $ref): DataResponse {
		try {
			return new DataResponse($this->issueService->getComments($this->userId ?? '', $ref));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function attachments(string $ref): DataResponse {
		try {
			return new DataResponse($this->issueService->getAttachments($this->userId ?? '', $ref));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function uploadAttachment(string $ref): DataResponse {
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}
		$content = file_get_contents((string)$file['tmp_name']);
		if ($content === false) {
			return new DataResponse(['error' => 'Could not read uploaded file'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$attachment = $this->issueService->uploadAttachment(
				$this->userId ?? '',
				$ref,
				(string)($file['name'] ?? 'file'),
				(string)($file['type'] ?? '') ?: 'application/octet-stream',
				$content,
			);
			return new DataResponse($attachment, Http::STATUS_CREATED);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Attach an existing Nextcloud file (chosen via the file picker) to the issue by
	 * path — the bytes are read server-side, so nothing round-trips through the browser.
	 * The path is resolved inside the user's own files root, which scopes it to their
	 * files and rejects traversal outside it.
	 */
	#[NoAdminRequired]
	public function attachFile(string $ref, string $path): DataResponse {
		if (trim($path) === '') {
			return new DataResponse(['error' => 'No path given'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$node = $this->rootFolder->getUserFolder($this->userId ?? '')->get($path);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
		if (!$node instanceof File) {
			return new DataResponse(['error' => 'Not a file'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$content = $node->getContent();
			$attachment = $this->issueService->uploadAttachment(
				$this->userId ?? '',
				$ref,
				$node->getName(),
				$node->getMimeType() ?: 'application/octet-stream',
				$content,
			);
			return new DataResponse($attachment, Http::STATUS_CREATED);
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => 'Could not read file'], Http::STATUS_BAD_REQUEST);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Upload a file to the tracker for inline embedding in a body (e.g. GitLab's
	 * /uploads endpoint) and return the markdown snippet the editor inserts.
	 */
	#[NoAdminRequired]
	public function uploadInline(string $ref): DataResponse {
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}
		$content = file_get_contents((string)$file['tmp_name']);
		if ($content === false) {
			return new DataResponse(['error' => 'Could not read uploaded file'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$result = $this->issueService->uploadInline(
				$this->userId ?? '',
				$ref,
				(string)($file['name'] ?? 'file'),
				(string)($file['type'] ?? '') ?: 'application/octet-stream',
				$content,
			);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function deleteAttachment(string $ref, string $attachmentId): DataResponse {
		try {
			$this->issueService->deleteAttachment($this->userId ?? '', $ref, $attachmentId);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function relations(string $ref): DataResponse {
		try {
			return new DataResponse($this->issueService->getRelations($this->userId ?? '', $ref));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function addRelation(string $ref, string $type, string $target): DataResponse {
		if (trim($type) === '' || trim($target) === '') {
			return new DataResponse(['error' => 'A relation type and target are required'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse(
				$this->issueService->addRelation($this->userId ?? '', $ref, $type, $target),
				Http::STATUS_CREATED,
			);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function deleteRelation(string $ref, string $relationId): DataResponse {
		try {
			$this->issueService->deleteRelation($this->userId ?? '', $ref, $relationId);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function file(string $ref, string $src): DataDisplayResponse {
		try {
			$file = $this->issueService->fetchFile($this->userId ?? '', $ref, $src);
		} catch (TrackerException $e) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$body = $file['body'];
		$contentType = $file['contentType'];
		$length = strlen($body);

		// Honour a single byte range so <audio>/<video> can play and seek: media
		// elements request ranges and Safari refuses to play a resource served without
		// range support. The whole file is already in memory, so we just slice it.
		$range = $this->parseRange($this->request->getHeader('Range'), $length);
		if ($range === false) {
			// A range was asked for but is unsatisfiable.
			return new DataDisplayResponse('', Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, [
				'Content-Range' => 'bytes */' . $length,
				'Accept-Ranges' => 'bytes',
			]);
		}
		if ($range !== null) {
			[$start, $end] = $range;
			$response = new DataDisplayResponse(
				substr($body, $start, $end - $start + 1),
				Http::STATUS_PARTIAL_CONTENT,
				[
					'Content-Type' => $contentType,
					'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $length,
					'Accept-Ranges' => 'bytes',
				],
			);
			$response->cacheFor(3600, false, true);
			return $response;
		}

		$response = new DataDisplayResponse(
			$body,
			Http::STATUS_OK,
			['Content-Type' => $contentType, 'Accept-Ranges' => 'bytes'],
		);
		$response->cacheFor(3600, false, true);
		return $response;
	}

	/**
	 * Parse a single-range HTTP `Range` header against a body of $length bytes.
	 * Returns [start, end] (inclusive) for a satisfiable range, null when no range
	 * was requested (serve the whole body), or false when the range is unsatisfiable
	 * (respond 416). Only a single range is supported — media elements never send
	 * multi-ranges — so anything else falls through to the full body.
	 *
	 * @return array{0: int, 1: int}|null|false
	 */
	private function parseRange(string $header, int $length): array|null|false {
		if ($header === '' || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1) {
			return null;
		}
		[$first, $last] = [$m[1], $m[2]];
		if ($first === '' && $last === '') {
			return null;
		}
		if ($first === '') {
			// Suffix range: the last N bytes.
			$start = max(0, $length - (int)$last);
			$end = $length - 1;
		} else {
			$start = (int)$first;
			$end = $last === '' ? $length - 1 : min((int)$last, $length - 1);
		}
		if ($length === 0 || $start > $end || $start >= $length) {
			return false;
		}
		return [$start, $end];
	}

	#[NoAdminRequired]
	public function addComment(string $ref, string $body): DataResponse {
		if (trim($body) === '') {
			return new DataResponse(['error' => 'Empty comment'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->issueService->addComment($this->userId ?? '', $ref, $body), Http::STATUS_CREATED);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * @param string[]|null $labels
	 * @param array<string, mixed>|null $fields provider-native field values keyed by descriptor id
	 */
	#[NoAdminRequired]
	public function update(
		string $ref,
		?string $title = null,
		?string $description = null,
		?string $status = null,
		?string $assignee = null,
		?array $labels = null,
		?array $fields = null,
		?string $type = null,
	): DataResponse {
		$changes = [];
		if ($title !== null) {
			$changes['title'] = $title;
		}
		if ($description !== null) {
			$changes['description'] = $description;
		}
		if ($status !== null) {
			$changes['status'] = $status;
		}
		if ($assignee !== null) {
			$changes['assignee'] = $assignee;
		}
		if ($type !== null && $type !== '') {
			$changes['type'] = $type;
		}
		if ($labels !== null) {
			$changes['labels'] = $labels;
		}
		if ($fields !== null && $fields !== []) {
			$changes['fields'] = $fields;
		}
		if ($changes === []) {
			return new DataResponse(['error' => 'No changes'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->issueService->updateIssue($this->userId ?? '', $ref, $changes));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function editMeta(string $ref, string $type = ''): DataResponse {
		try {
			return new DataResponse($this->issueService->getEditMeta($this->userId ?? '', $ref, $type === '' ? null : $type));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function assignees(string $ref, string $query = ''): DataResponse {
		try {
			return new DataResponse($this->issueService->searchAssignees($this->userId ?? '', $ref, $query));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function createAssignees(string $connection, string $project = '', string $query = ''): DataResponse {
		try {
			return new DataResponse($this->issueService->searchCreateAssignees($this->userId ?? '', $connection, $project, $query));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function timeRecords(string $ref): DataResponse {
		try {
			return new DataResponse($this->issueService->getTimeRecords($this->userId ?? '', $ref));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function updateComment(string $ref, string $commentId, string $body): DataResponse {
		if (trim($body) === '') {
			return new DataResponse(['error' => 'Empty comment'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->issueService->updateComment($this->userId ?? '', $ref, $commentId, $body));
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function deleteComment(string $ref, string $commentId): DataResponse {
		try {
			$this->issueService->deleteComment($this->userId ?? '', $ref, $commentId);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function logTime(string $ref, int $seconds, string $comment = '', string $startedAt = ''): DataResponse {
		if ($seconds <= 0) {
			return new DataResponse(['error' => 'Invalid duration'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->issueService->logTime(
				$this->userId ?? '',
				$ref,
				$seconds,
				$comment,
				$startedAt === '' ? null : $startedAt,
			);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function updateTime(string $ref, string $recordId, int $seconds, string $comment = '', string $startedAt = ''): DataResponse {
		if ($seconds <= 0) {
			return new DataResponse(['error' => 'Invalid duration'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->issueService->updateTime(
				$this->userId ?? '',
				$ref,
				$recordId,
				$seconds,
				$comment,
				$startedAt === '' ? null : $startedAt,
			);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function deleteTime(string $ref, string $recordId): DataResponse {
		try {
			$this->issueService->deleteTime($this->userId ?? '', $ref, $recordId);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (TrackerException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/** Dismiss any pending sync notification for an issue once the user views it. */
	#[NoAdminRequired]
	public function markSeen(string $ref): DataResponse {
		$this->notifier->dismiss($this->userId ?? '', $ref);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}
}
