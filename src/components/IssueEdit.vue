<template>
	<div class="unity-edit">
		<div class="unity-form-grid">
			<div class="unity-edit-field unity-edit-field--full">
				<label class="unity-edit-label">{{ t('unity', 'Title') }}</label>
				<NcTextField v-model="form.title" label-outside :aria-label="t('unity', 'Title')" />
			</div>

			<div class="unity-edit-field unity-edit-field--full">
				<label class="unity-edit-label">{{ t('unity', 'Description') }}</label>
				<MarkupEditor v-model="form.description" :format="issue.bodyFormat" :issue-ref="issue.ref" :tracker="issue.tracker" :mentions="issue.mentions || []" :rows="8" />
			</div>

			<template v-if="meta">
				<div v-if="meta.capabilities.type && types.length" class="unity-edit-field">
					<label class="unity-edit-label">{{ t('unity', 'Type') }}</label>
					<select v-model="typeId" class="unity-edit-select" :disabled="fieldsReloading">
						<option v-for="ty in types" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
					</select>
				</div>
				<div v-if="meta.capabilities.status" class="unity-edit-field">
					<label class="unity-edit-label">{{ t('unity', 'Status') }}</label>
					<select v-model="form.status" class="unity-edit-select">
						<option value="">{{ t('unity', '(no change)') }}</option>
						<option v-for="s in meta.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
					</select>
				</div>
				<div v-if="meta.capabilities.assignee" class="unity-edit-field">
					<label class="unity-edit-label">{{ t('unity', 'Assignee') }}</label>
					<AssigneePicker mode="edit"
						:issue-ref="issue.ref"
						:current="meta.assignee"
						@change="assigneeChoice = $event" />
				</div>
				<div v-if="meta.capabilities.labels" class="unity-edit-field">
					<label class="unity-edit-label">{{ t('unity', 'Labels') }}</label>
					<NcSelect v-model="form.labels"
						:options="labelOptions"
						:multiple="true"
						:close-on-select="false"
						:taggable="meta.capabilities.labelsFreeText"
						:aria-label-combobox="t('unity', 'Labels')"
						:placeholder="meta.capabilities.labelsFreeText ? t('unity', 'Search or type to add labels') : t('unity', 'Search labels')" />
				</div>
				<NcLoadingIcon v-if="fieldsReloading" class="unity-form-grid__full" :size="20" />
				<DynamicField v-for="f in fields"
					:key="f.id"
					:descriptor="f"
					:model-value="fieldValues[f.id]"
					@update:model-value="setFieldValue(f.id, $event)" />
			</template>
			<NcLoadingIcon v-else-if="loadingMeta" class="unity-form-grid__full" :size="20" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import MarkupEditor from './MarkupEditor.vue'
import DynamicField from './DynamicField.vue'
import AssigneePicker from './AssigneePicker.vue'

export default {
	name: 'IssueEdit',
	components: { NcTextField, NcLoadingIcon, NcSelect, MarkupEditor, DynamicField, AssigneePicker },
	props: {
		issue: { type: Object, required: true },
	},
	emits: ['saved', 'saving'],
	data() {
		return {
			form: {
				title: this.issue.title || '',
				description: this.issue.description || '',
				status: '',
				labels: [],
			},
			// Assignee is handled by AssigneePicker: the current choice ({id,name}) and
			// the issue's original assignee id, so save() only sends a real change.
			assigneeChoice: null,
			assigneeOriginalId: '',
			meta: null,
			loadingMeta: false,
			// Provider-native dynamic fields, their live values, and the originals for diffing.
			fields: [],
			fieldValues: {},
			fieldsOriginal: {},
			// Issue type: the choices, the selected id, the original, and a reload flag.
			types: [],
			typeId: '',
			originalTypeId: '',
			fieldsReloading: false,
		}
	},
	computed: {
		labelOptions() {
			return (this.meta?.labels || []).map((l) => l.name)
		},
	},
	watch: {
		// Fields are type-specific; reload them when the user switches the issue type.
		typeId(newVal, oldVal) {
			if (oldVal !== '' && newVal !== oldVal) {
				this.reloadFields()
			}
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
		// Seed fields + their values (and the diff baseline) from a descriptor list.
		seedFields(fieldList) {
			this.fields = Array.isArray(fieldList) ? fieldList : []
			const values = {}
			for (const f of this.fields) {
				if (f.value !== undefined && f.value !== null) {
					values[f.id] = f.value
				} else if (f.type === 'multiselect') {
					values[f.id] = []
				} else if (f.type === 'bool') {
					values[f.id] = false
				} else {
					values[f.id] = ''
				}
			}
			this.fieldValues = values
			this.fieldsOriginal = JSON.parse(JSON.stringify(values))
		},
		preselect() {
			this.seedFields(this.meta.fields)
			// Issue type (where the provider allows changing it).
			this.types = Array.isArray(this.meta.types) ? this.meta.types : []
			this.typeId = this.meta.typeId || ''
			this.originalTypeId = this.typeId
			// Preselect current values by matching names → provider ids where possible.
			const byName = (list, name) => (list.find((x) => x.name === name) || {}).id || ''
			// GitLab/GitHub carry native status ids (opened/closed/open); match directly first.
			const statusById = (this.meta.statuses.find((s) => s.id === this.issue.status) || {}).id
			this.form.status = statusById || byName(this.meta.statuses, this.issue.status) || ''
			this.assigneeOriginalId = (this.meta.assignee && this.meta.assignee.id) || ''
			if (this.meta.capabilities.labelsFreeText) {
				this.form.labels = [...(this.issue.labels || [])]
			} else {
				const available = new Set(this.meta.labels.map((l) => l.name))
				this.form.labels = (this.issue.labels || []).filter((l) => available.has(l))
			}
		},
		async save() {
			// The trigger button lives in the parent header; report saving state up so it
			// can show the spinner and stay disabled during submit.
			this.$emit('saving', true)
			try {
				const payload = {
					title: this.form.title,
					description: this.form.description,
				}
				if (this.meta && this.meta.capabilities.status && this.form.status !== '') {
					payload.status = this.form.status
				}
				// Send the assignee only when it actually changed. This covers set,
				// change, and clear (Unassigned → id ''); an untouched picker matches
				// the original and is skipped.
				if (this.meta && this.meta.capabilities.assignee && this.assigneeChoice
					&& this.assigneeChoice.id !== this.assigneeOriginalId) {
					payload.assignee = this.assigneeChoice.id
				}
				if (this.meta && this.meta.capabilities.labels) {
					payload.labels = this.form.labels
				}
				// Send the type only when the user changed it.
				if (this.meta && this.meta.capabilities.type && this.typeId && this.typeId !== this.originalTypeId) {
					payload.type = this.typeId
				}
				// Only send dynamic fields whose value actually changed.
				const changedFields = {}
				for (const f of this.fields) {
					const current = this.fieldValues[f.id]
					if (JSON.stringify(current) !== JSON.stringify(this.fieldsOriginal[f.id])) {
						changedFields[f.id] = current
					}
				}
				if (Object.keys(changedFields).length > 0) {
					payload.fields = changedFields
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
				this.$emit('saving', false)
			}
		},
		setFieldValue(id, value) {
			this.fieldValues = { ...this.fieldValues, [id]: value }
		},
		// Re-fetch the field descriptors for the newly selected type.
		async reloadFields() {
			this.fieldsReloading = true
			try {
				const ref = encodeURIComponent(this.issue.ref)
				const { data } = await axios.get(generateUrl('/apps/unity/issues/{ref}/edit-meta', { ref }), {
					params: { type: this.typeId },
				})
				if (data && Array.isArray(data.fields)) {
					this.seedFields(data.fields)
				}
			} catch (e) {
				// Keep the current fields on a transient error.
			} finally {
				this.fieldsReloading = false
			}
		},
	},
}
</script>

<style scoped>
.unity-edit {
	/* Query container so the grid responds to the form's own width — this form lives in
	   a resizable detail pane, not the viewport, so a viewport @media would be wrong. */
	container-type: inline-size;
}
.unity-form-grid {
	display: grid;
	grid-template-columns: 1fr; /* narrow: single column */
	gap: 8px 12px;
	align-items: start;
}
@container (min-width: 520px) {
	.unity-form-grid {
		grid-template-columns: 1fr 1fr; /* capped at two per row */
	}
}
.unity-edit-field--full,
.unity-form-grid__full {
	grid-column: 1 / -1;
}
.unity-edit-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-edit-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0; /* let the cell shrink instead of overflowing the grid track */
}
/* Match the height and border of the Nextcloud input/select components so native
   selects, date inputs and NcTextField/NcSelect all line up. */
.unity-edit-select {
	min-height: var(--default-clickable-area, 44px);
	padding: 0 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	width: 100%;
	box-sizing: border-box;
}
.unity-edit-select:hover:not(:disabled) {
	border-color: var(--color-main-text);
}
.unity-edit-multiselect {
	min-height: 90px;
	border-radius: var(--border-radius-element, 8px);
}
</style>
