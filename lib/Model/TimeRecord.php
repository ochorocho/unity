<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/** A single stored time entry / worklog on an issue, normalized across trackers. */
class TimeRecord implements JsonSerializable {

	public function __construct(
		public string $id,
		public string $author,
		public int $seconds,
		public ?string $date,
		public string $comment = '',
		public bool $editable = false,
		public bool $deletable = false,
	) {
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
