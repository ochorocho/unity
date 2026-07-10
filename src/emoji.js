/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { emojiSearch } from '@nextcloud/vue/functions/emoji'
import emojiData from 'emoji-mart-vue-fast/data/all.json'

/** Convert an emoji-mart "unified" codepoint string (e.g. "1F44D-1F3FB") to the native glyph. */
function unifiedToNative(unified) {
	return unified.split('-').map((cp) => String.fromCodePoint(parseInt(cp, 16))).join('')
}

/**
 * Build the shortcode↔native maps once from the (already bundled) emoji-mart
 * dataset. `forward` maps shortcode → native (id, short_names, aliases and the
 * gemoji `+1`/`-1` keyword-aliases). `reverse` maps native → canonical id (set
 * once per emoji so it yields the canonical shortcode).
 */
const { forward, reverse } = (() => {
	const fwd = Object.create(null)
	const rev = Object.create(null)
	const emojis = (emojiData && emojiData.emojis) || {}
	for (const id of Object.keys(emojis)) {
		const entry = emojis[id]
		if (!entry || !entry.b) {
			continue
		}
		const glyph = unifiedToNative(entry.b)
		fwd[id] = glyph
		for (const shortName of (entry.n || [])) {
			fwd[shortName] = glyph
		}
		if (!(glyph in rev)) {
			rev[glyph] = id
		}
	}
	for (const alias of Object.keys(emojiData.aliases || {})) {
		const canonical = emojiData.aliases[alias]
		if (fwd[canonical]) {
			fwd[alias] = fwd[canonical]
		}
	}
	if (fwd.thumbsup) {
		fwd['+1'] = fwd.thumbsup
	}
	if (fwd.thumbsdown) {
		fwd['-1'] = fwd.thumbsdown
	}
	return { forward: fwd, reverse: rev }
})()

/**
 * Replace `:shortcode:` occurrences with their native emoji, leaving unknown
 * codes untouched. Non-destructive: intended for render only.
 *
 * @param {string} text
 * @return {string}
 */
export function emojify(text) {
	if (!text || text.indexOf(':') === -1) {
		return text
	}
	return text.replace(/:([a-z0-9_+-]+):/gi, (match, name) => forward[name.toLowerCase()] || match)
}

/**
 * Reverse lookup: a native emoji glyph → its canonical `:shortcode:`, or null
 * when unknown. Used to store the provider-native shortcode (GitHub/GitLab).
 *
 * @param {string} glyph
 * @return {string|null}
 */
export function emojiToShortcode(glyph) {
	const id = reverse[glyph]
	return id ? `:${id}:` : null
}

/**
 * Search emojis for the editor typeahead.
 *
 * @param {string} query
 * @param {number} max
 * @return {Array<{id: string, native: string, name: string}>}
 */
export function searchEmojis(query, max = 8) {
	try {
		return (emojiSearch(query, max) || []).map((e) => ({ id: e.id, native: e.native, name: e.name || e.id }))
	} catch (e) {
		return []
	}
}
