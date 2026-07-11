<template>
	<div class="unity-records">
		<NcLoadingIcon v-if="loading" :size="20" />
		<template v-else-if="records.length">
			<ul class="unity-records-list">
				<li v-for="r in pagedRecords" :key="r.id" class="unity-record">
					<div class="unity-record-main">
						<span class="unity-record-head">
							<strong>{{ humanize(r.seconds) }}</strong>
							<span v-if="r.author" class="unity-record-author">· {{ r.author }}</span>
							<span v-if="r.date" class="unity-record-date">· {{ formatDate(r.date) }}</span>
						</span>
						<span v-if="stripHtml(r.comment)" class="unity-record-comment">{{ stripHtml(r.comment) }}</span>
					</div>
					<div v-if="r.editable || r.deletable" class="unity-record-actions">
						<NcButton v-if="r.editable"
							type="tertiary"
							:aria-label="t('unity', 'Edit time entry')"
							:title="t('unity', 'Edit time entry')"
							@click="$emit('edit', r)">
							<template #icon><Pencil :size="18" /></template>
						</NcButton>
						<NcButton v-if="r.deletable"
							type="tertiary"
							:aria-label="t('unity', 'Delete time entry')"
							:title="t('unity', 'Delete time entry')"
							:disabled="deletingId === r.id"
							@click="remove(r)">
							<template #icon>
								<NcLoadingIcon v-if="deletingId === r.id" :size="18" />
								<Delete v-else :size="18" />
							</template>
						</NcButton>
					</div>
				</li>
			</ul>
			<div v-if="pageCount > 1" class="unity-records-pager">
				<NcButton type="tertiary"
					:disabled="safePage === 0"
					:aria-label="t('unity', 'Previous page')"
					@click="prev">
					<template #icon><ChevronLeft :size="18" /></template>
				</NcButton>
				<span class="unity-records-page">{{ t('unity', 'Page {current} of {total}', { current: safePage + 1, total: pageCount }) }}</span>
				<NcButton type="tertiary"
					:disabled="safePage >= pageCount - 1"
					:aria-label="t('unity', 'Next page')"
					@click="next">
					<template #icon><ChevronRight :size="18" /></template>
				</NcButton>
			</div>
		</template>
		<p v-else class="unity-records-empty">{{ t('unity', 'No individual time records.') }}</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { humanizeDuration } from '../duration.js'
import { stripHtml } from '../render.js'

/** Number of time entries shown per page. */
const PAGE_SIZE = 5

export default {
	name: 'TimeRecords',
	components: { NcButton, NcLoadingIcon, Pencil, Delete, ChevronLeft, ChevronRight },
	props: {
		issueRef: { type: String, required: true },
		reloadKey: { type: Number, default: 0 },
	},
	emits: ['edit', 'changed'],
	data() {
		return {
			records: [],
			loading: false,
			deletingId: null,
			page: 0,
		}
	},
	computed: {
		// Newest entries first, so "page 1" always shows the latest work.
		sortedRecords() {
			return [...this.records].sort((a, b) => this.dateValue(b.date) - this.dateValue(a.date))
		},
		pageCount() {
			return Math.max(1, Math.ceil(this.sortedRecords.length / PAGE_SIZE))
		},
		// Guard against a page index left dangling after records shrink.
		safePage() {
			return Math.min(this.page, this.pageCount - 1)
		},
		pagedRecords() {
			const start = this.safePage * PAGE_SIZE
			return this.sortedRecords.slice(start, start + PAGE_SIZE)
		},
	},
	watch: {
		issueRef() {
			this.page = 0
			this.fetch()
		},
		reloadKey() {
			this.page = 0
			this.fetch()
		},
	},
	mounted() {
		this.fetch()
	},
	methods: {
		stripHtml,
		humanize(seconds) {
			return humanizeDuration(seconds)
		},
		dateValue(value) {
			const ms = value ? moment(value).valueOf() : 0
			return Number.isNaN(ms) ? 0 : ms
		},
		prev() {
			if (this.safePage > 0) {
				this.page = this.safePage - 1
			}
		},
		next() {
			if (this.safePage < this.pageCount - 1) {
				this.page = this.safePage + 1
			}
		},
		formatDate(value) {
			return value ? moment(value).format('ll') : ''
		},
		async fetch() {
			// Only show the full-block spinner on the first load. On a refetch (e.g.
			// switching issues) keep the current rows rendered until the new data
			// arrives, so the block never collapses to a spinner and "rebuilds".
			const first = this.records.length === 0
			if (first) {
				this.loading = true
			}
			try {
				const ref = encodeURIComponent(this.issueRef)
				const { data } = await axios.get(generateUrl('/apps/unity/issues/{ref}/time', { ref }))
				this.records = Array.isArray(data) ? data : []
			} catch (e) {
				if (first) {
					this.records = []
				}
			} finally {
				this.loading = false
			}
		},
		async remove(record) {
			const confirmed = await showConfirmation({
				name: this.t('unity', 'Delete time entry'),
				text: this.t('unity', 'Delete this time entry of {duration}? This cannot be undone.', { duration: humanizeDuration(record.seconds) }),
				labelConfirm: this.t('unity', 'Delete'),
				severity: 'error',
			})
			if (!confirmed) {
				return
			}
			this.deletingId = record.id
			try {
				const ref = encodeURIComponent(this.issueRef)
				const recordId = encodeURIComponent(record.id)
				await axios.delete(generateUrl('/apps/unity/issues/{ref}/time/{recordId}', { ref, recordId }))
				showSuccess(this.t('unity', 'Time entry deleted'))
				await this.fetch()
				this.$emit('changed')
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not delete time entry'))
			} finally {
				this.deletingId = null
			}
		},
	},
}
</script>

<style scoped>
.unity-records-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 6px;
}
.unity-record {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 8px;
	padding: 6px 0;
	border-top: 1px solid var(--color-border);
}
.unity-record-main {
	display: flex;
	flex-direction: column;
	min-width: 0;
}
.unity-record-actions {
	display: flex;
	gap: 2px;
	flex-shrink: 0;
}
.unity-record-head {
	font-size: 0.9em;
}
.unity-record-author,
.unity-record-date {
	color: var(--color-text-maxcontrast);
}
.unity-record-comment {
	font-size: 0.9em;
	margin-top: 2px;
	white-space: pre-wrap;
}
.unity-records-empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 6px;
}
.unity-records-pager {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	margin-top: 8px;
}
.unity-records-page {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
</style>
