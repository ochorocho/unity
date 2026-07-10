/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Flip the `index`-th Markdown task marker (`[ ]` ↔ `[x]`) in `text`, leaving
 * everything else untouched. Task items are counted in document order, matching
 * the render order of the checkboxes.
 *
 * @param {string} text Markdown source
 * @param {number} index zero-based index of the task item to toggle
 * @param {boolean} checked target state
 * @return {string} updated Markdown
 */
export function toggleTask(text, index, checked) {
	let n = -1
	return (text || '').replace(/^(\s*[-*+]\s+)\[([ xX])\]/gm, (match, prefix) => {
		n++
		if (n === index) {
			return prefix + (checked ? '[x]' : '[ ]')
		}
		return match
	})
}

/**
 * Flip the `index`-th Markdown task marker (`[ ]` ↔ `[x]`) in `text` regardless
 * of its current state. Used when the toggle source (NcRichText's `interactTodo`)
 * reports only which checkbox was hit, not the resulting state.
 *
 * @param {string} text Markdown source
 * @param {number} index zero-based index of the task item to flip
 * @return {string} updated Markdown
 */
export function toggleTaskAt(text, index) {
	let n = -1
	return (text || '').replace(/^(\s*[-*+]\s+)\[([ xX])\]/gm, (match, prefix, mark) => {
		n++
		if (n === index) {
			return prefix + (mark === ' ' ? '[x]' : '[ ]')
		}
		return match
	})
}
