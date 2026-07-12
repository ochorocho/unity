<template>
	<div class="unity-detail">
		<div class="unity-detail-header">
			<div class="unity-detail-title">
				<span class="unity-badge" :style="{ backgroundColor: color }">{{ shortLabel }}</span>
				<div>
					<a :href="issue.url" target="_blank" rel="noopener noreferrer" class="unity-detail-link">
						{{ linkText }}
					</a>
					<h2>{{ issue.title }}</h2>
				</div>
			</div>
			<div class="unity-detail-actions">
				<NcButton v-if="!editing" type="secondary" @click="editing = true">{{ t('unity', 'Edit') }}</NcButton>
				<NcButton type="tertiary" :aria-label="t('unity', 'Close')" @click="$emit('close')">✕</NcButton>
			</div>
		</div>

		<IssueEdit v-if="editing" :issue="issue" @saved="onSaved" @cancel="editing = false" />

		<template v-else>
		<div class="unity-meta">
			<span v-if="issue.status" class="unity-chip">{{ issue.status }}</span>
			<span v-if="issue.assignee" class="unity-chip">{{ t('unity', 'Assignee') }}: {{ issue.assignee }}</span>
			<span v-if="issue.author" class="unity-chip">{{ t('unity', 'Author') }}: {{ issue.author }}</span>
			<span v-for="l in issue.labels" :key="l" class="unity-chip unity-chip-label">{{ l }}</span>
		</div>

		<div v-if="issue.description" class="unity-description">
			<RenderedText :text="issue.description"
				:format="issue.bodyFormat"
				:issue-ref="issue.ref"
				:editable="true"
				@update:text="onDescriptionTask" />
		</div>

		<IssueAttachments v-if="supportsAttachments"
			:issue-ref="issue.ref"
			:reload-key="attachmentsReloadKey"
			@changed="attachmentsReloadKey++" />

		<div v-if="supportsTime" class="unity-time">
			<div class="unity-time-header">
				<span class="unity-time-total">
					{{ t('unity', 'Time tracked') }}: <strong>{{ timeSpentText }}</strong>
				</span>
				<NcButton @click="openLog()">{{ t('unity', 'Log time') }}</NcButton>
			</div>
			<TimeRecords :issue-ref="issue.ref"
				:reload-key="recordsReloadKey"
				@edit="onEditRecord"
				@changed="onTimeChanged" />
		</div>

		<h3 class="unity-comments-title">{{ t('unity', 'Comments') }}</h3>
		<NcLoadingIcon v-if="loading" :size="24" />
		<CommentList v-else
			:comments="comments"
			:format="issue.bodyFormat"
			:issue-ref="issue.ref"
			:editable="true"
			@updated="$emit('updated')" />

		<AddComment :issue-ref="issue.ref" :format="issue.bodyFormat" :tracker="issue.tracker" @added="(c) => $emit('comment-added', c)" />

		<NcDialog v-if="showLogModal"
			:name="editRecord ? t('unity', 'Edit time entry') : t('unity', 'Log time')"
			size="normal"
			@closing="onLogClosing">
			<div class="unity-log-modal">
				<LogTime :key="editRecord ? editRecord.id : 'new'"
					:issue-ref="issue.ref"
					:record="editRecord"
					@logged="onLogged"
					@updated="onTimeUpdated" />
			</div>
		</NcDialog>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import CommentList from './CommentList.vue'
import AddComment from './AddComment.vue'
import LogTime from './LogTime.vue'
import RenderedText from './RenderedText.vue'
import TimeRecords from './TimeRecords.vue'
import IssueAttachments from './IssueAttachments.vue'
import IssueEdit from './IssueEdit.vue'
import { trackerById } from '../trackers.js'
import { humanizeDuration } from '../duration.js'

export default {
	name: 'IssueDetail',
	components: { NcButton, NcLoadingIcon, NcDialog, CommentList, AddComment, LogTime, RenderedText, TimeRecords, IssueAttachments, IssueEdit },
	props: {
		issue: { type: Object, required: true },
		comments: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},
	emits: ['close', 'comment-added', 'time-logged', 'updated'],
	data() {
		return {
			showLogModal: false,
			recordsReloadKey: 0,
			attachmentsReloadKey: 0,
			editing: false,
			editRecord: null,
		}
	},
	watch: {
		// This instance is reused across issue switches (no :key), so clear any
		// transient UI state so it doesn't leak from the previous issue.
		'issue.ref'() {
			this.editing = false
			this.showLogModal = false
			this.editRecord = null
		},
	},
	computed: {
		color() {
			return trackerById(this.issue.tracker).color
		},
		shortLabel() {
			return trackerById(this.issue.tracker).label.slice(0, 2)
		},
		supportsTime() {
			return trackerById(this.issue.tracker).timeTracking
		},
		supportsAttachments() {
			return trackerById(this.issue.tracker).attachments
		},
		timeSpentText() {
			return humanizeDuration(this.issue.timeSpentSeconds || 0)
		},
		linkText() {
			const project = this.issue.project
			// Jira keys (ABC-123) already encode the project; others benefit from the prefix.
			if (!project || this.issue.tracker === 'jira') {
				return this.issue.displayId
			}
			return `${project} ${this.issue.displayId}`
		},
	},
	methods: {
		openLog() {
			this.editRecord = null
			this.showLogModal = true
		},
		onEditRecord(record) {
			this.editRecord = record
			this.showLogModal = true
		},
		onLogClosing() {
			this.showLogModal = false
			this.editRecord = null
		},
		onLogged() {
			this.showLogModal = false
			this.editRecord = null
			this.recordsReloadKey++
			this.$emit('time-logged')
		},
		onTimeUpdated() {
			this.showLogModal = false
			this.editRecord = null
			this.recordsReloadKey++
			this.$emit('time-logged')
		},
		onTimeChanged() {
			this.recordsReloadKey++
			this.$emit('time-logged')
		},
		onSaved() {
			this.editing = false
			this.$emit('updated')
		},
		async onDescriptionTask(newText) {
			try {
				const ref = encodeURIComponent(this.issue.ref)
				await axios.put(generateUrl('/apps/unity/issues/{ref}', { ref }), { description: newText })
				this.$emit('updated')
			} catch (e) {
				showError(this.t('unity', 'Could not update the description'))
			}
		},
	},
}
</script>

<style scoped>
.unity-detail-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
	position: sticky;
	top: 0;
	z-index: 5;
	background: var(--color-main-background);
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}
.unity-detail-title {
	display: flex;
	gap: 12px;
	align-items: flex-start;
}
.unity-detail-actions {
	display: flex;
	gap: 6px;
	align-items: center;
}
.unity-detail-title h2 {
	margin: 4px 0 0;
	font-size: 1.2em;
}
.unity-detail-link {
	font-weight: bold;
	color: var(--color-primary-element);
}
.unity-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	color: #fff;
	font-size: 0.7em;
	font-weight: bold;
	flex-shrink: 0;
}
.unity-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 12px 0;
}
.unity-chip {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 2px 10px;
	font-size: 0.85em;
}
.unity-chip-label {
	background: var(--color-primary-element-light);
}
.unity-description {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px;
	margin-bottom: 16px;
	overflow-wrap: anywhere;
}
.unity-time {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}
.unity-time-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}
.unity-comments-title {
	margin: 16px 0 8px;
	font-size: 1em;
}
.unity-log-modal {
	padding: 0 16px 16px;
}
</style>
