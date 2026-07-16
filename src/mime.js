/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Uploaded-file extensions → mime. Inline links carry no mime (unlike attachments,
// which get it from the tracker), so it's guessed from the extension; unknown falls
// back to a generic file icon. Audio/video are included because GitLab's inline
// players are turned into file chips that open the Viewer — the mimes below are the
// ones the Viewer's audios/videos handlers register.
const EXT_MIME = {
	// Audio (Viewer audios handler).
	mp3: 'audio/mpeg',
	wav: 'audio/wav',
	flac: 'audio/flac',
	aac: 'audio/aac',
	m4a: 'audio/mp4',
	oga: 'audio/ogg',
	ogg: 'audio/ogg',
	weba: 'audio/webm',
	// Video (Viewer videos handler).
	mp4: 'video/mp4',
	m4v: 'video/x-m4v',
	webm: 'video/webm',
	ogv: 'video/ogg',
	mov: 'video/quicktime',
	mkv: 'video/x-matroska',
	flv: 'video/x-flv',
	mpeg: 'video/mpeg',
	mpg: 'video/mpeg',
	pdf: 'application/pdf',
	doc: 'application/msword',
	docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	xls: 'application/vnd.ms-excel',
	xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	ppt: 'application/vnd.ms-powerpoint',
	pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	odt: 'application/vnd.oasis.opendocument.text',
	ods: 'application/vnd.oasis.opendocument.spreadsheet',
	odp: 'application/vnd.oasis.opendocument.presentation',
	rtf: 'application/rtf',
	txt: 'text/plain',
	md: 'text/markdown',
	csv: 'text/csv',
	zip: 'application/zip',
	tar: 'application/x-tar',
	gz: 'application/gzip',
	bz2: 'application/x-bzip2',
	'7z': 'application/x-7z-compressed',
	rar: 'application/vnd.rar',
	json: 'application/json',
	xml: 'application/xml',
	yml: 'application/x-yaml',
	yaml: 'application/x-yaml',
	html: 'text/html',
	css: 'text/css',
	js: 'application/javascript',
	ts: 'application/typescript',
	sh: 'application/x-shellscript',
	sql: 'application/sql',
	py: 'text/x-python',
	java: 'text/x-java',
	c: 'text/x-c',
	cpp: 'text/x-c',
	h: 'text/x-c',
	go: 'text/x-go',
	rs: 'text/x-rust',
	rb: 'text/x-ruby',
	php: 'application/x-php',
}

/**
 * Best-effort mime for a filename, from its extension. Unknown → octet-stream (which
 * OC.MimeType renders as the generic file icon).
 *
 * @param {string} name filename
 * @return {string} a mime type
 */
export function mimeFromExtension(name) {
	const ext = (name || '').split('.').pop().toLowerCase()
	return EXT_MIME[ext] || 'application/octet-stream'
}

/**
 * Nextcloud's Files-app icon URL for a mime type, or '' when the core OC.MimeType API
 * isn't on the page (callers then fall back to a generic icon). Same-origin, so it
 * satisfies the img-src 'self' CSP.
 *
 * @param {string} mime a mime type
 * @return {string} an icon URL, or ''
 */
export function fileIconUrl(mime) {
	const oc = window.OC
	return (mime && oc?.MimeType?.getIconUrl) ? (oc.MimeType.getIconUrl(mime) || '') : ''
}
