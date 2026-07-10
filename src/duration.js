/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Parse a human duration into seconds.
 * Accepts "1h 30m", "1h30m", "90m", "1.5h", "2h", or a bare number (minutes).
 *
 * @param {string} input
 * @return {number} whole seconds (0 if unparseable)
 */
export function parseDuration(input) {
	const s = (input || '').trim().toLowerCase()
	if (s === '') {
		return 0
	}
	let seconds = 0
	let matched = false
	const re = /(\d+(?:\.\d+)?)\s*(h|m)/g
	let m
	while ((m = re.exec(s)) !== null) {
		matched = true
		const value = parseFloat(m[1])
		seconds += m[2] === 'h' ? value * 3600 : value * 60
	}
	if (!matched) {
		const value = parseFloat(s)
		if (!isNaN(value)) {
			seconds = value * 60
		}
	}
	return Math.round(seconds)
}

/**
 * Format seconds as a compact "2h 30m" string.
 *
 * @param {number} seconds
 * @return {string}
 */
export function humanizeDuration(seconds) {
	const total = Math.max(0, Math.round(seconds || 0))
	if (total === 0) {
		return '0m'
	}
	const h = Math.floor(total / 3600)
	const m = Math.round((total % 3600) / 60)
	return [h ? `${h}h` : '', m ? `${m}m` : ''].filter(Boolean).join(' ') || '0m'
}
