/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import textile from 'textile-js'
import DOMPurify from 'dompurify'
import { generateUrl } from '@nextcloud/router'

/**
 * Render Redmine Textile to sanitized HTML. Images are routed through Unity's
 * same-origin backend proxy (so private attachments load with the connection's
 * token), and links open in a new tab. textile-js output is untrusted, so it is
 * always run through DOMPurify.
 *
 * @param {string} text Textile source
 * @param {string} ref issue ref used by the image proxy
 * @return {string} sanitized HTML
 */
/**
 * Sanitize untrusted HTML with DOMPurify. Images are routed through Unity's
 * same-origin backend proxy (so private attachments load with the connection's
 * token), and links open in a new tab.
 *
 * @param {string} html untrusted HTML
 * @param {string} ref issue ref used by the image proxy
 * @return {string} sanitized HTML
 */
export function sanitizeHtml(html, ref) {
	const base = generateUrl('/apps/unity/issues/{ref}/file', { ref: encodeURIComponent(ref) })

	const hook = (node) => {
		if (node.tagName === 'IMG') {
			const src = node.getAttribute('src') || ''
			if (src && !/^data:/i.test(src) && !src.startsWith(base)) {
				node.setAttribute('src', `${base}?src=${encodeURIComponent(src)}`)
			}
		}
		if (node.tagName === 'A') {
			node.setAttribute('target', '_blank')
			node.setAttribute('rel', 'noopener noreferrer')
		}
	}

	DOMPurify.addHook('afterSanitizeAttributes', hook)
	// Keep task-list checkboxes (e.g. GitLab's server-rendered Markdown) so they can
	// be re-enabled and toggled; DOMPurify strips event handlers regardless.
	const clean = DOMPurify.sanitize(html || '', {
		ADD_TAGS: ['input'],
		ADD_ATTR: ['target', 'rel', 'type', 'checked', 'disabled'],
	})
	DOMPurify.removeHook('afterSanitizeAttributes')
	return clean
}

export function renderTextile(text, ref) {
	let html = ''
	try {
		html = textile(text || '')
	} catch (e) {
		return ''
	}
	return sanitizeHtml(html, ref)
}

/**
 * Render a body that is already HTML (e.g. Jira Server / DC descriptions and
 * comments, which the Jira API returns as rendered HTML). The HTML is untrusted,
 * so it is always run through DOMPurify.
 *
 * @param {string} html rendered HTML from the tracker
 * @param {string} ref issue ref used by the image proxy
 * @return {string} sanitized HTML
 */
export function renderHtml(html, ref) {
	return sanitizeHtml(html || '', ref)
}

/**
 * Strip all markup from a possibly-HTML string, leaving only its visible text.
 * Used for compact, single-line contexts (e.g. the time-entry list) where a
 * tracker may return an HTML body that would otherwise leak raw tags.
 *
 * @param {string} html untrusted HTML or plain text
 * @return {string} plain text with all tags removed
 */
export function stripHtml(html) {
	return DOMPurify.sanitize(html || '', { ALLOWED_TAGS: [], ALLOWED_ATTR: [] })
}
