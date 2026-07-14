<template>
	<NcSelect v-model="selected"
		:options="allOptions"
		label="label"
		:filterable="false"
		:clearable="false"
		:loading="searching"
		:placeholder="t('unity', 'Search users')"
		:aria-label-combobox="t('unity', 'Assignee')"
		@search="onSearch" />
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'

/**
 * A search-as-you-type, single-select assignee control shared by the edit and
 * create forms. Queries the provider's assignable-user search (server-side where
 * supported, client-side filtered otherwise), pre-loads the first page on open
 * where the API allows, and always offers an explicit "Unassigned" option so an
 * assignee can be cleared. Emits `change` with `{ id, name }` (id `''` = Unassigned).
 *
 * Uses `v-model` (not `:model-value`) so NcSelect's `@search` event fires while
 * typing — the pattern the project and relation-target pickers use.
 */
export default {
	name: 'AssigneePicker',
	components: { NcSelect },
	props: {
		// 'edit' searches assignees for an existing issue; 'create' for a project.
		mode: { type: String, default: 'edit' },
		issueRef: { type: String, default: '' },
		connectionId: { type: String, default: '' },
		project: { type: String, default: '' },
		// The issue's current assignee (edit preselect), { id, name } or null.
		current: { type: Object, default: null },
	},
	emits: ['change'],
	data() {
		return {
			selected: null,
			options: [],
			searching: false,
			searchTimer: null,
		}
	},
	computed: {
		unassignedOption() {
			return { id: '', name: '', label: this.t('unity', 'Unassigned') }
		},
		allOptions() {
			// "Unassigned" first, then results (minus the currently selected one, so
			// it isn't listed twice).
			const selId = this.selected ? this.selected.id : null
			const results = this.options.filter((o) => o.id !== '' && o.id !== selId)
			return [this.unassignedOption, ...results]
		},
	},
	watch: {
		issueRef() {
			this.reset()
		},
		project() {
			this.reset()
		},
		current() {
			this.seedCurrent()
		},
		selected(value) {
			this.$emit('change', { id: value ? value.id : '', name: value ? (value.name || '') : '' })
		},
	},
	mounted() {
		this.reset()
	},
	methods: {
		reset() {
			clearTimeout(this.searchTimer)
			this.searching = false
			this.options = []
			this.seedCurrent()
			// Pre-load the first page. Returns members on GitLab/Redmine/GitHub/Asana;
			// Jira Cloud returns nothing for an empty query, so it stays type-to-search.
			this.search('')
		},
		// Preselect the issue's current assignee (edit), else Unassigned.
		seedCurrent() {
			this.selected = (this.current && this.current.id)
				? { id: this.current.id, name: this.current.name, label: this.current.name || this.current.id }
				: this.unassignedOption
		},
		onSearch(term) {
			clearTimeout(this.searchTimer)
			this.searching = true
			this.searchTimer = setTimeout(() => this.search((term || '').trim()), 300)
		},
		async search(query) {
			this.searching = true
			try {
				const { data } = await axios.get(this.endpoint(), { params: this.params(query) })
				const users = Array.isArray(data) ? data : []
				this.options = users.map((u) => ({ id: u.id, name: u.name, label: u.name || u.id }))
			} catch (e) {
				// Keep the current options on a transient search error.
			} finally {
				this.searching = false
			}
		},
		endpoint() {
			if (this.mode === 'create') {
				return generateUrl('/apps/unity/create-assignees')
			}
			const ref = encodeURIComponent(this.issueRef)
			return generateUrl('/apps/unity/issues/{ref}/assignees', { ref })
		},
		params(query) {
			if (this.mode === 'create') {
				return { connection: this.connectionId, project: this.project, query }
			}
			return { query }
		},
	},
}
</script>
