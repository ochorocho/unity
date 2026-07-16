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

	const proxify = (url) => `${base}?src=${encodeURIComponent(url)}`
	// A resource the browser would load cross-origin (so blocked by our img-src/media-src
	// 'self' CSP): needs proxying. Skips inline data: URIs and already-proxied URLs.
	const external = (url) => url && !/^data:/i.test(url) && !url.startsWith(base)

	const hook = (node) => {
		const tag = node.tagName
		// Every resource the browser fetches inline must go through the same-origin proxy
		// (so private files load with the connection's token and satisfy the CSP): images,
		// and the media GitLab's Audio/VideoLinkFilter emit as <audio>/<video>/<source>.
		if (tag === 'IMG' || tag === 'AUDIO' || tag === 'VIDEO' || tag === 'SOURCE') {
			// GitLab's ImageLazyLoadFilter parks the real URL in data-src and leaves a 1x1
			// GIF in src; its lazy-loader is GitLab's own JS, which never runs here, so the
			// placeholder would be all that renders. Promote data-src and drop it. (Media
			// elements carry a plain src; the fallback covers them.)
			const src = node.getAttribute('data-src') || node.getAttribute('src') || ''
			if (external(src)) {
				node.setAttribute('src', proxify(src))
				node.removeAttribute('data-src')
			}
			// <video poster> is a still image loaded under the CSP too.
			if (tag === 'VIDEO') {
				const poster = node.getAttribute('poster') || ''
				if (external(poster)) {
					node.setAttribute('poster', proxify(poster))
				}
			}
		}
		if (tag === 'A') {
			const href = node.getAttribute('href') || ''
			// GitLab wraps each inline image in a link to its upstream upload URL, and
			// links uploaded files the same way; both need the token-authenticated proxy
			// that <img src> already goes through, or they open a login page. Matched by
			// upload shape so ordinary links (and other trackers) are never touched.
			if (/\/uploads\/[0-9a-f]+\/[^/]+$/i.test(href) && !href.startsWith(base)) {
				node.setAttribute('href', proxify(href))
			}
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
