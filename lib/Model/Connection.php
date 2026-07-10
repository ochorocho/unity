<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/**
 * A user's named connection to one tracker instance. Secret fields (token,
 * tempoToken) are only populated when loaded for internal use and are never
 * exposed through jsonSerialize().
 */
class Connection implements JsonSerializable {

	public function __construct(
		public string $id,
		public string $tracker,
		public string $label,
		public string $baseUrl,
		public string $username = '',
		public string $token = '',
		public string $tempoToken = '',
		public array $settings = [],
	) {
	}

	public function withSecrets(string $token, string $tempoToken = ''): self {
		return new self(
			$this->id, $this->tracker, $this->label, $this->baseUrl,
			$this->username, $token, $tempoToken, $this->settings,
		);
	}

	/**
	 * Normalize a user-entered base URL: trim, default to https:// when no
	 * scheme is given, and strip a trailing slash. Empty input stays empty
	 * (GitHub falls back to api.github.com in its client).
	 */
	public static function normalizeBaseUrl(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		if (preg_match('#^https?://#i', $url) !== 1) {
			$url = 'https://' . $url;
		}
		return rtrim($url, '/');
	}

	public static function fromMeta(array $m): self {
		return new self(
			(string)($m['id'] ?? ''),
			(string)($m['tracker'] ?? ''),
			(string)($m['label'] ?? ''),
			(string)($m['baseUrl'] ?? ''),
			(string)($m['username'] ?? ''),
			'', '',
			is_array($m['settings'] ?? null) ? $m['settings'] : [],
		);
	}

	/** Non-secret metadata persisted in the IUserConfig index. */
	public function toMeta(): array {
		return [
			'id' => $this->id,
			'tracker' => $this->tracker,
			'label' => $this->label,
			'baseUrl' => $this->baseUrl,
			'username' => $this->username,
			'settings' => $this->settings,
		];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'tracker' => $this->tracker,
			'label' => $this->label,
			'baseUrl' => $this->baseUrl,
			'username' => $this->username,
			'hasToken' => $this->token !== '',
			'hasTempoToken' => $this->tempoToken !== '',
			'settings' => $this->settings,
		];
	}
}
