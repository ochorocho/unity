<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/** Normalized comment shared by all trackers. */
class Comment implements JsonSerializable {

	public function __construct(
		public string $id,
		public string $author,
		public ?string $authorAvatar,
		public string $body,
		public ?string $createdAt,
		public ?string $url = null,
		/**
		 * Provider-rendered HTML for the body (e.g. GitLab renders its Markdown —
		 * including embedded raw HTML — server-side). When present the UI shows
		 * this sanitized HTML; `body` still holds the raw source.
		 */
		public ?string $renderedBody = null,
		/**
		 * Whether the connection's current user authored this comment and may
		 * therefore edit it. Set per-tracker in getComments(); the UI gates the
		 * edit affordance on it (same convention as TimeRecord::$editable).
		 */
		public bool $editable = false,
		/**
		 * Whether the current user may delete this comment: they authored it AND
		 * the tracker's API supports deleting comments (e.g. Redmine cannot delete
		 * journal notes, so this stays false there). Set per-tracker in getComments().
		 */
		public bool $deletable = false,
		/**
		 * Mentions in `body`, as `{id, label}` where id is the canonical
		 * `mention:<handle>` token id and label the display name. Lets the mention
		 * editor render existing `@mention:<handle>` tokens as pills when editing.
		 *
		 * @var list<array{id: string, label: string}>
		 */
		public array $mentions = [],
	) {
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
