<template>
	<div v-if="format === 'markdown'" ref="mdRoot" class="unity-rendered">
		<NcRichText :text="markdownText"
			:use-markdown="true"
			:use-extended-markdown="true"
			:interactive="editable"
			@interact-todo="onInteractTodo" />
	</div>
	<!-- eslint-disable-next-line vue/no-v-html -->
	<div v-else-if="format === 'textile'" class="unity-rendered unity-textile" v-html="textileHtml" />
	<!-- eslint-disable-next-line vue/no-v-html -->
	<div v-else-if="format === 'html'" class="unity-rendered unity-html" v-html="html" />
	<span v-else class="unity-rendered unity-plaintext">{{ plainText }}</span>
</template>

<script>
import NcRichText from '@nextcloud/vue/components/NcRichText'
import { proxifyImages } from '../markdown.js'
import { renderTextile, renderHtml } from '../render.js'
import { toggleTaskAt } from '../tasklist.js'
import { emojify } from '../emoji.js'

export default {
	name: 'RenderedText',
	components: { NcRichText },
	props: {
		text: { type: String, default: '' },
		format: { type: String, default: 'plaintext' },
		issueRef: { type: String, default: '' },
		editable: { type: Boolean, default: false },
	},
	emits: ['update:text'],
	computed: {
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
	methods: {
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
.unity-rendered :deep(pre) {
	overflow-x: auto;
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
.unity-rendered :deep(.task-list-item) {
	list-style: none;
}
</style>
