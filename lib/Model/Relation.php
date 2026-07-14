<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Model;

use JsonSerializable;

/**
 * A normalized link between two issues on the same connection. Relations are
 * always within one tracker instance, so the target is addressed by an encoded
 * Ref (see Ref.php) which lets the UI click through to the existing detail view.
 *
 * Directionality is folded into the type key + human label rather than a
 * separate enum: the provider owns the relation-type vocabulary (like the
 * status/label option lists in getEditMeta), the UI just displays labels.
 */
class Relation implements JsonSerializable {

	public function __construct(
		/**
		 * Provider-opaque token identifying this relation for deleteRelation().
		 * Some providers expose a real link id (Jira issuelink id, Redmine
		 * relation id, GitLab issue_link_id); others encode what delete needs
		 * (GitHub sub-issue numeric id, Asana "family:gid").
		 */
		public string $id,
		/** Normalized relation-type key, e.g. relates|blocks|is_blocked_by|sub-issue|depends_on. */
		public string $type,
		/** Human label from the provider's own vocabulary, e.g. "Is blocked by". */
		public string $typeLabel,
		/** Ref::encode(tracker, sameConnectionId, targetParts) — enables click-through. */
		public string $targetRef,
		public string $targetDisplayId,
		public string $targetTitle,
		public string $targetStatus,
		public string $targetUrl,
		/**
		 * Whether the current user may remove this relation from this side. False
		 * where the provider can't unlink from here (e.g. a read-only parent).
		 * Mirrors Comment::$deletable / TimeRecord::$deletable.
		 */
		public bool $deletable = true,
	) {
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
