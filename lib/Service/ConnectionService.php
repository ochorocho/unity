<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Connection;
use OCP\Config\IUserConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICredentialsManager;
use OCP\Security\ISecureRandom;

/**
 * CRUD for a user's tracker connections. Non-secret metadata lives in a JSON
 * index in IUserConfig; tokens are stored (encrypted at rest) via
 * ICredentialsManager, keyed per connection, and are never returned to the
 * client. Sending the PLACEHOLDER value on update keeps the stored token.
 */
class ConnectionService {

	private const INDEX_KEY = 'connections';
	public const PLACEHOLDER = '__keep__';

	private ICache $cache;

	public function __construct(
		private IUserConfig $userConfig,
		private ICredentialsManager $credentials,
		private ISecureRandom $random,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/**
	 * @return Connection[] metadata only (no secrets)
	 */
	public function list(string $userId): array {
		$raw = $this->userConfig->getValueString($userId, Application::APP_ID, self::INDEX_KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $meta) {
			if (is_array($meta)) {
				$out[] = Connection::fromMeta($meta);
			}
		}
		return $out;
	}

	public function get(string $userId, string $id): ?Connection {
		foreach ($this->list($userId) as $connection) {
			if ($connection->id === $id) {
				return $connection;
			}
		}
		return null;
	}

	/** Connection with decrypted secrets attached, for internal use. */
	public function getWithSecrets(string $userId, string $id): ?Connection {
		$connection = $this->get($userId, $id);
		if ($connection === null) {
			return null;
		}
		[$token, $tempoToken] = $this->readSecret($userId, $id);
		return $connection->withSecrets($token, $tempoToken);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(string $userId, array $data): Connection {
		$id = $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
		$connection = new Connection(
			$id,
			(string)($data['tracker'] ?? ''),
			(string)($data['label'] ?? ''),
			Connection::normalizeBaseUrl((string)($data['baseUrl'] ?? '')),
			(string)($data['username'] ?? ''),
			'', '',
			is_array($data['settings'] ?? null) ? $data['settings'] : [],
		);
		$this->persistMeta($userId, $connection);
		$token = (string)($data['token'] ?? '');
		$tempoToken = (string)($data['tempoToken'] ?? '');
		$this->writeSecret($userId, $id, $token, $tempoToken);
		$this->bumpGeneration($userId);
		return $connection->withSecrets($token, $tempoToken);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(string $userId, string $id, array $data): ?Connection {
		$existing = $this->get($userId, $id);
		if ($existing === null) {
			return null;
		}
		$connection = new Connection(
			$id,
			$existing->tracker,
			array_key_exists('label', $data) ? (string)$data['label'] : $existing->label,
			array_key_exists('baseUrl', $data) ? Connection::normalizeBaseUrl((string)$data['baseUrl']) : $existing->baseUrl,
			array_key_exists('username', $data) ? (string)$data['username'] : $existing->username,
			'', '',
			is_array($data['settings'] ?? null) ? $data['settings'] : $existing->settings,
		);
		$this->persistMeta($userId, $connection);

		[$curToken, $curTempo] = $this->readSecret($userId, $id);
		$newToken = $this->resolveSecret($data['token'] ?? self::PLACEHOLDER, $curToken);
		$newTempo = $this->resolveSecret($data['tempoToken'] ?? self::PLACEHOLDER, $curTempo);
		$this->writeSecret($userId, $id, $newToken, $newTempo);
		$this->bumpGeneration($userId);
		return $connection->withSecrets($newToken, $newTempo);
	}

	public function delete(string $userId, string $id): void {
		$remaining = array_values(array_filter(
			$this->list($userId),
			static fn (Connection $c): bool => $c->id !== $id,
		));
		$this->writeIndex($userId, $remaining);
		$this->credentials->delete($userId, $this->secretKey($id));
		$this->bumpGeneration($userId);
	}

	/** Invalidate this user's cached issue lists. */
	private function bumpGeneration(string $userId): void {
		$key = IssueService::generationKey($userId);
		$current = (int)($this->cache->get($key) ?? 0);
		$this->cache->set($key, $current + 1);
	}

	private function resolveSecret(mixed $incoming, string $current): string {
		$value = (string)$incoming;
		if ($value === '' || $value === self::PLACEHOLDER) {
			return $current;
		}
		return $value;
	}

	private function persistMeta(string $userId, Connection $connection): void {
		$list = $this->list($userId);
		$replaced = false;
		foreach ($list as $i => $existing) {
			if ($existing->id === $connection->id) {
				$list[$i] = $connection;
				$replaced = true;
				break;
			}
		}
		if (!$replaced) {
			$list[] = $connection;
		}
		$this->writeIndex($userId, $list);
	}

	/**
	 * @param Connection[] $list
	 */
	private function writeIndex(string $userId, array $list): void {
		$meta = array_map(static fn (Connection $c): array => $c->toMeta(), $list);
		$this->userConfig->setValueString($userId, Application::APP_ID, self::INDEX_KEY, (string)json_encode(array_values($meta)));
	}

	/**
	 * @return array{0: string, 1: string} [token, tempoToken]
	 */
	private function readSecret(string $userId, string $id): array {
		$secret = $this->credentials->retrieve($userId, $this->secretKey($id));
		if (!is_array($secret)) {
			return ['', ''];
		}
		return [(string)($secret['token'] ?? ''), (string)($secret['tempoToken'] ?? '')];
	}

	private function writeSecret(string $userId, string $id, string $token, string $tempoToken): void {
		$this->credentials->store($userId, $this->secretKey($id), [
			'token' => $token,
			'tempoToken' => $tempoToken,
		]);
	}

	private function secretKey(string $id): string {
		return Application::APP_ID . '.conn.' . $id;
	}
}
