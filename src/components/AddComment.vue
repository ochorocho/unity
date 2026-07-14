<template>
	<div class="unity-add-comment">
		<MarkupEditor v-model="body"
			:format="format"
			:issue-ref="issueRef"
			:tracker="tracker"
			:placeholder="t('unity', 'Write a comment…')"
			:rows="4" />
		<div class="unity-add-actions">
			<NcButton type="primary" :disabled="submitting || body.trim() === ''" @click="submit">
				<template v-if="submitting" #icon><NcLoadingIcon :size="20" /></template>
				{{ t('unity', 'Comment') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MarkupEditor from './MarkupEditor.vue'

export default {
	name: 'AddComment',
	components: { NcButton, NcLoadingIcon, MarkupEditor },
	props: {
		issueRef: { type: String, required: true },
		format: { type: String, default: 'plaintext' },
		tracker: { type: String, default: '' },
	},
	emits: ['added'],
	data() {
		return {
			body: '',
			submitting: false,
		}
	},
	methods: {
		async submit() {
			if (this.body.trim() === '') {
				return
			}
			this.submitting = true
			try {
				const ref = encodeURIComponent(this.issueRef)
				const { data } = await axios.post(
					generateUrl('/apps/unity/issues/{ref}/comments', { ref }),
					{ body: this.body },
				)
				if (data && !data.error) {
					this.$emit('added', data)
					this.body = ''
				} else {
					showError(data.error || this.t('unity', 'Could not add comment'))
				}
			} catch (e) {
				showError(e?.response?.data?.error || this.t('unity', 'Could not add comment'))
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.unity-add-comment {
	margin-top: 12px;
}
.unity-add-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
