/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Extension → registered Viewer mime. Inline body images carry no mime, so we derive one
// from the filename. On our hasPreview:false + source fileInfo the images handler renders a
// literal <img :src="source">, so the browser (via the proxy's Content-Type), not this mime,
// decides how to decode the bytes. The mime only has to (a) resolve to the images handler and
// (b) not be the unregistered alias 'image' — hence a map of real image mimes + a png fallback.
const IMAGE_MIMES = {
	png: 'image/png', apng: 'image/apng', jpg: 'image/jpeg', jpeg: 'image/jpeg',
	gif: 'image/gif', webp: 'image/webp', bmp: 'image/bmp', svg: 'image/svg+xml',
	ico: 'image/x-icon', heic: 'image/heic', heif: 'image/heif',
	tif: 'image/tiff', tiff: 'image/tiff',
}

/**
 * Best-effort MIME for an inline image, derived from its filename extension.
 *
 * @param {string} name filename
 * @return {string} a registered image MIME, or 'image/png' as a safe fallback
 */
export function mimeFromName(name) {
	const ext = (name || '').split('.').pop().toLowerCase()
	return IMAGE_MIMES[ext] || 'image/png'
}

/**
 * Open already-resolved media URLs in the Nextcloud Viewer as a gallery. The Viewer owns
 * navigation, download and close once given the list, so no local state is kept here.
 *
 * @param {Array<{src: string, name: string, mime: string}>} items directly-loadable URLs
 * @param {number} index which item to show first
 */
export function viewerOpen(items, index = 0) {
	const viewer = window.OCA && window.OCA.Viewer
	if (!viewer || typeof viewer.open !== 'function') {
		// Viewer JS not on the page (app disabled): open the file directly rather than
		// dead-clicking. Not a FilePreview fallback — a graceful last resort.
		if (items[index]) {
			window.open(items[index].src, '_blank', 'noopener')
		}
		return
	}
	// hasPreview:false keeps the Viewer on the `source` branch (no PROPFIND, no preview
	// endpoint); a synthetic unique `filename` is the Viewer's identity key (real basenames
	// collide); omitting `permissions` disables Delete/Edit; the resulting null davPath
	// disables the sidebar (also passed explicitly).
	const list = items.map((item, i) => ({
		filename: `/unity-preview/${i}/${item.name}`,
		basename: item.name,
		mime: item.mime,
		source: item.src,
		hasPreview: false,
		fileid: i,
	}))
	viewer.open({ fileInfo: list[index], list, enableSidebar: false, canLoop: false })
}
