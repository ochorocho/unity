/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Read the original upstream URL back out of a Unity file-proxy URL
 * (`…/apps/unity/issues/{ref}/file?src=<encoded>`), as built by proxifyImages()
 * (src/markdown.js) and the sanitizeHtml() DOMPurify hook (src/render.js).
 *
 * @param {string} proxied a proxy URL, or any other URL
 * @return {string} the decoded upstream URL, or '' if this isn't a proxy URL
 */
export function originalSrc(proxied) {
	const match = /[?&]src=([^&]+)/.exec(proxied || '')
	if (!match) {
		return ''
	}
	try {
		return decodeURIComponent(match[1])
	} catch (e) {
		return ''
	}
}

/**
 * The filename at the end of a URL's path, without query or fragment.
 *
 * @param {string} url any URL
 * @return {string} the last path segment, or '' if there is none
 */
export function filenameFromUrl(url) {
	const path = (url || '').split(/[?#]/)[0]
	// No filter(Boolean) here: a trailing slash must yield '' (so callers fall back to
	// a name of their own), not the directory name that dropping the empty segment
	// would surface.
	const last = path.split('/').pop() || ''
	try {
		return decodeURIComponent(last)
	} catch (e) {
		return last
	}
}
