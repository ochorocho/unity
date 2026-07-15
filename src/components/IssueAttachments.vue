<template>
	<div class="unity-attachments">
		<div class="unity-attachments-header">
			<span class="unity-attachments-title">{{ t('unity', 'Attachments') }}</span>
			<NcButton type="secondary" :disabled="uploading" @click="pick">
				<template #icon>
					<NcLoadingIcon v-if="uploading" :size="18" />
					<Paperclip v-else :size="18" />
				</template>
				{{ t('unity', 'Add attachment') }}
			</NcButton>
			<input ref="fileInput" type="file" class="unity-attachments-input" @change="onFile">
		</div>

		<NcLoadingIcon v-if="loading" :size="20" />
		<ul v-else-if="attachments.length" class="unity-attachments-list">
			<li v-for="a in attachments" :key="a.id" class="unity-attachment">
				<a v-if="previewable(a)"
					href="#"
					class="unity-attachment-thumb"
					:class="{ 'unity-attachment-thumb--icon': !isImage(a) }"
					:aria-label="t('unity', 'Preview {name}', { name: a.filename })"
					@click.prevent="preview = a">
					<img v-if="isImage(a)" :src="thumbUrl(a)" :alt="a.filename">
					<FilePdfBox v-else-if="isPdf(a)" :size="26" />
					<FileIcon v-else :size="26" />
				</a>
				<span v-else class="unity-attachment-thumb unity-attachment-thumb--icon">
					<FileIcon :size="26" />
				</span>
				<div class="unity-attachment-main">
					<a v-if="previewable(a)"
						href="#"
						class="unity-attachment-name"
						@click.prevent="preview = a">{{ a.filename }}</a>
					<a v-else
						:href="proxy(a.src)"
						:download="a.filename"
						class="unity-attachment-name"
						target="_blank"
						rel="noopener noreferrer">{{ a.filename }}</a>
					<span class="unity-attachment-meta">
						{{ humanSize(a.size) }}<span v-if="a.author"> · {{ a.author }}</span>
					</span>
				</div>
				<a :href="proxy(a.src)"
					:download="a.filename"
					class="unity-attachment-dl"
					:aria-label="t('unity', 'Download')"
					:title="t('unity', 'Download')">
					<Download :size="18" />
				</a>
				<NcButton type="tertiary"
					:aria-label="t('unity', 'Delete attachment')"
					:title="t('unity', 'Delete attachment')"
					:disabled="deletingId === a.id"
					@click="remove(a)">
					<template #icon>
						<NcLoadingIcon v-if="deletingId === a.id" :size="18" />
						<Delete v-else :size="18" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p v-else class="unity-attachments-empty">{{ t('unity', 'No attachments.') }}</p>

		<NcDialog v-if="preview"
			:name="preview.filename"
			size="large"
			@closing="preview = null">
			<div class="unity-preview" :class="{ 'unity-preview--doc': isDocument(preview) }">
				<img v-if="isImage(preview)" :src="proxy(preview.src)" :alt="preview.filename">
				<!-- eslint-disable-next-line vue/html-self-closing -->
				<video v-else-if="isVideo(preview)" :src="proxy(preview.src)" controls></video>
				<!-- eslint-disable-next-line vue/html-self-closing -->
				<audio v-else-if="isAudio(preview)" :src="proxy(preview.src)" controls></audio>
				<iframe v-else :src="proxy(preview.src)" :title="preview.filename" class="unity-preview-frame" />
			</div>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'

export default {
	name: 'IssueAttachments',
	components: { NcButton, NcLoadingIcon, NcDialog, Paperclip, Download, Delete, FileIcon, FilePdfBox },
	props: {
		issueRef: { type: String, required: true },
		reloadKey: { type: Number, default: 0 },
	},
	emits: ['changed'],
	data() {
		return {
			attachments: [],
			loading: false,
			uploading: false,
			deletingId: null,
			// The attachment currently open in the preview modal, or null.
			preview: null,
		}
	},
	watch: {
		issueRef() {
			this.preview = null
			this.fetch()
		},
		reloadKey() {
			this.fetch()
		},
	},
	mounted() {
		this.fetch()
		// Own Escape while the preview is open so it closes the modal only — not the
		// issue detail behind it (App.vue's global Escape handler closes that). Capture
		// phase runs before the bubbling window listeners in App.vue and NcModal.
		window.addEventListener('keydown', this.onPreviewKeydown, true)
	},
	beforeUnmount() {
		window.removeEventListener('keydown', this.onPreviewKeydown, true)
	},
	methods: {
		onPreviewKeydown(e) {
			if (e.key === 'Escape' && this.preview) {
				this.preview = null
				e.stopPropagation()
				e.preventDefault()
			}
		},
		proxy(src) {
			const ref = encodeURIComponent(this.issueRef)
			return generateUrl('/apps/unity/issues/{ref}/file', { ref }) + '?src=' + encodeURIComponent(src)
		},
		mimeType(a) {
			return typeof a.mimeType === 'string' ? a.mimeType : ''
		},
		isImage(a) {
			return this.mimeType(a).startsWith('image/')
		},
		isVideo(a) {
			return this.mimeType(a).startsWith('video/')
		},
		isAudio(a) {
			return this.mimeType(a).startsWith('audio/')
		},
		isPdf(a) {
			return this.mimeType(a) === 'application/pdf'
		},
		isText(a) {
			return this.mimeType(a).startsWith('text/')
		},
		// PDF/text render in an iframe (the browser's built-in viewer).
		isDocument(a) {
			return this.isPdf(a) || this.isText(a)
		},
		// Types the browser can render inline in the preview modal.
		previewable(a) {
			return this.isImage(a) || this.isVideo(a) || this.isAudio(a) || this.isPdf(a) || this.isText(a)
		},
		thumbUrl(a) {
			return this.proxy(a.thumbnailSrc || a.src)
		},
		humanSize(bytes) {
			let v = Number(bytes) || 0
			if (v < 1024) {
				return `${v} B`
			}
			const units = ['KB', 'MB', 'GB', 'TB']
			let i = -1
			do {
				v /= 1024
				i++
			} while (v >= 1024 && i < units.length - 1)
			return `${v.toFixed(v >= 10 ? 0 : 1)} ${units[i]}`
		},
		async fetch() {
			// Keep existing rows on refetch (issue switch) until new data arrives.
			const first = this.attachments.length === 0
			if (first) {
				this.loading = true
			}
			try {
				const ref = encodeURIComponent(this.issueRef)
				const { data } = await axios.get(generateUrl('/apps/unity/issues/{ref}/attachments', { ref }))
				this.attachments = Array.isArray(data?.attachments) ? data.attachments : []
			} catch (e) {
				if (first) {
					this.attachments = []
				}
			} finally {
				this.loading = false
			}
		},
		pick() {
			this.$refs.fileInput.click()
		},
		async onFile(e) {
			const files = e.target.files ? Array.from(e.target.files) : []
			e.target.value = '' // allow re-selecting the same file later
			await this.uploadFiles(files)
		},
		/**
		 * Upload one or more files to the issue, one request each. Shared by the
		 * "Add attachment" picker and the parent's drag-and-drop drop zone (called
		 * via a $ref). Refreshes the list and emits `changed` when any succeed.
		 *
		 * @param {File[]|FileList} files files to upload
		 */
		async uploadFiles(files) {
			const list = Array.from(files || []).filter(Boolean)
			if (list.length === 0) {
				return
			}
			this.uploading = true
			let ok = 0
			try {
				const ref = encodeURIComponent(this.issueRef)
				for (const file of list) {
					try {
						const fd = new FormData()
						fd.append('file', file)
						await axios.post(generateUrl('/apps/unity/issues/{ref}/attachments', { ref }), fd)
						ok++
					} catch (err) {
						showError(err?.response?.data?.error
							|| this.t('unity', 'Could not upload {name}', { name: file.name }))
					}
				}
				if (ok > 0) {
					showSuccess(ok === 1
						? this.t('unity', 'Attachment uploaded')
						: this.t('unity', '{count} attachments uploaded', { count: ok }))
					await this.fetch()
					this.$emit('changed')
				}
			} finally {
				this.uploading = false
			}
		},
		async remove(a) {
			const confirmed = await showConfirmation({
				name: this.t('unity', 'Delete attachment'),
				text: this.t('unity', 'Delete “{name}”? This cannot be undone.', { name: a.filename }),
				labelConfirm: this.t('unity', 'Delete'),
				severity: 'error',
			})
			if (!confirmed) {
				return
			}
			this.deletingId = a.id
			try {
				const ref = encodeURIComponent(this.issueRef)
				const attachmentId = encodeURIComponent(a.id)
				await axios.delete(generateUrl('/apps/unity/issues/{ref}/attachments/{attachmentId}', { ref, attachmentId }))
				showSuccess(this.t('unity', 'Attachment deleted'))
				await this.fetch()
				this.$emit('changed')
			} catch (err) {
				showError(err?.response?.data?.error || this.t('unity', 'Could not delete the attachment'))
			} finally {
				this.deletingId = null
			}
		},
	},
}
</script>

<style scoped>
.unity-attachments {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}
.unity-attachments-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}
.unity-attachments-title {
	font-weight: bold;
}
.unity-attachments-input {
	display: none;
}
.unity-attachments-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 8px;
}
.unity-attachment {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 6px 0;
	border-top: 1px solid var(--color-border);
}
.unity-attachment-thumb {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	flex-shrink: 0;
	border-radius: var(--border-radius-element, 8px);
	overflow: hidden;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
.unity-attachment-thumb img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
.unity-attachment-main {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex-grow: 1;
}
.unity-attachment-name {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.unity-attachment-meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-attachment-dl {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	padding: 6px;
	color: var(--color-main-text);
	border-radius: var(--border-radius-element, 8px);
}
.unity-attachment-dl:hover {
	background: var(--color-background-hover);
}
.unity-attachments-empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 8px;
}
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
