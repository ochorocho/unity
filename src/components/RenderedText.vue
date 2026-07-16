<template>
	<!-- Single root: a fragment root can't inherit fallthrough attributes, which would
	     silently drop the class and scoped-style attribute CommentList passes down.
	     It also gives the image-click delegation one place to live instead of four. -->
	<div class="unity-rendered-root" @click="onRootClick">
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-if="rendered" ref="htmlRoot" class="unity-rendered unity-html" :class="{ 'unity-tasks-editable': editable }" v-html="renderedSafe" @click="onRenderedTodo" />
		<div v-else-if="format === 'markdown'" ref="mdRoot" class="unity-rendered">
			<NcRichText :text="markdownText"
				:use-markdown="true"
				:use-extended-markdown="true"
				:interactive="editable"
				@interact-todo="onInteractTodo" />
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else-if="format === 'textile'" ref="textileRoot" class="unity-rendered unity-textile" v-html="textileHtml" />
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else-if="format === 'html'" ref="htmlFormatRoot" class="unity-rendered unity-html" v-html="html" />
		<span v-else class="unity-rendered unity-plaintext">{{ plainText }}</span>

		<FilePreview v-if="preview"
			:src="preview.src"
			:name="preview.name"
			kind="image"
			@close="preview = null" />
	</div>
</template>

<script>
import NcRichText from '@nextcloud/vue/components/NcRichText'
import FilePreview from './FilePreview.vue'
import { proxifyImages, imageAttributes } from '../markdown.js'
import { originalSrc, filenameFromUrl } from '../fileurl.js'
import { renderTextile, renderHtml } from '../render.js'
import { toggleTaskAt } from '../tasklist.js'
import { highlightCodeBlocks } from '../highlight.js'
import { emojify } from '../emoji.js'
import { stylizeMentions } from '../mentions.js'

export default {
	name: 'RenderedText',
	components: { NcRichText, FilePreview },
	props: {
		text: { type: String, default: '' },
		format: { type: String, default: 'plaintext' },
		issueRef: { type: String, default: '' },
		editable: { type: Boolean, default: false },
		// Provider-rendered HTML (e.g. GitLab's own Markdown renderer). When set it
		// takes precedence over `format`, is sanitized/image-proxied, and shown as
		// HTML; `text` remains the raw source (used by editing/task-toggle).
		rendered: { type: String, default: '' },
	},
	emits: ['update:text'],
	data() {
		return {
			// The inline image currently open in the preview modal, or null.
			preview: null,
		}
	},
	computed: {
		renderedSafe() {
			return this.rendered ? renderHtml(this.rendered, this.issueRef) : ''
		},
		markdownText() {
			return proxifyImages(emojify(this.text), this.issueRef)
		},
		textileHtml() {
			return renderTextile(emojify(this.text), this.issueRef)
		},
		html() {
			return renderHtml(emojify(this.text), this.issueRef)
		},
		plainText() {
			return emojify(this.text)
		},
	},
	watch: {
		// Re-enable task checkboxes and re-highlight code after a silent re-fetch
		// re-renders the provider HTML.
		renderedSafe() {
			this.$nextTick(() => {
				this.enableRenderedTasks()
				this.highlightRendered()
				this.applyMentionPills()
			})
		},
		textileHtml() {
			this.$nextTick(() => {
				this.highlightRendered()
				this.applyMentionPills()
			})
		},
		html() {
			this.$nextTick(() => {
				this.highlightRendered()
				this.applyMentionPills()
			})
		},
	},
	mounted() {
		if (this.format === 'markdown' && this.$refs.mdRoot) {
			// NcRichText renders (and re-renders on text change) asynchronously, so
			// reapply the dimensions and mention pills whenever the subtree changes.
			this.imageObserver = new MutationObserver(() => {
				this.applyImageDimensions()
				this.applyMentionPills()
			})
			this.imageObserver.observe(this.$refs.mdRoot, { childList: true, subtree: true })
			this.$nextTick(() => this.applyImageDimensions())
		}
		this.$nextTick(() => {
			if (this.rendered) {
				this.enableRenderedTasks()
			}
			// The NcRichText (markdown) branch highlights itself; the v-html branches don't.
			this.highlightRendered()
			this.applyMentionPills()
		})
	},
	beforeUnmount() {
		if (this.imageObserver) {
			this.imageObserver.disconnect()
			this.imageObserver = null
		}
	},
	methods: {
		// Open inline images in the preview modal. Delegated from the wrapper so it
		// covers all four render branches — including the NcRichText/markdown one,
		// which renders asynchronously and re-renders whenever the text changes.
		onRootClick(e) {
			const img = e.target.closest && e.target.closest('img:not(.emoji):not(.emoticon)')
			if (!img) {
				return
			}
			const src = img.getAttribute('src') || ''
			if (!src) {
				return // nothing to show; leave the click alone
			}
			// GitLab's ImageLinkFilter wraps inline images in an <a> to the upload URL,
			// and sanitizeHtml() gives every anchor target="_blank" — without this the
			// browser opens a new tab instead of the modal.
			e.preventDefault()
			// A rendered <img> src is ALREADY a proxy URL (proxifyImages / the sanitizeHtml
			// hook), so it is passed through unresolved. The name doubles as the download
			// filename, so prefer the upstream filename over alt — GitLab emits a generic
			// alt ("image") that would save a file with no extension.
			const name = filenameFromUrl(originalSrc(src)) || img.getAttribute('alt') || 'image'
			this.preview = { src, name }
		},
		// Apply GitLab-style image width/height (stripped from the markdown source)
		// as attributes on the rendered <img>; combined with the max-width:100% /
		// height:auto CSS the browser scales them responsively.
		applyImageDimensions() {
			if (this.format !== 'markdown' || !this.$refs.mdRoot) {
				return
			}
			const dims = imageAttributes(emojify(this.text))
			if (dims.size === 0) {
				return
			}
			this.$refs.mdRoot.querySelectorAll('img').forEach((img) => {
				const url = originalSrc(img.getAttribute('src'))
				if (!url) {
					return
				}
				const d = dims.get(url)
				if (!d) {
					return
				}
				if (d.width && img.getAttribute('width') !== d.width) {
					img.setAttribute('width', d.width)
				}
				if (d.height && img.getAttribute('height') !== d.height) {
					img.setAttribute('height', d.height)
				}
			})
		},
		// Normalize mentions into uniform pills in whichever branch is active. The
		// provider-HTML branches (Jira Cloud span, GitLab/Asana anchors) are exact;
		// the markdown (GitHub) and textile (Redmine) branches carry only plain
		// `@handle` text, so those use the wrapping heuristic.
		applyMentionPills() {
			stylizeMentions(this.$refs.htmlRoot, { heuristic: false })
			stylizeMentions(this.$refs.htmlFormatRoot, { heuristic: false })
			stylizeMentions(this.$refs.textileRoot, { heuristic: true })
			stylizeMentions(this.$refs.mdRoot, { heuristic: true })
		},
		// Syntax-highlight code blocks in whichever provider-HTML branch is rendered
		// (the NcRichText/markdown branch highlights itself).
		highlightRendered() {
			highlightCodeBlocks(this.$refs.htmlRoot || this.$refs.htmlFormatRoot || this.$refs.textileRoot)
		},
		// Server-rendered bodies (e.g. GitLab) come back with static, disabled task
		// checkboxes. When the body is editable, re-enable them so they can be ticked.
		enableRenderedTasks() {
			if (!this.rendered || !this.editable || !this.$refs.htmlRoot) {
				return
			}
			this.$refs.htmlRoot.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
				cb.disabled = false
				cb.style.cursor = 'pointer'
			})
		},
		// Toggle the clicked task in the server-rendered HTML by flipping the matching
		// marker in the raw Markdown (kept in `text`) — same emit contract as the
		// NcRichText path; the parent persists it and a silent re-fetch re-renders.
		// A click anywhere on the task-list item (the label text, not only the box)
		// toggles it, matching the NcRichText/Jira behaviour.
		onRenderedTodo(e) {
			if (!this.editable || !this.$refs.htmlRoot) {
				return
			}
			const target = e.target
			if (target.tagName === 'IMG') {
				return // an image click opens the preview (onRootClick), never toggles
			}
			if (target.closest && target.closest('a')) {
				return // don't toggle when clicking a link inside the item
			}
			const item = target.closest && target.closest('.task-list-item')
			if (!item) {
				return
			}
			const box = item.querySelector('input[type="checkbox"]')
			if (!box) {
				return
			}
			const boxes = [...this.$refs.htmlRoot.querySelectorAll('input[type="checkbox"]')]
			const index = boxes.indexOf(box)
			if (index === -1) {
				return
			}
			if (target !== box) {
				box.checked = !box.checked // reflect a label click immediately
			}
			this.$emit('update:text', toggleTaskAt(this.text, index))
		},
		onInteractTodo(id) {
			if (!this.editable || !this.$refs.mdRoot) {
				return
			}
			const boxes = [...this.$refs.mdRoot.querySelectorAll('input[type="checkbox"]')]
			const el = this.$refs.mdRoot.querySelector('[id="' + id + '"]')
			if (!el) {
				return
			}
			let index = boxes.indexOf(el)
			if (index === -1) {
				index = boxes.findIndex((b) => el.contains(b) || b.contains(el))
			}
			if (index === -1) {
				return
			}
			this.$emit('update:text', toggleTaskAt(this.text, index))
		},
	},
}
</script>

<style scoped>
.unity-plaintext {
	white-space: pre-wrap;
}
.unity-rendered :deep(img) {
	max-width: 100%;
	height: auto;
}
/* Inline images open in the preview modal (onRootClick). Emoji and Jira Server
   emoticons are decoration, not content — excluded here and in the handler so the
   affordance and the behaviour stay in sync. */
.unity-rendered :deep(img:not(.emoji):not(.emoticon)) {
	cursor: zoom-in;
}
.unity-rendered :deep(pre) {
	overflow-x: auto;
}
/* Code-block look + syntax-highlight tokens for the provider-HTML paths
   (GitLab/Asana/Jira Server/Redmine). Scoped to the v-html branches so it never
   touches the NcRichText/markdown branch, which ships its own hljs theme.
   GitHub "prettylights" colours (matching NcRichText), with a dark-mode variant. */
:is(.unity-html, .unity-textile) :deep(pre) {
	padding: 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}
:is(.unity-html, .unity-textile) :deep(pre code) {
	padding: 0;
	background: none;
}
:is(.unity-html, .unity-textile) :deep(.hljs-keyword),
:is(.unity-html, .unity-textile) :deep(.hljs-doctag),
:is(.unity-html, .unity-textile) :deep(.hljs-type),
:is(.unity-html, .unity-textile) :deep(.hljs-name),
:is(.unity-html, .unity-textile) :deep(.hljs-selector-tag) {
	color: #d73a49;
}
:is(.unity-html, .unity-textile) :deep(.hljs-title),
:is(.unity-html, .unity-textile) :deep(.hljs-title.function_),
:is(.unity-html, .unity-textile) :deep(.hljs-title.class_),
:is(.unity-html, .unity-textile) :deep(.hljs-section) {
	color: #6f42c1;
}
:is(.unity-html, .unity-textile) :deep(.hljs-attr),
:is(.unity-html, .unity-textile) :deep(.hljs-attribute),
:is(.unity-html, .unity-textile) :deep(.hljs-variable),
:is(.unity-html, .unity-textile) :deep(.hljs-literal),
:is(.unity-html, .unity-textile) :deep(.hljs-number),
:is(.unity-html, .unity-textile) :deep(.hljs-operator),
:is(.unity-html, .unity-textile) :deep(.hljs-selector-attr),
:is(.unity-html, .unity-textile) :deep(.hljs-selector-class),
:is(.unity-html, .unity-textile) :deep(.hljs-selector-id) {
	color: #005cc5;
}
:is(.unity-html, .unity-textile) :deep(.hljs-string),
:is(.unity-html, .unity-textile) :deep(.hljs-regexp) {
	color: #032f62;
}
:is(.unity-html, .unity-textile) :deep(.hljs-built_in),
:is(.unity-html, .unity-textile) :deep(.hljs-symbol) {
	color: #e36209;
}
:is(.unity-html, .unity-textile) :deep(.hljs-comment),
:is(.unity-html, .unity-textile) :deep(.hljs-quote),
:is(.unity-html, .unity-textile) :deep(.hljs-meta) {
	color: #6a737d;
}
@media (prefers-color-scheme: dark) {
	:is(.unity-html, .unity-textile) :deep(.hljs-keyword),
	:is(.unity-html, .unity-textile) :deep(.hljs-doctag),
	:is(.unity-html, .unity-textile) :deep(.hljs-type),
	:is(.unity-html, .unity-textile) :deep(.hljs-name),
	:is(.unity-html, .unity-textile) :deep(.hljs-selector-tag) {
		color: #ff7b72;
	}
	:is(.unity-html, .unity-textile) :deep(.hljs-title),
	:is(.unity-html, .unity-textile) :deep(.hljs-title.function_),
	:is(.unity-html, .unity-textile) :deep(.hljs-title.class_),
	:is(.unity-html, .unity-textile) :deep(.hljs-section) {
		color: #d2a8ff;
	}
	:is(.unity-html, .unity-textile) :deep(.hljs-attr),
	:is(.unity-html, .unity-textile) :deep(.hljs-attribute),
	:is(.unity-html, .unity-textile) :deep(.hljs-variable),
	:is(.unity-html, .unity-textile) :deep(.hljs-literal),
	:is(.unity-html, .unity-textile) :deep(.hljs-number),
	:is(.unity-html, .unity-textile) :deep(.hljs-operator),
	:is(.unity-html, .unity-textile) :deep(.hljs-selector-attr),
	:is(.unity-html, .unity-textile) :deep(.hljs-selector-class),
	:is(.unity-html, .unity-textile) :deep(.hljs-selector-id) {
		color: #79c0ff;
	}
	:is(.unity-html, .unity-textile) :deep(.hljs-string),
	:is(.unity-html, .unity-textile) :deep(.hljs-regexp) {
		color: #a5d6ff;
	}
	:is(.unity-html, .unity-textile) :deep(.hljs-built_in),
	:is(.unity-html, .unity-textile) :deep(.hljs-symbol) {
		color: #ffa657;
	}
	:is(.unity-html, .unity-textile) :deep(.hljs-comment),
	:is(.unity-html, .unity-textile) :deep(.hljs-quote),
	:is(.unity-html, .unity-textile) :deep(.hljs-meta) {
		color: #8b949e;
	}
}
/* Mention pill — matches the editor's mention chip so rendered @mentions read as
   mentions across every provider: a bordered chip with a person icon and the
   name (no leading '@'). Normalized by stylizeMentions() (src/mentions.js). */
.unity-rendered :deep(.unity-mention) {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 0 8px 0 6px;
	border: 1px solid var(--color-border-dark);
	border-radius: 1em;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 500;
	line-height: 1.4;
	text-decoration: none;
	white-space: nowrap;
	vertical-align: baseline;
}
.unity-rendered :deep(.unity-mention)::before {
	content: '';
	flex: 0 0 auto;
	width: 1em;
	height: 1em;
	background-color: currentColor;
	opacity: 0.7;
	-webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4a4 4 0 0 1 4 4 4 4 0 0 1-4 4 4 4 0 0 1-4-4 4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4Z'/%3E%3C/svg%3E") center / contain no-repeat;
	mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4a4 4 0 0 1 4 4 4 4 0 0 1-4 4 4 4 0 0 1-4-4 4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4Z'/%3E%3C/svg%3E") center / contain no-repeat;
}
.unity-rendered :deep(a.unity-mention:hover) {
	background: var(--color-background-hover);
}
.unity-rendered :deep(blockquote) {
	border-left: 4px solid var(--color-border-dark);
	padding-left: 12px;
	margin: 0 0 0.5em 0;
	color: var(--color-text-maxcontrast);
}
.unity-textile :deep(table),
.unity-html :deep(table) {
	border-collapse: collapse;
}
.unity-textile :deep(td),
.unity-textile :deep(th),
.unity-html :deep(td),
.unity-html :deep(th) {
	border: 1px solid var(--color-border);
	padding: 4px 8px;
}
/* Restore list markers + indentation in the provider-HTML / textile branches
   (Nextcloud's base CSS resets ul/ol); the NcRichText markdown branch styles itself. */
:is(.unity-html, .unity-textile) :deep(ul),
:is(.unity-html, .unity-textile) :deep(ol) {
	padding-inline-start: 1.5em;
	margin: 0 0 0.5em;
}
:is(.unity-html, .unity-textile) :deep(ul) {
	list-style: disc;
}
:is(.unity-html, .unity-textile) :deep(ol) {
	list-style: decimal;
}
:is(.unity-html, .unity-textile) :deep(li) {
	margin: 0.15em 0;
}
/* Our ADF→HTML wraps each list item's text in a <p>; drop its block margins so
   simple items stay on one line. */
:is(.unity-html, .unity-textile) :deep(li > p:only-child) {
	margin: 0;
}
.unity-rendered :deep(.task-list-item) {
	list-style: none;
}
/* When the body is editable, the whole task row toggles on click — signal it and
   mirror the NcRichText (Jira) checkbox hover affordance for the provider-rendered
   task lists (GitLab/Asana). */
.unity-tasks-editable :deep(.task-list-item) {
	cursor: pointer;
	/* Shrink to the checkbox + label so the hover highlight is inline, not full-width. */
	width: fit-content;
	border-radius: var(--border-radius-element);
	padding-block: 2px;
	padding-inline: 4px;
	margin-inline-start: -4px;
}
.unity-tasks-editable :deep(.task-list-item):hover {
	background-color: var(--color-background-hover);
}
.unity-tasks-editable :deep(.task-list-item):has(input:checked):hover {
	background-color: var(--color-primary-element-light-hover);
}
/* Vertically center the checkbox against its label in server-rendered task lists
   (e.g. GitLab), which otherwise sit the box on the text baseline. */
.unity-html :deep(.task-list-item) input[type="checkbox"] {
	vertical-align: middle;
	margin: 0 0.4em 0 0;
}
</style>
