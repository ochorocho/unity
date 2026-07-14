<template>
	<div id="unity_prefs_content" class="section">
		<h2>{{ t('unity', 'Unity — issue tracker connections') }}</h2>
		<p class="settings-hint">
			{{ t('unity', 'Connect your Jira, GitLab, Redmine, GitHub and Asana accounts. Tokens are stored encrypted and never shown again.') }}
		</p>

		<div class="unity-connections">
			<div v-for="c in connections" :key="c.id" class="unity-connection-row">
				<span class="unity-dot" :style="{ backgroundColor: color(c.tracker) }" />
				<div class="unity-connection-info">
					<strong>{{ c.label || c.baseUrl }}</strong>
					<span class="unity-connection-sub">{{ trackerLabel(c.tracker) }} · {{ c.baseUrl }}</span>
				</div>
				<NcButton type="tertiary" @click="edit(c)">{{ t('unity', 'Edit') }}</NcButton>
				<NcButton type="tertiary" @click="remove(c)">{{ t('unity', 'Delete') }}</NcButton>
			</div>
			<p v-if="connections.length === 0" class="settings-hint">
				{{ t('unity', 'No connections configured yet.') }}
			</p>
		</div>

		<NcButton type="secondary" @click="addNew">{{ t('unity', 'Add connection') }}</NcButton>

		<ConnectionForm v-if="editing"
			:model="editing"
			@saved="onSaved"
			@cancel="editing = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import ConnectionForm from './ConnectionForm.vue'
import { trackerById } from '../trackers.js'

export default {
	name: 'PersonalSettings',
	components: { NcButton, ConnectionForm },
	data() {
		let initial = []
		try {
			initial = loadState('unity', 'unity-connections')
		} catch (e) {
			initial = []
		}
		return {
			connections: Array.isArray(initial) ? initial : [],
			editing: null,
		}
	},
	async mounted() {
		await this.refresh()
	},
	methods: {
		color(tracker) {
			return trackerById(tracker).color
		},
		trackerLabel(tracker) {
			return trackerById(tracker).label
		},
		async refresh() {
			try {
				const { data } = await axios.get(generateUrl('/apps/unity/connections'))
				this.connections = data
			} catch (e) {
				showError(this.t('unity', 'Could not load connections'))
			}
		},
		addNew() {
			this.editing = { id: '', tracker: 'jira', label: '', baseUrl: '', username: '', token: '', tempoToken: '' }
		},
		edit(connection) {
			this.editing = { ...connection, token: '', tempoToken: '' }
		},
		async remove(connection) {
			try {
				await axios.delete(generateUrl('/apps/unity/connections/{id}', { id: connection.id }))
				showSuccess(this.t('unity', 'Connection removed'))
				await this.refresh()
			} catch (e) {
				showError(this.t('unity', 'Could not remove connection'))
			}
		},
		async onSaved() {
			this.editing = null
			await this.refresh()
		},
	},
}
</script>

<style scoped>
.unity-connections {
	margin: 16px 0;
	max-width: 640px;
}
.unity-connection-row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}
.unity-connection-info {
	display: flex;
	flex-direction: column;
	flex: 1;
}
.unity-connection-sub {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.unity-dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
}
</style>
