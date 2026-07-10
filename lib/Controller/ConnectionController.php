<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Controller;

use OCA\Unity\Model\Connection;
use OCA\Unity\Service\ConnectionService;
use OCA\Unity\Service\TrackerManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ConnectionController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ConnectionService $connectionService,
		private TrackerManager $trackerManager,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->connectionService->list($this->userId ?? ''));
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	#[NoAdminRequired]
	public function create(
		string $tracker,
		string $label = '',
		string $baseUrl = '',
		string $username = '',
		string $token = '',
		string $tempoToken = '',
		array $settings = [],
	): DataResponse {
		if (!in_array($tracker, $this->trackerManager->trackerIds(), true)) {
			return new DataResponse(['error' => 'Unsupported tracker: ' . $tracker], Http::STATUS_BAD_REQUEST);
		}
		$connection = $this->connectionService->create($this->userId ?? '', [
			'tracker' => $tracker,
			'label' => $label,
			'baseUrl' => $baseUrl,
			'username' => $username,
			'token' => $token,
			'tempoToken' => $tempoToken,
			'settings' => $settings,
		]);
		return new DataResponse($connection, Http::STATUS_CREATED);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	#[NoAdminRequired]
	public function update(
		string $id,
		?string $label = null,
		?string $baseUrl = null,
		?string $username = null,
		?string $token = null,
		?string $tempoToken = null,
		?array $settings = null,
	): DataResponse {
		$data = array_filter([
			'label' => $label,
			'baseUrl' => $baseUrl,
			'username' => $username,
			'token' => $token,
			'tempoToken' => $tempoToken,
			'settings' => $settings,
		], static fn ($v): bool => $v !== null);
		$connection = $this->connectionService->update($this->userId ?? '', $id, $data);
		if ($connection === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($connection);
	}

	#[NoAdminRequired]
	public function destroy(string $id): DataResponse {
		$this->connectionService->delete($this->userId ?? '', $id);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Test a connection. If a token placeholder is sent for an existing
	 * connection (id given), the stored secret is used.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[NoAdminRequired]
	public function test(
		string $tracker,
		string $baseUrl = '',
		string $username = '',
		string $token = '',
		string $tempoToken = '',
		string $id = '',
		array $settings = [],
	): DataResponse {
		$client = $this->trackerManager->get($tracker);
		if ($client === null) {
			return new DataResponse(['ok' => false, 'message' => 'Unsupported tracker'], Http::STATUS_BAD_REQUEST);
		}
		if (($token === '' || $token === ConnectionService::PLACEHOLDER) && $id !== '') {
			$stored = $this->connectionService->getWithSecrets($this->userId ?? '', $id);
			if ($stored !== null) {
				$token = $stored->token;
				if ($tempoToken === '' || $tempoToken === ConnectionService::PLACEHOLDER) {
					$tempoToken = $stored->tempoToken;
				}
			}
		}
		$connection = new Connection('', $tracker, '', Connection::normalizeBaseUrl($baseUrl), $username, $token, $tempoToken, $settings);
		return new DataResponse($client->testConnection($connection));
	}
}
