<template>
	<div class="unity-logtime">
		<h3 class="unity-logtime-title">{{ isEdit ? t('unity', 'Edit time entry') : t('unity', 'Log time') }}</h3>
		<div class="unity-logtime-row">
			<NcTextField v-model="duration"
				class="unity-logtime-duration"
				:label="t('unity', 'Duration (e.g. 1h 30m)')"
				@keydown.enter="submit" />
			<input v-model="date"
				type="date"
				class="unity-logtime-date"
				:aria-label="t('unity', 'Date')">
		</div>
		<NcTextField v-model="comment" :label="t('unity', 'Work description (optional)')" />
		<div class="unity-logtime-actions">
			<span v-if="parsedSeconds > 0" class="unity-logtime-preview">
				{{ humanize(parsedSeconds) }}
			</span>
			<NcButton type="primary" :disabled="submitting || parsedSeconds <= 0" @click="submit">
				<template v-if="submitting" #icon><NcLoadingIcon :size="20" /></template>
				{{ isEdit ? t('unity', 'Save') : t('unity', 'Log time') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { parseDuration, humanizeDuration } from '../duration.js'

/** Today's date as YYYY-MM-DD in the user's local timezone. */
function today() {
	return moment().format('YYYY-MM-DD')
}

export default {
	name: 'LogTime',
	components: { NcTextField, NcButton, NcLoadingIcon },
	props: {
		issueRef: { type: String, required: true },
		// When set, the form edits this existing time record instead of creating one.
		record: { type: Object, default: null },
	},
	emits: ['logged', 'updated'],
	data() {
		return {
			duration: this.record ? humanizeDuration(this.record.seconds) : '',
			comment: this.record ? (this.record.comment || '') : '',
			date: this.record && this.record.date ? String(this.record.date).slice(0, 10) : today(),
			submitting: false,
		}
	},
	computed: {
		isEdit() {
			return !!this.record
		},
		parsedSeconds() {
			return parseDuration(this.duration)
		},
	},
	methods: {
		humanize(seconds) {
			return humanizeDuration(seconds)
		},
		async submit() {
			const seconds = this.parsedSeconds
			if (seconds <= 0) {
				return
			}
			this.submitting = true
			try {
				const ref = encodeURIComponent(this.issueRef)
				const payload = { seconds, comment: this.comment, startedAt: this.date }
				if (this.isEdit) {
					const recordId = encodeURIComponent(this.record.id)
					await axios.put(generateUrl('/apps/unity/issues/{ref}/time/{recordId}', { ref, recordId }), payload)
					showSuccess(this.t('unity', 'Time entry updated'))
					this.$emit('updated')
				} else {
					await axios.post(generateUrl('/apps/unity/issues/{ref}/time', { ref }), payload)
					showSuccess(this.t('unity', 'Time logged'))
					this.duration = ''
					this.comment = ''
					this.date = today()
					this.$emit('logged')
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', this.isEdit ? 'Could not update time entry' : 'Could not log time'))
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.unity-logtime {
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
.unity-logtime-title {
	font-size: 1em;
	margin-bottom: 8px;
}
.unity-logtime-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}
.unity-logtime-duration {
	flex: 1;
}
.unity-logtime-date {
	min-height: 36px;
	border-radius: var(--border-radius-element, 8px);
	border: 2px solid var(--color-border-maxcontrast);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0 8px;
}
.unity-logtime-actions {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 8px;
}
.unity-logtime-preview {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
