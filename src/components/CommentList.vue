<template>
	<div class="unity-comments">
		<p v-if="comments.length === 0" class="unity-no-comments">
			{{ t('unity', 'No comments yet.') }}
		</p>
		<div v-for="comment in comments" :key="comment.id" class="unity-comment">
			<div class="unity-comment-head">
				<strong>{{ comment.author || t('unity', 'Unknown') }}</strong>
				<span class="unity-comment-date">{{ formatDate(comment.createdAt) }}</span>
			</div>
			<RenderedText class="unity-comment-body"
				:text="comment.body"
				:format="format"
				:issue-ref="issueRef"
				:editable="editable && !!comment.id"
				@update:text="(newText) => onCommentTask(comment, newText)" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import RenderedText from './RenderedText.vue'

export default {
	name: 'CommentList',
	components: { RenderedText },
	props: {
		comments: { type: Array, default: () => [] },
		format: { type: String, default: 'plaintext' },
		issueRef: { type: String, default: '' },
		editable: { type: Boolean, default: false },
	},
	emits: ['updated'],
	methods: {
		formatDate(value) {
			return value ? moment(value).format('LLL') : ''
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
				showError(this.t('unity', 'Could not update the comment'))
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
	font-size: 0.9em;
}
.unity-comment-date {
	color: var(--color-text-maxcontrast);
}
.unity-comment-body {
	margin-top: 4px;
	overflow-wrap: anywhere;
}
.unity-no-comments {
	color: var(--color-text-maxcontrast);
}
</style>
