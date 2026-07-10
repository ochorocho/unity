<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use RuntimeException;

/** Thrown when a tracker API call fails in a way the caller should surface. */
class TrackerException extends RuntimeException {
}
