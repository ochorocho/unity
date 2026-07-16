/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t } from '@nextcloud/l10n'

/** Tracker metadata shared across the UI. */
export const TRACKERS = [
	{ id: 'jira', label: 'Jira', color: '#2684ff', timeTracking: true, attachments: true, emojiShortcodes: false, create: true, relations: true, mention: true, inlineUpload: false },
	{ id: 'gitlab', label: 'GitLab', color: '#fc6d26', timeTracking: true, attachments: false, emojiShortcodes: true, create: true, relations: true, mention: true, inlineUpload: true },
	{ id: 'redmine', label: 'Redmine', color: '#b32024', timeTracking: true, attachments: true, emojiShortcodes: false, create: true, relations: true, mention: true, inlineUpload: false },
	{ id: 'github', label: 'GitHub', color: '#24292f', timeTracking: false, attachments: false, emojiShortcodes: true, create: true, relations: true, mention: true, inlineUpload: false },
	{ id: 'asana', label: 'Asana', color: '#f06a6a', timeTracking: true, attachments: true, emojiShortcodes: false, create: true, relations: true, mention: true, inlineUpload: false },
]

export function trackerById(id) {
	return TRACKERS.find((tr) => tr.id === id)
		|| { id, label: id, color: 'var(--color-primary-element)', timeTracking: false, attachments: false, emojiShortcodes: false, create: false, relations: false, mention: false, inlineUpload: false }
}

/** Body format used for a new issue's description input, by tracker. */
export function createBodyFormat(trackerId) {
	return trackerId === 'redmine' ? 'textile' : 'markdown'
}

export const SORT_OPTIONS = () => [
	{ id: 'updated', label: t('unity', 'Last updated') },
	{ id: 'created', label: t('unity', 'Created') },
	{ id: 'title', label: t('unity', 'Title') },
	{ id: 'status', label: t('unity', 'Status') },
]
