<template>
	<NcDialog :name="name" size="large" @closing="$emit('close')">
		<div class="unity-preview" :class="{ 'unity-preview--doc': kind === 'document' }">
			<img v-if="kind === 'image'" :src="src" :alt="name">
			<!-- eslint-disable-next-line vue/html-self-closing -->
			<video v-else-if="kind === 'video'" :src="src" controls></video>
			<!-- eslint-disable-next-line vue/html-self-closing -->
			<audio v-else-if="kind === 'audio'" :src="src" controls></audio>
			<iframe v-else :src="src" :title="name" class="unity-preview-frame" />
		</div>
		<template #actions>
			<NcButton :href="src" :download="name" variant="secondary">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('unity', 'Download') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'FilePreview',
	components: { NcDialog, NcButton, Download },
	props: {
		// An already-resolved, directly loadable URL. Callers resolve it themselves:
		// attachments hold a raw upstream URL and must wrap it in the file proxy, while
		// a rendered inline <img> already carries the proxy URL in its src. Resolving
		// here would double-proxy the latter.
		src: { type: String, required: true },
		// Dialog heading and download filename. Must be non-empty: NcButton renders
		// `download: props.download || undefined`, so an empty name drops the attribute
		// and the button navigates instead of downloading.
		name: { type: String, required: true },
		// Which element to render. The mime type is only ever used to make this choice,
		// so callers map to it: attachments from their mimeType, inline images know it.
		kind: {
			type: String,
			default: 'image',
			validator: (v) => ['image', 'video', 'audio', 'document'].includes(v),
		},
	},
	emits: ['close'],
	// Rendered only while open (the parent v-if's it), so the listener's lifetime is
	// the preview's lifetime and the handler needs no open-check.
	mounted() {
		// Close the preview on Escape, and mark the event handled so App.vue's global
		// Escape handler doesn't also close the issue detail behind it. Capture phase
		// runs before the bubbling window listeners in App.vue and NcModal.
		window.addEventListener('keydown', this.onKeydown, true)
	},
	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown, true)
	},
	methods: {
		onKeydown(e) {
			if (e.key === 'Escape') {
				this.$emit('close')
				e.stopPropagation()
				// Marks the event consumed. App.vue keys off defaultPrevented rather than
				// probing the DOM for a mask, which by then has already been removed.
				e.preventDefault()
			}
		},
	},
}
</script>

<style scoped>
.unity-preview {
	display: flex;
	justify-content: center;
	padding: 12px;
}
.unity-preview img,
.unity-preview video {
	max-width: 100%;
	max-height: 75vh;
	object-fit: contain;
}
.unity-preview audio {
	width: 100%;
}
/* PDF/text fill the dialog with the browser's own viewer. */
.unity-preview--doc {
	display: block;
	padding: 0;
}
.unity-preview-frame {
	width: 100%;
	height: 75vh;
	border: none;
}
</style>
