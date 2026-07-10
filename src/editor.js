/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t } from '@nextcloud/l10n'

/** Toolbar actions per markup syntax. */
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
 * Apply a toolbar action to a textarea's current selection.
 *
 * @return {{value: string, start: number, end: number}} the new value + selection to restore
 */
export function applyAction(textarea, action) {
	const value = textarea.value
	const start = textarea.selectionStart
	const end = textarea.selectionEnd
	const selected = value.slice(start, end)

	if (action.wrap) {
		const [before, after] = action.wrap
		const newValue = value.slice(0, start) + before + selected + after + value.slice(end)
		return { value: newValue, start: start + before.length, end: start + before.length + selected.length }
	}
	if (action.linePrefix) {
		const lineStart = value.lastIndexOf('\n', start - 1) + 1
		const region = value.slice(lineStart, end)
		const lines = region.split('\n')
		const prefixed = lines.map((l) => action.linePrefix + l).join('\n')
		const newValue = value.slice(0, lineStart) + prefixed + value.slice(end)
		return { value: newValue, start: start + action.linePrefix.length, end: end + action.linePrefix.length * lines.length }
	}
	if (action.block) {
		const after = action.blockEnd || ''
		const newValue = value.slice(0, start) + action.block + selected + after + value.slice(end)
		return { value: newValue, start: start + action.block.length, end: start + action.block.length + selected.length }
	}
	if (action.insert) {
		const newValue = value.slice(0, start) + action.insert + value.slice(end)
		const pos = start + action.insert.length
		return { value: newValue, start: pos, end: pos }
	}
	return { value, start, end }
}
