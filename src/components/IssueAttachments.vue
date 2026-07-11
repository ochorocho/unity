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
				<a v-if="isImage(a)"
					href="#"
					class="unity-attachment-thumb"
					:aria-label="a.filename"
					@click.prevent="lightbox = a">
					<img :src="thumbUrl(a)" :alt="a.filename">
				</a>
				<span v-else class="unity-attachment-thumb unity-attachment-thumb--icon">
					<FileIcon :size="26" />
				</span>
				<div class="unity-attachment-main">
					<a :href="proxy(a.src)"
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
			</li>
		</ul>
		<p v-else class="unity-attachments-empty">{{ t('unity', 'No attachments.') }}</p>

		<NcDialog v-if="lightbox"
			:name="lightbox.filename"
			size="large"
			@closing="lightbox = null">
			<div class="unity-lightbox">
				<img :src="proxy(lightbox.src)" :alt="lightbox.filename">
			</div>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileIcon from 'vue-material-design-icons/File.vue'

export default {
	name: 'IssueAttachments',
	components: { NcButton, NcLoadingIcon, NcDialog, Paperclip, Download, FileIcon },
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
			lightbox: null,
		}
	},
	watch: {
		issueRef() {
			this.lightbox = null
			this.fetch()
		},
		reloadKey() {
			this.fetch()
		},
	},
	mounted() {
		this.fetch()
	},
	methods: {
		proxy(src) {
			const ref = encodeURIComponent(this.issueRef)
			return generateUrl('/apps/unity/issues/{ref}/file', { ref }) + '?src=' + encodeURIComponent(src)
		},
		isImage(a) {
			return typeof a.mimeType === 'string' && a.mimeType.startsWith('image/')
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
			const file = e.target.files && e.target.files[0]
			e.target.value = '' // allow re-selecting the same file later
			if (!file) {
				return
			}
			this.uploading = true
			try {
				const ref = encodeURIComponent(this.issueRef)
				const fd = new FormData()
				fd.append('file', file)
				await axios.post(generateUrl('/apps/unity/issues/{ref}/attachments', { ref }), fd)
				showSuccess(this.t('unity', 'Attachment uploaded'))
				await this.fetch()
				this.$emit('changed')
			} catch (err) {
				showError(err?.response?.data?.error || this.t('unity', 'Could not upload the attachment'))
			} finally {
				this.uploading = false
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
.unity-lightbox {
	display: flex;
	justify-content: center;
	padding: 12px;
}
.unity-lightbox img {
	max-width: 100%;
	max-height: 75vh;
	object-fit: contain;
}
</style>
