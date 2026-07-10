/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t } from '@nextcloud/l10n'

/** Tracker metadata shared across the UI. */
export const TRACKERS = [
	{ id: 'jira', label: 'Jira', color: '#2684ff', timeTracking: true, emojiShortcodes: false },
	{ id: 'gitlab', label: 'GitLab', color: '#fc6d26', timeTracking: true, emojiShortcodes: true },
	{ id: 'redmine', label: 'Redmine', color: '#b32024', timeTracking: true, emojiShortcodes: false },
	{ id: 'github', label: 'GitHub', color: '#24292f', timeTracking: false, emojiShortcodes: true },
]

export function trackerById(id) {
	return TRACKERS.find((tr) => tr.id === id)
		|| { id, label: id, color: 'var(--color-primary-element)', timeTracking: false, emojiShortcodes: false }
}

export const SORT_OPTIONS = () => [
	{ id: 'updated', label: t('unity', 'Last updated') },
	{ id: 'created', label: t('unity', 'Created') },
	{ id: 'title', label: t('unity', 'Title') },
	{ id: 'status', label: t('unity', 'Status') },
]
