<template>
	<div class="unity-form">
		<h3>{{ isNew ? t('unity', 'Add connection') : t('unity', 'Edit connection') }}</h3>

		<label class="unity-form-label">{{ t('unity', 'Tracker') }}</label>
		<select v-model="form.tracker" class="unity-select" :disabled="!isNew">
			<option v-for="tr in trackers" :key="tr.id" :value="tr.id">{{ tr.label }}</option>
		</select>

		<NcTextField v-model="form.label"
			:label="t('unity', 'Name')" />

		<NcTextField v-model="form.baseUrl"
			:label="baseUrlLabel"
			:placeholder="baseUrlPlaceholder" />

		<NcTextField v-if="needsUsername"
			v-model="form.username"
			:label="t('unity', 'Account email')" />

		<NcPasswordField v-model="form.token"
			:label="tokenLabel"
			:placeholder="isNew ? '' : t('unity', 'Leave blank to keep current token')" />

		<NcPasswordField v-if="form.tracker === 'jira'"
			v-model="form.tempoToken"
			:label="t('unity', 'Tempo API token (optional)')"
			:placeholder="isNew ? '' : t('unity', 'Leave blank to keep current token')" />

		<NcCheckboxRadioSwitch type="switch"
			v-model="form.settings.allowLocalAddress">
			{{ t('unity', 'Allow connecting to an internal/local server address') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard v-if="form.settings.allowLocalAddress" type="warning" class="unity-token-help">
			{{ t('unity', 'This relaxes Nextcloud\'s protection against requests to private/internal addresses for this connection only. Enable it only for a tracker you trust on your own network.') }}
		</NcNoteCard>

		<template v-if="form.tracker === 'redmine'">
			<label class="unity-form-label">{{ t('unity', 'Text format') }}</label>
			<select v-model="form.settings.textFormat" class="unity-select">
				<option value="textile">{{ t('unity', 'Textile (Redmine default)') }}</option>
				<option value="markdown">{{ t('unity', 'Markdown') }}</option>
			</select>
		</template>

		<NcNoteCard v-if="help" type="info" class="unity-token-help">
			<p class="unity-help-title">{{ t('unity', 'Required token permissions') }}</p>
			<p v-if="help.auth" class="unity-help-auth">{{ help.auth }}</p>
			<ul class="unity-help-scopes">
				<li v-for="s in help.scopes" :key="s.name">
					<code>{{ s.name }}</code> — {{ s.purpose }}
				</li>
			</ul>
			<ul class="unity-help-notes">
				<li v-for="(note, i) in help.notes" :key="i">{{ note }}</li>
			</ul>
			<a :href="help.createUrl" target="_blank" rel="noopener noreferrer" class="unity-help-link">
				{{ help.createLabel }} ↗
			</a>
		</NcNoteCard>

		<div class="unity-form-actions">
			<NcButton @click="test">
				<template v-if="testing" #icon><NcLoadingIcon :size="20" /></template>
				{{ t('unity', 'Test') }}
			</NcButton>
			<span v-if="testResult" class="unity-test-result" :class="{ ok: testResult.ok }">
				{{ testResult.ok ? (t('unity', 'OK') + (testResult.user ? ' – ' + testResult.user : '')) : testResult.message }}
			</span>
			<span class="unity-spacer" />
			<NcButton type="tertiary" @click="$emit('cancel')">{{ t('unity', 'Cancel') }}</NcButton>
			<NcButton type="primary" :disabled="saving || !valid" @click="save">
				<template v-if="saving" #icon><NcLoadingIcon :size="20" /></template>
				{{ t('unity', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { TRACKERS } from '../trackers.js'
import { tokenHelp } from '../tokenHelp.js'

export default {
	name: 'ConnectionForm',
	components: { NcTextField, NcPasswordField, NcButton, NcLoadingIcon, NcNoteCard, NcCheckboxRadioSwitch },
	props: {
		model: { type: Object, required: true },
	},
	emits: ['saved', 'cancel'],
	data() {
		return {
			form: { ...this.model, settings: { allowLocalAddress: false, ...(this.model.settings || {}) } },
			trackers: TRACKERS,
			testing: false,
			testResult: null,
			saving: false,
		}
	},
	computed: {
		isNew() {
			return !this.form.id
		},
		needsUsername() {
			return this.form.tracker === 'jira'
		},
		valid() {
			if (this.form.baseUrl.trim() === '') {
				return false
			}
			if (this.isNew && this.form.token.trim() === '') {
				return false
			}
			return true
		},
		baseUrlLabel() {
			return this.t('unity', 'Base URL')
		},
		baseUrlPlaceholder() {
			switch (this.form.tracker) {
			case 'jira': return 'https://your-site.atlassian.net'
			case 'gitlab': return 'https://gitlab.com'
			case 'github': return 'https://api.github.com'
			case 'redmine': return 'https://redmine.example.com'
			default: return ''
			}
		},
		tokenLabel() {
			return this.form.tracker === 'redmine' ? this.t('unity', 'API key') : this.t('unity', 'API token')
		},
		help() {
			return tokenHelp(this.form.tracker, this.form.baseUrl)
		},
	},
	methods: {
		async test() {
			this.testing = true
			this.testResult = null
			try {
				const { data } = await axios.post(generateUrl('/apps/unity/connections/test'), {
					tracker: this.form.tracker,
					baseUrl: this.form.baseUrl,
					username: this.form.username,
					token: this.form.token,
					tempoToken: this.form.tempoToken,
					settings: this.form.settings,
					id: this.form.id,
				})
				this.testResult = data
			} catch (e) {
				this.testResult = { ok: false, message: this.t('unity', 'Test failed') }
			} finally {
				this.testing = false
			}
		},
		async save() {
			this.saving = true
			try {
				const payload = {
					tracker: this.form.tracker,
					label: this.form.label,
					baseUrl: this.form.baseUrl,
					username: this.form.username,
					token: this.form.token,
					tempoToken: this.form.tempoToken,
					settings: this.form.settings,
				}
				if (this.isNew) {
					await axios.post(generateUrl('/apps/unity/connections'), payload)
				} else {
					await axios.put(generateUrl('/apps/unity/connections/{id}', { id: this.form.id }), payload)
				}
				showSuccess(this.t('unity', 'Connection saved'))
				this.$emit('saved')
			} catch (e) {
				showError(this.t('unity', 'Could not save connection'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.unity-form {
	max-width: 480px;
	margin-top: 16px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.unity-form-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-select {
	min-height: 36px;
	border-radius: var(--border-radius-element, 8px);
}
.unity-form-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
}
.unity-spacer {
	flex: 1;
}
.unity-test-result {
	font-size: 0.85em;
	color: var(--color-error);
}
.unity-test-result.ok {
	color: var(--color-success);
}
.unity-token-help {
	font-size: 0.9em;
}
.unity-help-title {
	font-weight: bold;
	margin-bottom: 4px;
}
.unity-help-auth {
	margin-bottom: 8px;
}
.unity-help-scopes,
.unity-help-notes {
	margin: 4px 0 8px;
	padding-inline-start: 18px;
	list-style: disc;
}
.unity-help-scopes code {
	background: var(--color-background-dark);
	border-radius: 4px;
	padding: 1px 5px;
}
.unity-help-link {
	color: var(--color-primary-element);
	font-weight: bold;
}
</style>
