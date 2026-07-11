<template>
	<div class="unity-editor">
		<div class="unity-editor-tabs">
			<button type="button"
				class="unity-editor-tab"
				:class="{ active: tab === 'write' }"
				@click="tab = 'write'">
				{{ t('unity', 'Write') }}
			</button>
			<button type="button"
				class="unity-editor-tab"
				:class="{ active: tab === 'preview' }"
				@click="tab = 'preview'">
				{{ t('unity', 'Preview') }}
			</button>
		</div>

		<div v-show="tab === 'write'">
			<div class="unity-editor-toolbar">
				<button v-for="a in toolbar"
					:key="a.key"
					type="button"
					class="unity-editor-btn"
					:title="a.title"
					@click="apply(a)">
					{{ a.label }}
				</button>
				<NcEmojiPicker :close-on-select="false" @select="insertEmoji">
					<button type="button" class="unity-editor-btn" :title="t('unity', 'Insert emoji')">🙂</button>
				</NcEmojiPicker>
			</div>
			<div class="unity-editor-input">
				<textarea ref="ta"
					class="unity-editor-textarea"
					:value="modelValue"
					:placeholder="placeholder"
					:rows="rows"
					@input="onInput"
					@keydown="onKeydown"
					@click="detect"
					@blur="closeMenu" />
				<ul v-if="menu.open" class="unity-emoji-menu">
					<li v-for="(e, i) in menu.items"
						:key="e.id"
						class="unity-emoji-item"
						:class="{ active: i === menu.index }"
						@mousedown.prevent="pick(i)"
						@mousemove="menu.index = i">
						<span class="unity-emoji-native">{{ e.native }}</span>
						<span class="unity-emoji-name">:{{ e.id }}:</span>
					</li>
				</ul>
			</div>
		</div>

		<div v-show="tab === 'preview'" class="unity-editor-preview">
			<RenderedText v-if="modelValue" :text="modelValue" :format="previewFormat" :issue-ref="issueRef" />
			<p v-else class="unity-editor-empty">{{ t('unity', 'Nothing to preview') }}</p>
		</div>
	</div>
</template>

<script>
import NcEmojiPicker from '@nextcloud/vue/components/NcEmojiPicker'
import RenderedText from './RenderedText.vue'
import { toolbarFor, applyAction } from '../editor.js'
import { searchEmojis, emojiToShortcode } from '../emoji.js'
import { trackerById } from '../trackers.js'

export default {
	name: 'MarkupEditor',
	components: { NcEmojiPicker, RenderedText },
	props: {
		modelValue: { type: String, default: '' },
		// The tracker body format ('markdown' | 'textile' | 'html' | 'plaintext').
		// Drives both the toolbar syntax and how the Preview tab renders.
		format: { type: String, default: 'markdown' },
		issueRef: { type: String, default: '' },
		tracker: { type: String, default: '' },
		placeholder: { type: String, default: '' },
		rows: { type: Number, default: 6 },
	},
	emits: ['update:modelValue'],
	data() {
		return {
			tab: 'write',
			menu: { open: false, items: [], index: 0, start: 0 },
		}
	},
	computed: {
		// Toolbar snippets only come in markdown and textile flavours; anything
		// that isn't textile (markdown, html, plaintext) uses the markdown toolbar.
		syntax() {
			return this.format === 'textile' ? 'textile' : 'markdown'
		},
		toolbar() {
			return toolbarFor(this.syntax)
		},
		// Preview must match how the saved body is displayed, so an HTML body
		// (e.g. Jira Server/DC) renders as HTML instead of showing raw tags.
		previewFormat() {
			if (this.format === 'html') {
				return 'html'
			}
			return this.format === 'textile' ? 'textile' : 'markdown'
		},
		useShortcodes() {
			return trackerById(this.tracker).emojiShortcodes
		},
	},
	methods: {
		apply(action) {
			const ta = this.$refs.ta
			const res = applyAction(ta, action)
			this.$emit('update:modelValue', res.value)
			this.$nextTick(() => {
				ta.focus()
				ta.setSelectionRange(res.start, res.end)
			})
		},
		insertEmoji(emoji) {
			const native = typeof emoji === 'string' ? emoji : (emoji && emoji.native) || ''
			const out = this.useShortcodes ? (emojiToShortcode(native) || native) : native
			this.apply({ insert: out })
		},
		onInput(e) {
			this.$emit('update:modelValue', e.target.value)
			this.$nextTick(this.detect)
		},
		detect() {
			const ta = this.$refs.ta
			if (!ta) {
				return
			}
			const caret = ta.selectionStart
			const before = ta.value.slice(0, caret)
			const m = before.match(/:([a-z0-9_+-]+)$/i)
			if (!m) {
				this.closeMenu()
				return
			}
			const items = searchEmojis(m[1].toLowerCase(), 8)
			if (items.length === 0) {
				this.closeMenu()
				return
			}
			this.menu = { open: true, items, index: 0, start: caret - m[0].length }
		},
		onKeydown(e) {
			if (!this.menu.open) {
				return
			}
			const len = this.menu.items.length
			if (e.key === 'ArrowDown') {
				this.menu.index = (this.menu.index + 1) % len
				e.preventDefault()
			} else if (e.key === 'ArrowUp') {
				this.menu.index = (this.menu.index - 1 + len) % len
				e.preventDefault()
			} else if (e.key === 'Enter' || e.key === 'Tab') {
				this.pick(this.menu.index)
				e.preventDefault()
			} else if (e.key === 'Escape') {
				this.closeMenu()
				e.preventDefault()
			}
		},
		pick(i) {
			const ta = this.$refs.ta
			const emoji = this.menu.items[i]
			if (!ta || !emoji) {
				return
			}
			const insert = this.useShortcodes ? ':' + emoji.id + ':' : emoji.native
			const caret = ta.selectionStart
			const value = ta.value
			const newValue = value.slice(0, this.menu.start) + insert + value.slice(caret)
			const pos = this.menu.start + insert.length
			this.closeMenu()
			this.$emit('update:modelValue', newValue)
			this.$nextTick(() => {
				ta.focus()
				ta.setSelectionRange(pos, pos)
			})
		},
		closeMenu() {
			this.menu.open = false
		},
	},
}
</script>

<style scoped>
.unity-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 8px);
	overflow: hidden;
}
.unity-editor-tabs {
	display: flex;
	gap: 4px;
	padding: 4px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}
.unity-editor-tab {
	background: transparent;
	border: none;
	border-radius: var(--border-radius, 6px);
	padding: 4px 12px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}
.unity-editor-tab.active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: bold;
}
.unity-editor-toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
	padding: 4px;
	border-bottom: 1px solid var(--color-border);
}
.unity-editor-btn {
	min-width: 30px;
	height: 30px;
	background: transparent;
	border: none;
	border-radius: var(--border-radius, 6px);
	cursor: pointer;
	color: var(--color-main-text);
	font-size: 0.85em;
}
.unity-editor-btn:hover {
	background: var(--color-background-hover);
}
.unity-editor-input {
	position: relative;
}
.unity-editor-textarea {
	width: 100%;
	border: none;
	padding: 8px;
	resize: vertical;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}
.unity-emoji-menu {
	position: absolute;
	left: 8px;
	bottom: 8px;
	z-index: 20;
	min-width: 180px;
	max-height: 200px;
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
	padding: 4px;
}
.unity-emoji-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border-radius: var(--border-radius, 6px);
	cursor: pointer;
}
.unity-emoji-item.active {
	background: var(--color-primary-element-light);
}
.unity-emoji-native {
	font-size: 1.1em;
}
.unity-emoji-name {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.unity-editor-preview {
	padding: 8px;
	min-height: 80px;
}
.unity-editor-empty {
	color: var(--color-text-maxcontrast);
}
</style>
