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
	// A trailing GitLab-style attribute block (e.g. `{width=122 height=110}`) is
	// consumed and dropped so it doesn't leak into the output as literal text; the
	// dimensions are reapplied to the rendered <img> from imageAttributes().
	return text.replace(/!\[([^\]]*)\]\(\s*(<[^>]+>|[^)\s]+)[^)]*\)(?:\{[^}]*\})?/g, (match, alt, rawUrl) => {
		const url = rawUrl.replace(/^<|>$/g, '')
		if (/^data:/i.test(url)) {
			return `![${alt}](${url})`
		}
		return `![${alt}](${base}?src=${encodeURIComponent(url)})`
	})
}

/**
 * Parse the width/height from GitLab-style image attribute blocks
 * (`![alt](url){width=122 height=110}`). Keyed by the original image URL so a
 * rendered <img> (whose proxy src carries `?src=<encoded url>`) can be matched.
 *
 * @param {string} text Markdown source
 * @return {Map<string, {width: (string|null), height: (string|null)}>} url → dimensions
 */
export function imageAttributes(text) {
	const map = new Map()
	if (!text) {
		return map
	}
	const re = /!\[[^\]]*\]\(\s*(<[^>]+>|[^)\s]+)[^)]*\)\{([^}]*)\}/g
	let m
	while ((m = re.exec(text)) !== null) {
		const url = m[1].replace(/^<|>$/g, '')
		const w = /(?:^|\s)width\s*=\s*"?(\d+)/i.exec(m[2])
		const h = /(?:^|\s)height\s*=\s*"?(\d+)/i.exec(m[2])
		if (w || h) {
			map.set(url, { width: w ? w[1] : null, height: h ? h[1] : null })
		}
	}
	return map
}
