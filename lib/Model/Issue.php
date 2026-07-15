<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/** Normalized issue shared by all trackers. */
class Issue implements JsonSerializable {

	/**
	 * @param string[] $labels
	 */
	public function __construct(
		public string $ref,
		public string $tracker,
		public string $connectionId,
		public string $connectionLabel,
		public string $displayId,
		public string $title,
		public string $description,
		public string $status,
		public string $author,
		public string $assignee,
		public array $labels,
		public string $project,
		public ?string $createdAt,
		public ?string $updatedAt,
		public string $url,
		public ?int $timeSpentSeconds = null,
		public string $bodyFormat = 'plaintext',
		/**
		 * Provider-rendered HTML for the description (e.g. GitLab renders its
		 * Markdown — including embedded raw HTML — server-side). When present the
		 * UI shows this sanitized HTML; `description` still holds the raw source
		 * used for editing. Only populated for the single-issue view.
		 */
		public ?string $renderedDescription = null,
		/**
		 * Mentions in `description`, as `{id, label}` (canonical `mention:<handle>`
		 * token id + display name), so the mention editor renders existing mentions
		 * as pills when editing the description. Only populated for the detail view.
		 *
		 * @var list<array{id: string, label: string}>
		 */
		public array $mentions = [],
	) {
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}

	/**
	 * Rebuild an Issue from its serialized array form (used to rehydrate
	 * cached results).
	 *
	 * @param array<string, mixed> $a
	 */
	public static function fromArray(array $a): self {
		return new self(
			(string)($a['ref'] ?? ''),
			(string)($a['tracker'] ?? ''),
			(string)($a['connectionId'] ?? ''),
			(string)($a['connectionLabel'] ?? ''),
			(string)($a['displayId'] ?? ''),
			(string)($a['title'] ?? ''),
			(string)($a['description'] ?? ''),
			(string)($a['status'] ?? ''),
			(string)($a['author'] ?? ''),
			(string)($a['assignee'] ?? ''),
			is_array($a['labels'] ?? null) ? array_values($a['labels']) : [],
			(string)($a['project'] ?? ''),
			$a['createdAt'] ?? null,
			$a['updatedAt'] ?? null,
			(string)($a['url'] ?? ''),
			isset($a['timeSpentSeconds']) ? (int)$a['timeSpentSeconds'] : null,
			(string)($a['bodyFormat'] ?? 'plaintext'),
			isset($a['renderedDescription']) ? (string)$a['renderedDescription'] : null,
			is_array($a['mentions'] ?? null) ? array_values($a['mentions']) : [],
		);
	}
}
