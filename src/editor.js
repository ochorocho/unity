/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * Formatting toolbar actions per markup syntax. Each action carries the syntax
 * to apply to the current selection: `wrap` surrounds it, `linePrefix` prefixes
 * each selected line, `block` fences it, and `insert` drops a snippet.
 */
export function toolbarFor(syntax) {
	if (syntax === 'textile') {
		return [
			{ key: 'bold', label: 'B', title: t('unity', 'Bold'), wrap: ['*', '*'] },
			{ key: 'italic', label: 'I', title: t('unity', 'Italic'), wrap: ['_', '_'] },
			{ key: 'strike', label: 'S', title: t('unity', 'Strikethrough'), wrap: ['-', '-'] },
			{ key: 'code', label: '</>', title: t('unity', 'Code'), wrap: ['@', '@'] },
			{ key: 'h', label: 'H', title: t('unity', 'Heading'), linePrefix: 'h2. ' },
			{ key: 'quote', label: '“', title: t('unity', 'Quote'), linePrefix: 'bq. ' },
			{ key: 'ul', label: '•', title: t('unity', 'Bulleted list'), linePrefix: '* ' },
			{ key: 'ol', label: '1.', title: t('unity', 'Numbered list'), linePrefix: '# ' },
			{ key: 'link', label: '🔗', title: t('unity', 'Link'), wrap: ['"', '":https://'] },
		]
	}
	return [
		{ key: 'bold', label: 'B', title: t('unity', 'Bold'), wrap: ['**', '**'] },
		{ key: 'italic', label: 'I', title: t('unity', 'Italic'), wrap: ['*', '*'] },
		{ key: 'strike', label: 'S', title: t('unity', 'Strikethrough'), wrap: ['~~', '~~'] },
		{ key: 'code', label: '</>', title: t('unity', 'Inline code'), wrap: ['`', '`'] },
		{ key: 'codeblock', label: '{ }', title: t('unity', 'Code block'), block: '```\n', blockEnd: '\n```' },
		{ key: 'h', label: 'H', title: t('unity', 'Heading'), linePrefix: '## ' },
		{ key: 'quote', label: '“', title: t('unity', 'Quote'), linePrefix: '> ' },
		{ key: 'ul', label: '•', title: t('unity', 'Bulleted list'), linePrefix: '- ' },
		{ key: 'ol', label: '1.', title: t('unity', 'Numbered list'), linePrefix: '1. ' },
		{ key: 'task', label: '☑', title: t('unity', 'Task list'), linePrefix: '- [ ] ' },
		{ key: 'link', label: '🔗', title: t('unity', 'Link'), wrap: ['[', '](https://)'] },
		{ key: 'table', label: '▦', title: t('unity', 'Table'), insert: '\n| Column | Column |\n| --- | --- |\n| a | b |\n' },
	]
}

/**
 * Build the text a toolbar action inserts, given the currently selected text.
 * `wrap`/`block` surround the selection; `linePrefix` prefixes each selected
 * line; `insert` ignores the selection. Returns null for an unknown action.
 *
 * @param {object} action a toolbar action from toolbarFor()
 * @param {string} selected the currently selected text ('' when none)
 * @return {string|null} the text to insert in place of the selection
 */
export function actionText(action, selected) {
	if (action.wrap) {
		return action.wrap[0] + selected + action.wrap[1]
	}
	if (action.block) {
		return action.block + selected + (action.blockEnd || '')
	}
	if (action.linePrefix) {
		return selected.split('\n').map((line) => action.linePrefix + line).join('\n')
	}
	if (action.insert) {
		return action.insert
	}
	return null
}
