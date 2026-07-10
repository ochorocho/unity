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
use OCP\IRequest;

class IssueController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IssueService $issueService,
		private IssueNotifier $notifier,
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
		string $connections = '',
		string $cursors = '',
		int $limit = 30,
	): DataResponse {
		$query = new IssueQuery(
			$term,
			$sort,
			$order,
			$assignedToMe === 'true' || $assignedToMe === '1',
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
	#[NoCSRFRequired]
	public function file(string $ref, string $src): DataDisplayResponse {
		try {
			$file = $this->issueService->fetchFile($this->userId ?? '', $ref, $src);
			$response = new DataDisplayResponse(
				$file['body'],
				Http::STATUS_OK,
				['Content-Type' => $file['contentType']],
			);
			$response->cacheFor(3600, false, true);
			return $response;
		} catch (TrackerException $e) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
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
	 */
	#[NoAdminRequired]
	public function update(
		string $ref,
		?string $title = null,
		?string $description = null,
		?string $status = null,
		?string $assignee = null,
		?array $labels = null,
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
		if ($labels !== null) {
			$changes['labels'] = $labels;
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
	public function editMeta(string $ref): DataResponse {
		try {
			return new DataResponse($this->issueService->getEditMeta($this->userId ?? '', $ref));
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
