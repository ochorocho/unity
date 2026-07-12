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
	) {
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
