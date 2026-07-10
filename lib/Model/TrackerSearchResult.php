<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/** Result of a single tracker/connection search. */
class TrackerSearchResult implements JsonSerializable {

	/**
	 * @param Issue[] $issues
	 */
	public function __construct(
		public array $issues,
		public ?string $nextCursor = null,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'issues' => $this->issues,
			'nextCursor' => $this->nextCursor,
		];
	}
}
