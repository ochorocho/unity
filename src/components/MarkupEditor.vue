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
					@mousedown.prevent="apply(a)">
					{{ a.label }}
				</button>
			</div>
			<div class="unity-editor-input"
				:style="{ '--unity-editor-min-height': minHeight }"
				@dragover="onDragOver"
				@drop="onDrop"
				@paste="onPaste">
				<NcRichContenteditable ref="editor"
					:model-value="modelValue"
					:label="placeholder"
					:placeholder="placeholder"
					:multiline="true"
					:auto-complete="mentionSearch"
					:user-data="mentionUserData"
					@update:model-value="$emit('update:modelValue', $event)" />
			</div>
		</div>

		<div v-show="tab === 'preview'" class="unity-editor-preview">
			<RenderedText v-if="modelValue" :text="previewText" :format="previewFormat" :issue-ref="issueRef" />
			<p v-else class="unity-editor-empty">{{ t('unity', 'Nothing to preview') }}</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcRichContenteditable from '@nextcloud/vue/components/NcRichContenteditable'
import RenderedText from './RenderedText.vue'
import { trackerById } from '../trackers.js'
import { toolbarFor, actionText } from '../editor.js'

// Monotonic id for inline-upload placeholders, unique across all editor instances
// so concurrent uploads never collide on their `](uploading-<id>)` anchor.
let uploadUid = 0

export default {
	name: 'MarkupEditor',
	components: { NcRichContenteditable, RenderedText },
	props: {
		modelValue: { type: String, default: '' },
		// The tracker body format ('markdown' | 'textile' | 'html' | 'plaintext').
		// Drives how the Preview tab renders.
		format: { type: String, default: 'markdown' },
		issueRef: { type: String, default: '' },
		tracker: { type: String, default: '' },
		placeholder: { type: String, default: '' },
		rows: { type: Number, default: 6 },
		// Create context (no issueRef yet): connection id + project the @mention
		// user search should scope to. Ignored when issueRef is set.
		connection: { type: String, default: '' },
		project: { type: String, default: '' },
		// Existing mentions in the initial value, as {id, label}, so their
		// @"user/<handle>" tokens render as pills when editing (not just newly
		// picked ones). id is the canonical 'user/<handle>' token id.
		mentions: { type: Array, default: () => [] },
	},
	emits: ['update:modelValue'],
	data() {
		// Seed userData with the initial mentions so pills render before
		// NcRichContenteditable first paints; mentionSearch adds more as the user types.
		const mentionUserData = {}
		for (const m of this.mentions) {
			if (m && m.id) {
				mentionUserData[m.id] = { id: m.id, label: m.label || m.id, icon: 'icon-user', source: 'unity' }
			}
		}
		return {
			tab: 'write',
			// id -> autocomplete item, so NcRichContenteditable can render a picked
			// mention as a pill (it looks up the @token id in this map).
			mentionUserData,
		}
	},
	computed: {
		// NcRichContenteditable leaves `<`/`>` HTML-escaped in its value; decode them
		// for Preview so it matches the saved result (the backend does the same on save).
		previewText() {
			return this.modelValue.replace(/&lt;/g, '<').replace(/&gt;/g, '>')
		},
		// Preview must match how the saved body is displayed, so an HTML body
		// (e.g. Jira Server/DC) renders as HTML instead of showing raw tags.
		previewFormat() {
			if (this.format === 'html') {
				return 'html'
			}
			return this.format === 'textile' ? 'textile' : 'markdown'
		},
		mentionsEnabled() {
			return trackerById(this.tracker).mention
		},
		// Inline file upload (drag/paste into the editor) is only offered for trackers
		// with an upload endpoint (GitLab) and when we have an issue ref to scope it to.
		uploadEnabled() {
			return trackerById(this.tracker).inlineUpload && !!this.issueRef
		},
		minHeight() {
			return Math.max(3, this.rows) * 1.4 + 'em'
		},
		// Toolbar snippets come in markdown and textile flavours; anything that
		// isn't textile (markdown, html, plaintext) uses the markdown toolbar.
		syntax() {
			return this.format === 'textile' ? 'textile' : 'markdown'
		},
		toolbar() {
			return toolbarFor(this.syntax)
		},
	},
	methods: {
		/** The NcRichContenteditable's editable element, or null. */
		editorEl() {
			const root = this.$refs.editor && this.$refs.editor.$el
			return root ? root.querySelector('[contenteditable]') : null
		},
		/**
		 * Apply a formatting toolbar action to the current selection. Buttons use
		 * @mousedown.prevent so focus/selection stay in the contenteditable, then
		 * document.execCommand('insertText') replaces the selection — which fires the
		 * input event NcRichContenteditable listens to, so modelValue updates.
		 *
		 * @param {object} action a toolbar action from editor.js
		 */
		apply(action) {
			const el = this.editorEl()
			if (!el) {
				return
			}
			if (document.activeElement !== el) {
				el.focus()
			}
			const selection = window.getSelection()
			const selected = selection ? selection.toString() : ''
			const text = actionText(action, selected)
			if (text === null) {
				return
			}
			document.execCommand('insertText', false, text)
			// For an empty-selection wrap (e.g. **|**), drop the caret between the
			// markers so the user can type inside them.
			if (action.wrap && selected === '' && action.wrap[1]) {
				try {
					const sel = window.getSelection()
					for (let i = 0; i < action.wrap[1].length; i++) {
						sel.modify('move', 'backward', 'character')
					}
				} catch (e) {
					// Selection.modify is non-standard; caret just stays after the markers.
				}
			}
		},
		/**
		 * NcRichContenteditable's `@` autocomplete source. Reuses the assignee/user
		 * search and maps each user to a mention option whose id is the canonical
		 * `user/<handle>` token — NcRichContenteditable then stores it in the body
		 * as `@"user/<handle>"`, which the backend rewrites to the provider-native
		 * mention. The option's id is also recorded in userData so the picked mention
		 * renders as a pill.
		 *
		 * @param {string} search the text typed after `@`
		 * @param {Function} callback receives the list of mention options
		 */
		mentionSearch(search, callback) {
			if (!this.mentionsEnabled) {
				callback([])
				return
			}
			const params = { query: (search || '').trim() }
			let url
			if (this.issueRef) {
				url = generateUrl('/apps/unity/issues/{ref}/assignees', { ref: this.issueRef })
			} else if (this.connection) {
				url = generateUrl('/apps/unity/create-assignees')
				params.connection = this.connection
				params.project = this.project
			} else {
				callback([])
				return
			}
			axios.get(url, { params }).then(({ data }) => {
				const users = Array.isArray(data) ? data : []
				callback(users.map((u) => {
					const handle = u.mention || u.id
					// `user/<handle>` is the token id NcRichContenteditable recognizes
					// for handles containing a colon (e.g. Jira accountIds); the backend
					// rewrites @"user/<handle>" to the provider-native mention.
					const id = 'user/' + handle
					const item = { id, label: u.name || handle, icon: 'icon-user', source: 'unity' }
					this.mentionUserData[id] = item
					return item
				}))
			}).catch(() => callback([]))
		},
		/** True while a file (not text/other) is being dragged. */
		isFileDrag(e) {
			const types = e.dataTransfer && e.dataTransfer.types
			return !!types && Array.prototype.indexOf.call(types, 'Files') !== -1
		},
		onDragOver(e) {
			// preventDefault marks the editor as a valid file drop target.
			if (this.uploadEnabled && this.isFileDrag(e)) {
				e.preventDefault()
			}
		},
		onDrop(e) {
			if (!this.uploadEnabled) {
				return
			}
			const files = e.dataTransfer ? Array.from(e.dataTransfer.files) : []
			if (!files.length) {
				return
			}
			// Handle it here; don't let it bubble to IssueDetail's attachment dropzone.
			e.preventDefault()
			e.stopPropagation()
			this.uploadFiles(files)
		},
		onPaste(e) {
			if (!this.uploadEnabled) {
				return
			}
			const files = e.clipboardData ? Array.from(e.clipboardData.files) : []
			if (!files.length) {
				return // let the editor handle a normal text paste
			}
			e.preventDefault()
			this.uploadFiles(files)
		},
		/** Upload dropped/pasted files one at a time, inserting each result at the caret. */
		async uploadFiles(files) {
			for (const file of files) {
				await this.uploadOne(file)
			}
		},
		async uploadOne(file) {
			const id = ++uploadUid
			// Strip []() from the shown name so the placeholder's markdown stays parseable.
			const safeName = (file.name || 'file').replace(/[[\]()]/g, '')
			const placeholder = `![${this.t('unity', 'Uploading {name}…', { name: safeName })}](uploading-${id})`
			this.insertAtCaret(placeholder + '\n')
			try {
				const fd = new FormData()
				fd.append('file', file)
				const { data } = await axios.post(generateUrl('/apps/unity/issues/{ref}/upload', { ref: this.issueRef }), fd)
				const md = data && data.markdown ? data.markdown : ''
				this.replacePlaceholder(id, md ? md + '\n' : '')
			} catch (e) {
				this.replacePlaceholder(id, '')
				showError(e?.response?.data?.error || this.t('unity', 'Could not upload {name}', { name: file.name }))
			}
		},
		/** Insert text at the caret, mirroring apply()'s execCommand approach. */
		insertAtCaret(text) {
			const el = this.editorEl()
			if (!el) {
				this.$emit('update:modelValue', (this.modelValue || '') + text)
				return
			}
			if (document.activeElement !== el) {
				el.focus()
			}
			document.execCommand('insertText', false, text)
		},
		/**
		 * Swap the upload placeholder for its final markdown (or remove it on failure).
		 * Matches on the unique `](uploading-<id>)` anchor so it works regardless of
		 * where the caret has since moved or how the filename rendered.
		 */
		replacePlaceholder(id, replacement) {
			const re = new RegExp('!\\[[^\\]]*\\]\\(uploading-' + id + '\\)\\n?')
			this.$emit('update:modelValue', (this.modelValue || '').replace(re, replacement))
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
	padding: 4px 8px;
}
.unity-editor-input :deep(.rich-contenteditable__input) {
	min-height: var(--unity-editor-min-height, 6em);
	max-height: 40vh;
}
.unity-editor-preview {
	padding: 8px;
	min-height: 80px;
}
.unity-editor-empty {
	color: var(--color-text-maxcontrast);
}
</style>
