<template>
	<div class="unity-comments">
		<p v-if="comments.length === 0" class="unity-no-comments">
			{{ t('unity', 'No comments yet.') }}
		</p>
		<div v-for="comment in comments" :key="comment.id" class="unity-comment">
			<div class="unity-comment-head">
				<strong>{{ comment.author || t('unity', 'Unknown') }}</strong>
				<span class="unity-comment-meta">
					<span class="unity-comment-date">{{ formatDate(comment.createdAt) }}</span>
					<NcButton v-if="comment.editable && comment.id && editingId !== comment.id"
						type="tertiary"
						:aria-label="t('unity', 'Edit comment')"
						:title="t('unity', 'Edit comment')"
						@click="startEdit(comment)">
						<template #icon><Pencil :size="18" /></template>
					</NcButton>
					<NcButton v-if="comment.deletable && comment.id && editingId !== comment.id"
						type="tertiary"
						:aria-label="t('unity', 'Delete comment')"
						:title="t('unity', 'Delete comment')"
						:disabled="deletingId === comment.id"
						@click="remove(comment)">
						<template #icon>
							<NcLoadingIcon v-if="deletingId === comment.id" :size="18" />
							<Delete v-else :size="18" />
						</template>
					</NcButton>
				</span>
			</div>
			<template v-if="editingId === comment.id">
				<MarkupEditor v-model="editBody"
					:format="format"
					:issue-ref="issueRef"
					:tracker="tracker"
					:rows="4" />
				<div class="unity-comment-edit-actions">
					<NcButton :disabled="saving" @click="cancelEdit">{{ t('unity', 'Cancel') }}</NcButton>
					<NcButton type="primary" :disabled="saving || editBody.trim() === ''" @click="saveEdit(comment)">
						<template v-if="saving" #icon><NcLoadingIcon :size="20" /></template>
						{{ t('unity', 'Save') }}
					</NcButton>
				</div>
			</template>
			<RenderedText v-else
				class="unity-comment-body"
				:text="comment.body"
				:format="format"
				:rendered="comment.renderedBody"
				:issue-ref="issueRef"
				:editable="editable && !!comment.id"
				@update:text="(newText) => onCommentTask(comment, newText)" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showConfirmation, showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import RenderedText from './RenderedText.vue'
import MarkupEditor from './MarkupEditor.vue'

export default {
	name: 'CommentList',
	components: { NcButton, NcLoadingIcon, Pencil, Delete, RenderedText, MarkupEditor },
	props: {
		comments: { type: Array, default: () => [] },
		format: { type: String, default: 'plaintext' },
		issueRef: { type: String, default: '' },
		tracker: { type: String, default: '' },
		editable: { type: Boolean, default: false },
	},
	emits: ['updated'],
	data() {
		return {
			// The id of the comment currently being edited, or null.
			editingId: null,
			editBody: '',
			saving: false,
			// The id of the comment currently being deleted, or null.
			deletingId: null,
		}
	},
	watch: {
		// Editing state is keyed to a comment id; drop it if a re-fetch replaces
		// the comment set so a stale editor can't linger on a missing comment.
		comments() {
			if (this.editingId !== null && !this.comments.some((c) => c.id === this.editingId)) {
				this.cancelEdit()
			}
		},
	},
	methods: {
		formatDate(value) {
			return value ? moment(value).format('LLL') : ''
		},
		startEdit(comment) {
			this.editingId = comment.id
			this.editBody = comment.body
		},
		cancelEdit() {
			this.editingId = null
			this.editBody = ''
		},
		async saveEdit(comment) {
			if (this.editBody.trim() === '') {
				return
			}
			this.saving = true
			try {
				const ref = encodeURIComponent(this.issueRef)
				const commentId = encodeURIComponent(comment.id)
				const { data } = await axios.put(
					generateUrl('/apps/unity/issues/{ref}/comments/{commentId}', { ref, commentId }),
					{ body: this.editBody },
				)
				if (data && data.error) {
					showError(data.error || this.t('unity', 'Could not update the comment'))
					return
				}
				this.cancelEdit()
				// Silent re-fetch up the chain so the edited body (and its rendered
				// HTML) is refreshed from the tracker.
				this.$emit('updated')
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not update the comment'))
			} finally {
				this.saving = false
			}
		},
		async remove(comment) {
			const confirmed = await showConfirmation({
				name: this.t('unity', 'Delete comment'),
				text: this.t('unity', 'Delete this comment? This cannot be undone.'),
				labelConfirm: this.t('unity', 'Delete'),
				severity: 'error',
			})
			if (!confirmed) {
				return
			}
			this.deletingId = comment.id
			try {
				const ref = encodeURIComponent(this.issueRef)
				const commentId = encodeURIComponent(comment.id)
				await axios.delete(
					generateUrl('/apps/unity/issues/{ref}/comments/{commentId}', { ref, commentId }),
				)
				showSuccess(this.t('unity', 'Comment deleted'))
				// Silent re-fetch up the chain so the removed comment disappears.
				this.$emit('updated')
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not delete the comment'))
			} finally {
				this.deletingId = null
			}
		},
		async onCommentTask(comment, newText) {
			try {
				const ref = encodeURIComponent(this.issueRef)
				const commentId = encodeURIComponent(comment.id)
				await axios.put(
					generateUrl('/apps/unity/issues/{ref}/comments/{commentId}', { ref, commentId }),
					{ body: newText },
				)
				this.$emit('updated')
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not update the comment'))
			}
		},
	},
}
</script>

<style scoped>
.unity-comment {
	border-top: 1px solid var(--color-border);
	padding: 10px 0;
}
.unity-comment-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 0.9em;
}
.unity-comment-meta {
	display: flex;
	align-items: center;
	gap: 4px;
}
.unity-comment-date {
	color: var(--color-text-maxcontrast);
}
.unity-comment-body {
	margin-top: 4px;
	overflow-wrap: anywhere;
}
.unity-comment-edit-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
.unity-no-comments {
	color: var(--color-text-maxcontrast);
}
</style>
