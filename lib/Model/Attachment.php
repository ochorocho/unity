<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/**
 * Normalized issue attachment shared by all trackers. `src` (and the optional
 * `thumbnailSrc`) are upstream URLs the frontend loads through the SSRF-guarded
 * `issue#file` proxy rather than contacting the tracker directly.
 */
class Attachment implements JsonSerializable {

	public function __construct(
		public string $id,
		public string $filename,
		public string $mimeType,
		public int $size,
		public string $src,
		public ?string $thumbnailSrc = null,
		public string $author = '',
		public ?string $createdAt = null,
	) {
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
