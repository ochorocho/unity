<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

/**
 * Opaque, URL-safe handle identifying a single issue on a specific connection.
 * Encodes the tracker id, connection id and tracker-specific parts (Jira key,
 * GitLab project+iid, Redmine id, GitHub owner/repo/number) so follow-up calls
 * (detail, comments, time) can be routed without extra lookups.
 */
final class Ref {

	public static function encode(string $tracker, string $connectionId, array $parts): string {
		$json = json_encode(['t' => $tracker, 'c' => $connectionId, 'p' => $parts]);
		return rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
	}

	/**
	 * @return array{t: string, c: string, p: array}
	 */
	public static function decode(string $ref): array {
		$b64 = strtr($ref, '-_', '+/');
		$json = base64_decode($b64, true);
		$data = $json === false ? null : json_decode($json, true);
		if (!is_array($data)) {
			return ['t' => '', 'c' => '', 'p' => []];
		}
		return [
			't' => (string)($data['t'] ?? ''),
			'c' => (string)($data['c'] ?? ''),
			'p' => is_array($data['p'] ?? null) ? $data['p'] : [],
		];
	}
}
