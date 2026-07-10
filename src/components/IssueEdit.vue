<template>
	<div class="unity-edit">
		<label class="unity-edit-label">{{ t('unity', 'Title') }}</label>
		<NcTextField v-model="form.title" :label="t('unity', 'Title')" />

		<label class="unity-edit-label">{{ t('unity', 'Description') }}</label>
		<MarkupEditor v-model="form.description" :syntax="syntax" :issue-ref="issue.ref" :tracker="issue.tracker" :rows="8" />

		<template v-if="meta">
			<div v-if="meta.capabilities.status" class="unity-edit-field">
				<label class="unity-edit-label">{{ t('unity', 'Status') }}</label>
				<select v-model="form.status" class="unity-edit-select">
					<option value="">{{ t('unity', '(no change)') }}</option>
					<option v-for="s in meta.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
				</select>
			</div>
			<div v-if="meta.capabilities.assignee" class="unity-edit-field">
				<label class="unity-edit-label">{{ t('unity', 'Assignee') }}</label>
				<select v-model="form.assignee" class="unity-edit-select">
					<option value="">{{ t('unity', '(no change)') }}</option>
					<option v-for="a in meta.assignees" :key="a.id" :value="a.id">{{ a.name }}</option>
				</select>
			</div>
			<div v-if="meta.capabilities.labels" class="unity-edit-field">
				<NcSelect v-model="form.labels"
					:options="labelOptions"
					:multiple="true"
					:close-on-select="false"
					:taggable="meta.capabilities.labelsFreeText"
					:input-label="t('unity', 'Labels')"
					:placeholder="meta.capabilities.labelsFreeText ? t('unity', 'Search or type to add labels') : t('unity', 'Search labels')" />
			</div>
		</template>
		<NcLoadingIcon v-else-if="loadingMeta" :size="20" />

		<div class="unity-edit-actions">
			<NcButton type="tertiary" @click="$emit('cancel')">{{ t('unity', 'Cancel') }}</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon><NcLoadingIcon :size="20" /></template>
				{{ t('unity', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import MarkupEditor from './MarkupEditor.vue'

export default {
	name: 'IssueEdit',
	components: { NcTextField, NcButton, NcLoadingIcon, NcSelect, MarkupEditor },
	props: {
		issue: { type: Object, required: true },
	},
	emits: ['saved', 'cancel'],
	data() {
		return {
			form: {
				title: this.issue.title || '',
				description: this.issue.description || '',
				status: '',
				assignee: '',
				labels: [],
			},
			meta: null,
			loadingMeta: false,
			saving: false,
		}
	},
	computed: {
		syntax() {
			return this.issue.bodyFormat === 'textile' ? 'textile' : 'markdown'
		},
		labelOptions() {
			return (this.meta?.labels || []).map((l) => l.name)
		},
	},
	async mounted() {
		await this.loadMeta()
	},
	methods: {
		async loadMeta() {
			this.loadingMeta = true
			try {
				const ref = encodeURIComponent(this.issue.ref)
				const { data } = await axios.get(generateUrl('/apps/unity/issues/{ref}/edit-meta', { ref }))
				if (data && data.capabilities) {
					this.meta = data
					this.preselect()
				}
			} catch (e) {
				// editing title/description still works without meta
			} finally {
				this.loadingMeta = false
			}
		},
		preselect() {
			// Preselect current values by matching names → provider ids where possible.
			const byName = (list, name) => (list.find((x) => x.name === name) || {}).id || ''
			// GitLab/GitHub carry native status ids (opened/closed/open); match directly first.
			const statusById = (this.meta.statuses.find((s) => s.id === this.issue.status) || {}).id
			this.form.status = statusById || byName(this.meta.statuses, this.issue.status) || ''
			this.form.assignee = byName(this.meta.assignees, this.issue.assignee)
			if (this.meta.capabilities.labelsFreeText) {
				this.form.labels = [...(this.issue.labels || [])]
			} else {
				const available = new Set(this.meta.labels.map((l) => l.name))
				this.form.labels = (this.issue.labels || []).filter((l) => available.has(l))
			}
		},
		async save() {
			this.saving = true
			try {
				const payload = {
					title: this.form.title,
					description: this.form.description,
				}
				if (this.meta && this.meta.capabilities.status && this.form.status !== '') {
					payload.status = this.form.status
				}
				if (this.meta && this.meta.capabilities.assignee && this.form.assignee !== '') {
					payload.assignee = this.form.assignee
				}
				if (this.meta && this.meta.capabilities.labels) {
					payload.labels = this.form.labels
				}
				const ref = encodeURIComponent(this.issue.ref)
				const { data } = await axios.put(generateUrl('/apps/unity/issues/{ref}', { ref }), payload)
				if (data && !data.error) {
					this.$emit('saved', data)
				} else {
					showError(data.error || this.t('unity', 'Could not save issue'))
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not save issue'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.unity-edit {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.unity-edit-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-edit-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.unity-edit-select {
	min-height: 36px;
	border-radius: var(--border-radius-element, 8px);
}
.unity-edit-multiselect {
	min-height: 90px;
	border-radius: var(--border-radius-element, 8px);
}
.unity-edit-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
