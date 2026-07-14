<template>
	<div v-if="supported" class="unity-relations">
		<div class="unity-relations-header">
			<span class="unity-relations-title">{{ t('unity', 'Related issues') }}</span>
			<NcButton v-if="!adding && types.length" type="secondary" @click="startAdd">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('unity', 'Add relation') }}
			</NcButton>
		</div>

		<div v-if="adding" class="unity-relations-form">
			<NcSelect v-model="selectedType"
				class="unity-relations-type"
				:options="types"
				label="name"
				:clearable="false"
				:placeholder="t('unity', 'Relation')"
				:aria-label-combobox="t('unity', 'Relation type')" />
			<NcSelect v-model="selectedTarget"
				class="unity-relations-target"
				:options="targetOptions"
				label="label"
				:filterable="false"
				:clearable="true"
				:loading="searching"
				:placeholder="t('unity', 'Search issues')"
				:aria-label-combobox="t('unity', 'Target issue')"
				@search="onTargetSearch" />
			<div class="unity-relations-form-actions">
				<NcButton type="primary" :disabled="!canSubmit || submitting" @click="submit">
					<template #icon>
						<NcLoadingIcon v-if="submitting" :size="18" />
					</template>
					{{ t('unity', 'Add') }}
				</NcButton>
				<NcButton type="tertiary" @click="cancelAdd">{{ t('unity', 'Cancel') }}</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="20" />
		<div v-else-if="relations.length" class="unity-relations-groups">
			<div v-for="group in grouped" :key="group.label" class="unity-relations-group">
				<span class="unity-relations-group-label">{{ group.label }}</span>
				<ul class="unity-relations-list">
					<li v-for="r in group.items" :key="r.id" class="unity-relation">
						<a href="#" class="unity-relation-main" @click.prevent="$emit('open', r.targetRef)">
							<span class="unity-relation-id">{{ r.targetDisplayId }}</span>
							<span class="unity-relation-title">{{ r.targetTitle }}</span>
						</a>
						<span v-if="r.targetStatus" class="unity-chip unity-relation-status">{{ r.targetStatus }}</span>
						<a :href="r.targetUrl"
							class="unity-relation-ext"
							target="_blank"
							rel="noopener noreferrer"
							:aria-label="t('unity', 'Open in tracker')"
							:title="t('unity', 'Open in tracker')">
							<OpenInNew :size="16" />
						</a>
						<NcButton v-if="r.deletable"
							type="tertiary"
							:aria-label="t('unity', 'Remove relation')"
							:title="t('unity', 'Remove relation')"
							:disabled="deletingId === r.id"
							@click="remove(r)">
							<template #icon>
								<NcLoadingIcon v-if="deletingId === r.id" :size="18" />
								<Delete v-else :size="18" />
							</template>
						</NcButton>
					</li>
				</ul>
			</div>
		</div>
		<p v-else class="unity-relations-empty">{{ t('unity', 'No related issues.') }}</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'IssueRelations',
	components: { NcButton, NcLoadingIcon, NcSelect, Plus, Delete, OpenInNew },
	props: {
		issueRef: { type: String, required: true },
		connectionId: { type: String, default: '' },
		reloadKey: { type: Number, default: 0 },
	},
	emits: ['changed', 'open'],
	data() {
		return {
			supported: true,
			relations: [],
			types: [],
			loading: false,
			adding: false,
			submitting: false,
			deletingId: null,
			selectedType: null,
			selectedTarget: null,
			targetOptions: [],
			// The initial, unfiltered issue list for this connection, restored when the
			// search box is cleared (mirrors the project selector).
			initialTargets: [],
			searching: false,
			searchTimer: null,
		}
	},
	computed: {
		// Group relations by their human label, preserving first-seen order.
		grouped() {
			const order = []
			const byLabel = {}
			for (const r of this.relations) {
				if (!byLabel[r.typeLabel]) {
					byLabel[r.typeLabel] = []
					order.push(r.typeLabel)
				}
				byLabel[r.typeLabel].push(r)
			}
			return order.map((label) => ({ label, items: byLabel[label] }))
		},
		canSubmit() {
			return !!this.selectedType && !!this.selectedTarget
		},
	},
	watch: {
		issueRef() {
			this.cancelAdd()
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
		async fetch() {
			const first = this.relations.length === 0
			if (first) {
				this.loading = true
			}
			try {
				const ref = encodeURIComponent(this.issueRef)
				const { data } = await axios.get(generateUrl('/apps/unity/issues/{ref}/relations', { ref }))
				this.supported = data?.supported !== false
				this.relations = Array.isArray(data?.relations) ? data.relations : []
				this.types = Array.isArray(data?.types) ? data.types : []
			} catch (e) {
				if (first) {
					this.relations = []
				}
			} finally {
				this.loading = false
			}
		},
		startAdd() {
			this.adding = true
			this.selectedType = this.types.length ? this.types[0] : null
			this.selectedTarget = null
			this.targetOptions = this.initialTargets
			// Preload the connection's issues so the dropdown has options before typing.
			this.searchTargets('', true)
		},
		cancelAdd() {
			this.adding = false
			this.submitting = false
			this.selectedType = null
			this.selectedTarget = null
			this.targetOptions = []
			this.searching = false
			clearTimeout(this.searchTimer)
		},
		// Fired by NcSelect as the user types. Debounce, then ask the API for matching
		// issues (server-side search), mirroring the project selector. An empty query
		// restores the initial list without a round-trip.
		onTargetSearch(search) {
			clearTimeout(this.searchTimer)
			const query = (search || '').trim()
			if (query === '') {
				this.targetOptions = this.initialTargets
				this.searching = false
				return
			}
			this.searching = true
			this.searchTimer = setTimeout(() => this.searchTargets(query), 300)
		},
		async searchTargets(query, initial = false) {
			this.searching = true
			try {
				const { data } = await axios.get(generateUrl('/apps/unity/issues'), {
					params: { term: query, connections: this.connectionId, showClosed: 'true', limit: 20 },
				})
				const issues = Array.isArray(data?.issues) ? data.issues : []
				// Never offer the issue itself as its own relation target; give each option
				// a display label ("<id> <title>") for NcSelect, like the project selector.
				const options = issues
					.filter((it) => it.ref !== this.issueRef)
					.map((it) => ({ ...it, label: `${it.displayId} ${it.title}`.trim() }))
				this.targetOptions = options
				if (initial) {
					this.initialTargets = options
				}
			} catch (e) {
				// Keep the current options on a transient search error.
			} finally {
				this.searching = false
			}
		},
		async submit() {
			if (!this.canSubmit || this.submitting) {
				return
			}
			this.submitting = true
			try {
				const ref = encodeURIComponent(this.issueRef)
				await axios.post(generateUrl('/apps/unity/issues/{ref}/relations', { ref }), {
					type: this.selectedType.id,
					target: this.selectedTarget.ref,
				})
				showSuccess(this.t('unity', 'Relation added'))
				this.cancelAdd()
				await this.fetch()
				this.$emit('changed')
			} catch (err) {
				showError(err?.response?.data?.error || this.t('unity', 'Could not add the relation'))
			} finally {
				this.submitting = false
			}
		},
		async remove(r) {
			const confirmed = await showConfirmation({
				name: this.t('unity', 'Remove relation'),
				text: this.t('unity', 'Remove the relation to {id}?', { id: r.targetDisplayId }),
				labelConfirm: this.t('unity', 'Remove'),
				severity: 'error',
			})
			if (!confirmed) {
				return
			}
			this.deletingId = r.id
			try {
				const ref = encodeURIComponent(this.issueRef)
				const relationId = encodeURIComponent(r.id)
				await axios.delete(generateUrl('/apps/unity/issues/{ref}/relations/{relationId}', { ref, relationId }))
				showSuccess(this.t('unity', 'Relation removed'))
				await this.fetch()
				this.$emit('changed')
			} catch (err) {
				showError(err?.response?.data?.error || this.t('unity', 'Could not remove the relation'))
			} finally {
				this.deletingId = null
			}
		},
	},
}
</script>

<style scoped>
.unity-relations {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}
.unity-relations-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}
.unity-relations-title {
	font-weight: bold;
}
.unity-relations-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
}
.unity-relations-type {
	min-width: 160px;
}
.unity-relations-target {
	min-width: 240px;
	flex-grow: 1;
}
.unity-relations-form-actions {
	display: flex;
	gap: 6px;
}
.unity-relations-groups {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.unity-relations-group-label {
	font-size: 0.85em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}
.unity-relations-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 2px;
}
.unity-relation {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}
.unity-relation-main {
	display: flex;
	align-items: baseline;
	gap: 6px;
	min-width: 0;
	flex-grow: 1;
	color: var(--color-main-text);
}
.unity-relation-main:hover .unity-relation-title {
	text-decoration: underline;
}
.unity-relation-id {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}
.unity-relation-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.unity-relation-status {
	flex-shrink: 0;
}
.unity-relation-ext {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	padding: 4px;
	color: var(--color-text-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
}
.unity-relation-ext:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
.unity-relations-empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 8px;
}
</style>
