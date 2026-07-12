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
				<div class="unity-create-field">
					<label class="unity-create-label">{{ t('unity', 'Connection') }}</label>
					<select v-model="connectionId" class="unity-create-select" @change="onConnectionChange">
						<option value="" disabled>{{ t('unity', 'Choose a connection') }}</option>
						<option v-for="c in creatableConnections" :key="c.id" :value="c.id">
							{{ c.label || c.baseUrl }}
						</option>
					</select>
				</div>

				<NcLoadingIcon v-if="loadingMeta" :size="24" />
				<NcNoteCard v-else-if="metaError" type="error">{{ metaError }}</NcNoteCard>

				<template v-if="meta && !loadingMeta">
					<div class="unity-create-field">
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

					<div v-if="currentTypes.length" class="unity-create-field">
						<label class="unity-create-label">{{ t('unity', 'Type') }}</label>
						<select v-model="typeId" class="unity-create-select">
							<option value="" :disabled="meta.capabilities.typeRequired">
								{{ meta.capabilities.typeRequired ? t('unity', 'Choose a type') : t('unity', '(default)') }}
							</option>
							<option v-for="ty in currentTypes" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
						</select>
					</div>

					<div class="unity-create-field">
						<label class="unity-create-label">{{ t('unity', 'Title') }}</label>
						<NcTextField v-model="title" :label="t('unity', 'Title')" />
					</div>

					<div class="unity-create-field">
						<label class="unity-create-label">{{ t('unity', 'Description') }}</label>
						<MarkupEditor v-model="description"
							:format="bodyFormat"
							:tracker="selectedTracker"
							:rows="6" />
					</div>
				</template>
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
import { trackerById, createBodyFormat } from '../trackers.js'

export default {
	name: 'IssueCreate',
	components: { NcDialog, NcButton, NcTextField, NcLoadingIcon, NcNoteCard, NcSelect, MarkupEditor },
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
			typeId: '',
			title: '',
			description: '',
			saving: false,
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
			return this.selectedProject ? (this.selectedProject.types || []) : []
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
			return true
		},
	},
	watch: {
		// Types are project-specific; clear a stale selection when the project changes.
		projectSelection() {
			this.typeId = ''
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
			this.typeId = ''
			this.loadMeta()
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
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 8px 16px 16px;
}
.unity-create-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.unity-create-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-create-select {
	min-height: 36px;
	border-radius: var(--border-radius-element, 8px);
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
