<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

/** A normalized search/list request applied to every tracker. */
class IssueQuery {

	public function __construct(
		public string $term = '',
		public string $sort = 'updated',
		public string $order = 'desc',
		public bool $assignedToMe = false,
		public int $limit = 30,
	) {
	}
}
