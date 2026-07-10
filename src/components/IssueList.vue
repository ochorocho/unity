<template>
	<ul class="unity-issue-list">
		<NcListItem v-for="issue in issues"
			:key="issue.ref"
			:name="issue.title"
			:active="issue.ref === selectedRef"
			:force-display-actions="false"
			@click="$emit('select', issue)">
			<template #icon>
				<span class="unity-badge" :style="{ backgroundColor: color(issue.tracker) }">
					{{ label(issue.tracker) }}
				</span>
			</template>
			<template #subname>
				{{ issue.displayId }}<template v-if="issue.project"> · {{ issue.project }}</template>
				<template v-if="issue.status"> · {{ issue.status }}</template>
			</template>
			<template #details>
				<span class="unity-updated">{{ formatDate(issue.updatedAt) }}</span>
			</template>
		</NcListItem>
	</ul>
</template>

<script>
import moment from '@nextcloud/moment'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import { trackerById } from '../trackers.js'

export default {
	name: 'IssueList',
	components: { NcListItem },
	props: {
		issues: { type: Array, required: true },
		selectedRef: { type: String, default: '' },
	},
	emits: ['select'],
	methods: {
		color(tracker) {
			return trackerById(tracker).color
		},
		label(tracker) {
			return trackerById(tracker).label.slice(0, 2)
		},
		formatDate(value) {
			if (!value) {
				return ''
			}
			return moment(value).fromNow()
		},
	},
}
</script>

<style scoped>
.unity-issue-list {
	display: flex;
	flex-direction: column;
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
}
.list-item__wrapper--active .unity-updated {
	color: var(--color-primary-element-text);
	font-size: 0.85em;
	white-space: nowrap;
}
</style>
