<template>
	<NcDialog :name="t('unity', 'New issue')"
		size="normal"
		@closing="$emit('close')">
		<div class="unity-create">
			<template v-if="creatableConnections.length === 0">
				<NcNoteCard type="warning">
					{{ t('unity', 'None of your connections support creating issues.') }}
				</NcNoteCard>
			</template>
			<template v-else>
				<div class="unity-form-grid">
					<div class="unity-create-field">
						<label class="unity-create-label">{{ t('unity', 'Connection') }}</label>
						<select v-model="connectionId" class="unity-create-select" @change="onConnectionChange">
							<option value="" disabled>{{ t('unity', 'Choose a connection') }}</option>
							<option v-for="c in creatableConnections" :key="c.id" :value="c.id">
								{{ c.label || c.baseUrl }}
							</option>
						</select>
					</div>

					<NcLoadingIcon v-if="loadingMeta" class="unity-form-grid__full" :size="24" />
					<NcNoteCard v-else-if="metaError" class="unity-form-grid__full" type="error">{{ metaError }}</NcNoteCard>

					<template v-if="meta && !loadingMeta">
						<div class="unity-create-field unity-create-field--full">
							<label class="unity-create-label">{{ projectLabel }}</label>
							<NcSelect v-model="projectSelection"
								:options="projectOptions"
								label="name"
								:clearable="false"
								:filterable="false"
								:loading="projectsLoading"
								:placeholder="t('unity', 'Search projects')"
								:aria-label-combobox="projectLabel"
								@search="onProjectSearch" />
							<p v-if="meta.projects.length === 0" class="unity-create-hint">
								{{ t('unity', 'No projects available for this connection.') }}
							</p>
						</div>

						<div class="unity-create-field unity-create-field--full">
							<label class="unity-create-label">{{ t('unity', 'Title') }}</label>
							<NcTextField v-model="title" label-outside :aria-label="t('unity', 'Title')" />
						</div>

						<div class="unity-create-field unity-create-field--full">
							<label class="unity-create-label">{{ t('unity', 'Description') }}</label>
							<MarkupEditor v-model="description"
								:format="bodyFormat"
								:tracker="selectedTracker"
								:connection="connectionId"
								:project="projectId"
								:rows="6" />
						</div>

						<div v-if="currentTypes.length" class="unity-create-field">
							<label class="unity-create-label">{{ t('unity', 'Type') }}</label>
							<select v-model="typeId" class="unity-create-select" :disabled="fieldsLoading">
								<option value="" :disabled="meta.capabilities.typeRequired">
									{{ meta.capabilities.typeRequired ? t('unity', 'Choose a type') : t('unity', '(default)') }}
								</option>
								<option v-for="ty in currentTypes" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
							</select>
						</div>

						<div v-if="projectId && assigneeSupported" class="unity-create-field">
							<label class="unity-create-label">{{ t('unity', 'Assignee') }}</label>
							<AssigneePicker mode="create"
								:connection-id="connectionId"
								:project="projectId"
								@change="assigneeChoice = $event" />
						</div>

						<div v-if="projectId && labelsSupported" class="unity-create-field">
							<label class="unity-create-label">{{ t('unity', 'Labels') }}</label>
							<NcSelect v-model="labels"
								:options="labelOptions"
								:multiple="true"
								:close-on-select="false"
								:taggable="labelsFreeText"
								:aria-label-combobox="t('unity', 'Labels')"
								:placeholder="labelsFreeText ? t('unity', 'Search or type to add labels') : t('unity', 'Search labels')" />
						</div>

						<NcLoadingIcon v-if="fieldsLoading" class="unity-form-grid__full" :size="20" />
						<DynamicField v-for="f in fields"
							:key="f.id"
							:descriptor="f"
							:model-value="fieldValues[f.id]"
							@update:model-value="setFieldValue(f.id, $event)" />
					</template>
				</div>
			</template>

			<div class="unity-create-actions">
				<NcButton type="tertiary" @click="$emit('close')">{{ t('unity', 'Cancel') }}</NcButton>
				<NcButton type="primary" :disabled="!canCreate || saving" @click="submit">
					<template v-if="saving" #icon><NcLoadingIcon :size="20" /></template>
					{{ t('unity', 'Create') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import MarkupEditor from './MarkupEditor.vue'
import DynamicField from './DynamicField.vue'
import AssigneePicker from './AssigneePicker.vue'
import { trackerById, createBodyFormat } from '../trackers.js'

// A dynamic-field value counts as empty when unset, blank, or an empty multi-select.
function isEmptyValue(value) {
	if (Array.isArray(value)) {
		return value.length === 0
	}
	return value === undefined || value === null || value === ''
}

export default {
	name: 'IssueCreate',
	components: { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcNoteCard, NcSelect, MarkupEditor, DynamicField, AssigneePicker },
	props: {
		connections: { type: Array, default: () => [] },
		// The connection to preselect ('' = "All connections" → user must pick).
		preselected: { type: String, default: '' },
	},
	emits: ['close', 'created'],
	data() {
		return {
			connectionId: '',
			meta: null,
			loadingMeta: false,
			metaError: '',
			// The selected project object (NcSelect binds the whole option, not just the id).
			projectSelection: null,
			// Options shown in the project dropdown; refreshed from the API as the user types.
			projectOptions: [],
			projectsLoading: false,
			projectSearchTimer: null,
			// Issue types for the selected project (Jira fetches these per project).
			projectTypes: [],
			typeId: '',
			title: '',
			description: '',
			saving: false,
			// Provider-native dynamic fields for the chosen project/type, and their values.
			fields: [],
			fieldValues: {},
			fieldsLoading: false,
			fieldsToken: 0,
			// Assignee (from the project-context create-meta): whether it's offered and
			// the chosen user ({id,name} from AssigneePicker).
			assigneeSupported: false,
			assigneeChoice: null,
			// Labels (from the project-context create-meta): whether they're offered, whether
			// they're free-text, the option list ({id,name}), and the chosen label names.
			labelsSupported: false,
			labelsFreeText: false,
			labelMeta: [],
			labels: [],
		}
	},
	computed: {
		creatableConnections() {
			return this.connections.filter((c) => trackerById(c.tracker).create)
		},
		selectedConnection() {
			return this.connections.find((c) => c.id === this.connectionId) || null
		},
		selectedTracker() {
			return this.selectedConnection ? this.selectedConnection.tracker : ''
		},
		bodyFormat() {
			return createBodyFormat(this.selectedTracker)
		},
		selectedProject() {
			return this.projectSelection
		},
		// The plain project id string used by canCreate and the create request.
		projectId() {
			return this.projectSelection ? this.projectSelection.id : ''
		},
		currentTypes() {
			// Prefer types fetched for the selected project; fall back to those embedded
			// in the project list (Redmine's global trackers, etc.).
			if (this.projectTypes.length) {
				return this.projectTypes
			}
			return this.selectedProject ? (this.selectedProject.types || []) : []
		},
		// Free-text tags for the Labels picker (mirrors edit's labelOptions).
		labelOptions() {
			return this.labelMeta.map((l) => l.name)
		},
		// "Project" for most trackers, "Repository" for GitHub.
		projectLabel() {
			return this.selectedTracker === 'github' ? this.t('unity', 'Repository') : this.t('unity', 'Project')
		},
		canCreate() {
			if (!this.connectionId || !this.projectId || this.title.trim() === '') {
				return false
			}
			// Require a type only when the provider needs one and offers a choice.
			if (this.meta && this.meta.capabilities.typeRequired && this.currentTypes.length && this.typeId === '') {
				return false
			}
			// Every required dynamic field must have a value.
			for (const f of this.fields) {
				if (f.required && isEmptyValue(this.fieldValues[f.id])) {
					return false
				}
			}
			return true
		},
		// Identifies the project/type combination the dynamic fields belong to.
		fieldContext() {
			return `${this.connectionId}|${this.projectId}|${this.typeId}`
		},
	},
	watch: {
		// Types are project-specific; clear a stale selection when the project changes.
		projectSelection() {
			this.typeId = ''
			this.projectTypes = []
			this.labels = []
		},
		// Re-fetch the dynamic field descriptors whenever the project/type changes.
		fieldContext() {
			this.loadFields()
		},
	},
	mounted() {
		const preselectOk = this.preselected && this.creatableConnections.some((c) => c.id === this.preselected)
		if (preselectOk) {
			this.connectionId = this.preselected
		} else if (this.creatableConnections.length === 1) {
			this.connectionId = this.creatableConnections[0].id
		}
		if (this.connectionId) {
			this.loadMeta()
		}
	},
	methods: {
		onConnectionChange() {
			this.projectSelection = null
			this.projectOptions = []
			this.projectTypes = []
			this.typeId = ''
			this.fields = []
			this.fieldValues = {}
			this.labels = []
			this.labelsSupported = false
			this.labelsFreeText = false
			this.labelMeta = []
			this.loadMeta()
		},
		// Fetch the provider-native field descriptors for the current project/type.
		// A monotonic token guards against a stale in-flight response overwriting a newer one.
		async loadFields() {
			if (!this.connectionId || !this.projectId) {
				this.fields = []
				this.fieldValues = {}
				return
			}
			const token = ++this.fieldsToken
			this.fieldsLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/unity/create-meta'), {
					params: { connection: this.connectionId, project: this.projectId, type: this.typeId },
				})
				if (token !== this.fieldsToken) {
					return
				}
				// The project-context response carries the project's issue types; keep them
				// across the follow-up per-type call (which returns types: []).
				if (data && Array.isArray(data.types) && data.types.length) {
					this.projectTypes = data.types
				}
				const caps = (data && data.capabilities) || {}
				this.assigneeSupported = !!caps.assignee
				this.labelsSupported = !!caps.labels
				this.labelsFreeText = !!caps.labelsFreeText
				this.labelMeta = data && Array.isArray(data.labels) ? data.labels : []
				this.fields = data && Array.isArray(data.fields) ? data.fields : []
				this.seedFieldValues()
			} catch (e) {
				if (token === this.fieldsToken) {
					this.fields = []
					this.fieldValues = {}
				}
			} finally {
				if (token === this.fieldsToken) {
					this.fieldsLoading = false
				}
			}
		},
		seedFieldValues() {
			const values = {}
			for (const f of this.fields) {
				if (f.default !== undefined) {
					values[f.id] = f.default
				} else if (f.type === 'multiselect') {
					values[f.id] = []
				} else if (f.type === 'bool') {
					values[f.id] = false
				} else {
					values[f.id] = ''
				}
			}
			this.fieldValues = values
		},
		setFieldValue(id, value) {
			this.fieldValues = { ...this.fieldValues, [id]: value }
		},
		async loadMeta() {
			if (!this.connectionId) {
				return
			}
			this.loadingMeta = true
			this.metaError = ''
			this.meta = null
			try {
				const { data } = await axios.get(generateUrl('/apps/unity/create-meta'), {
					params: { connection: this.connectionId },
				})
				if (data && Array.isArray(data.projects)) {
					this.meta = data
					this.projectOptions = data.projects
				} else {
					this.metaError = (data && data.error) || this.t('unity', 'Could not load projects')
				}
			} catch (e) {
				this.metaError = e?.response?.data?.error || this.t('unity', 'Could not load projects')
			} finally {
				this.loadingMeta = false
			}
		},
		// Fired by NcSelect as the user types. Debounce, then ask the API for matching
		// projects (server-side search) rather than filtering the initial list client-side.
		onProjectSearch(search) {
			clearTimeout(this.projectSearchTimer)
			const query = (search || '').trim()
			if (query === '') {
				// Empty query: restore the initial (unfiltered) list without a round-trip.
				this.projectOptions = this.meta ? this.meta.projects : []
				this.projectsLoading = false
				return
			}
			this.projectsLoading = true
			this.projectSearchTimer = setTimeout(() => this.searchProjects(query), 300)
		},
		async searchProjects(query) {
			if (!this.connectionId) {
				return
			}
			this.projectsLoading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/unity/create-meta'), {
					params: { connection: this.connectionId, query },
				})
				if (data && Array.isArray(data.projects)) {
					this.projectOptions = data.projects
				}
			} catch (e) {
				// Keep the current options on a transient search error.
			} finally {
				this.projectsLoading = false
			}
		},
		async submit() {
			if (!this.canCreate) {
				return
			}
			this.saving = true
			try {
				const { data } = await axios.post(generateUrl('/apps/unity/create'), {
					connection: this.connectionId,
					project: this.projectId,
					type: this.typeId,
					title: this.title,
					description: this.description,
					assignee: (this.assigneeSupported && this.assigneeChoice) ? this.assigneeChoice.id : '',
					labels: this.labelsSupported ? this.labels : [],
					fields: this.fieldValues,
				})
				if (data && data.ref) {
					showSuccess(this.t('unity', 'Issue created'))
					this.$emit('created', data)
				} else {
					showError((data && data.error) || this.t('unity', 'Could not create the issue'))
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not create the issue'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.unity-create {
	/* Query container: the grid adapts to the dialog's content width. Stays flex so the
	   top NoteCard, the field grid and the actions row stack vertically. */
	container-type: inline-size;
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 8px 16px 16px;
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
/* NcSelect ships a bottom margin; drop it so dropdowns align with the other controls
   (row spacing comes from the grid gap). */
.unity-create :deep(.v-select) {
	margin: 0;
}
.unity-create-field--full,
.unity-form-grid__full {
	grid-column: 1 / -1;
}
.unity-create-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0; /* let the cell shrink instead of overflowing the grid track */
}
.unity-create-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
/* Match the Nextcloud input/select components (height + border) so native selects,
   date inputs and NcTextField/NcSelect all line up. */
.unity-create-select {
	min-height: var(--default-clickable-area, 44px);
	padding: 0 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	width: 100%;
	box-sizing: border-box;
}
.unity-create-select:hover:not(:disabled) {
	border-color: var(--color-main-text);
}
.unity-create-hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-create-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
