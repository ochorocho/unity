/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { generateUrl } from '@nextcloud/router'

/**
 * Rewrite Markdown image targets so they load through Unity's same-origin
 * backend proxy (which fetches them with the connection's token). This makes
 * private/attachment images render and satisfies the default `img-src 'self'`
 * CSP. Non-image links are left untouched.
 *
 * @param {string} text Markdown source
 * @param {string} ref opaque issue ref the proxy uses to pick the connection
 * @return {string} Markdown with image URLs pointing at the proxy
 */
export function proxifyImages(text, ref) {
	if (!text) {
		return ''
	}
	const base = generateUrl('/apps/unity/issues/{ref}/file', { ref: encodeURIComponent(ref) })
	// ![alt](url optional-title) — capture the URL, drop any title, keep alt.
	return text.replace(/!\[([^\]]*)\]\(\s*(<[^>]+>|[^)\s]+)[^)]*\)/g, (match, alt, rawUrl) => {
		const url = rawUrl.replace(/^<|>$/g, '')
		if (/^data:/i.test(url)) {
			return match
		}
		return `![${alt}](${base}?src=${encodeURIComponent(url)})`
	})
}
