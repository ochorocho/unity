<template>
	<NcContent app-name="unity">
		<NcAppNavigation>
			<template #list>
				<div class="unity-filters">
					<NcTextField v-model="term"
						:label="t('unity', 'Search issues')"
						trailing-button-icon="close"
						:show-trailing-button="term !== ''"
						@trailing-button-click="clearSearch"
						@keydown.enter="reload" />
					<label class="unity-field-label">{{ t('unity', 'Sort by') }}</label>
					<div class="unity-sort-row">
						<select v-model="sort" class="unity-select" @change="reload">
							<option v-for="o in sortOptions" :key="o.id" :value="o.id">{{ o.label }}</option>
						</select>
						<NcButton :aria-label="t('unity', 'Toggle sort direction')" @click="toggleOrder">
							{{ order === 'desc' ? '↓' : '↑' }}
						</NcButton>
					</div>
					<NcCheckboxRadioSwitch :model-value="assignedToMe" @update:model-value="onAssignedToggle">
						{{ t('unity', 'Assigned to me') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch :model-value="showClosed" @update:model-value="onShowClosedToggle">
						{{ t('unity', 'Show closed') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcAppNavigationItem :name="t('unity', 'All connections')"
					:active="activeConnection === ''"
					@click="selectConnection('')" />
				<NcAppNavigationItem v-for="c in connections"
					:key="c.id"
					:name="c.label || c.baseUrl"
					:active="activeConnection === c.id"
					@click="selectConnection(c.id)">
					<template #icon>
						<span class="unity-dot" :style="{ backgroundColor: trackerColor(c.tracker) }" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<NcButton type="tertiary" wide @click="openSettings">
					{{ t('unity', 'Manage connections') }}
				</NcButton>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div class="unity-main">
				<div v-if="connections.length === 0" class="unity-empty-wrap">
					<NcEmptyContent :name="t('unity', 'No connections yet')"
						:description="t('unity', 'Add a Jira, GitLab, Redmine, GitHub or Asana connection in Settings to see your issues.')">
						<template #action>
							<NcButton type="primary" @click="openSettings">{{ t('unity', 'Manage connections') }}</NcButton>
						</template>
					</NcEmptyContent>
				</div>

				<template v-else>
					<NcNoteCard v-for="e in errors" :key="e.connectionId" type="warning">
						{{ e.label || e.connectionId }}: {{ e.message }}
					</NcNoteCard>

					<div class="unity-split">
						<div class="unity-list-pane">
							<div class="unity-list-scroll">
								<div v-if="canCreateAny" class="unity-list-toolbar">
									<NcButton type="primary" @click="showCreate = true">
										<template #icon><Plus :size="20" /></template>
										{{ t('unity', 'New issue') }}
									</NcButton>
								</div>
								<NcEmptyContent v-if="!loading && issues.length === 0"
									:name="t('unity', 'No issues found')" />
								<IssueList v-else
									:issues="issues"
									:selected-ref="activeRef"
									@select="openIssue" />
								<div class="unity-list-footer">
									<NcButton v-if="hasMore && !loading" @click="loadMore">
										{{ t('unity', 'Load more') }}
									</NcButton>
									<!-- Appending (Load more) keeps the list visible with a footer
									     spinner; a full reload uses the overlay below instead. -->
									<NcLoadingIcon v-if="loading && appending" :size="28" />
								</div>
							</div>
							<!-- Full-list overlay while (re)loading the list from scratch. -->
							<div v-if="loading && !appending" class="unity-list-overlay">
								<NcLoadingIcon :size="44" />
							</div>
						</div>
						<!-- v-show (not v-if): the pane node is created once and only toggles
						     display, so switching issues never removes/re-adds it. -->
						<div v-show="selected || loadingRef" ref="detailPane" class="unity-detail-pane">
							<!-- Keep the current issue visible while the next one loads;
							     only show a spinner on first open (nothing to keep). -->
							<IssueDetail v-if="selected"
								:key="selected.ref"
								:issue="selected"
								:comments="comments"
								@close="closeDetail"
								@comment-added="onCommentAdded"
								@time-logged="onTimeLogged"
								@updated="onIssueUpdated"
								@open="openIssue({ ref: $event })" />
							<div v-else-if="loadingRef" class="unity-detail-loading">
								<NcLoadingIcon :size="44" />
							</div>
						</div>
					</div>
				</template>
			</div>
		</NcAppContent>

		<IssueCreate v-if="showCreate"
			:connections="connections"
			:preselected="activeConnection"
			@close="showCreate = false"
			@created="onIssueCreated" />
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Plus from 'vue-material-design-icons/Plus.vue'
import IssueList from './components/IssueList.vue'
import IssueDetail from './components/IssueDetail.vue'
import IssueCreate from './components/IssueCreate.vue'
import { SORT_OPTIONS, trackerById } from './trackers.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcNoteCard,
		NcLoadingIcon,
		Plus,
		IssueList,
		IssueDetail,
		IssueCreate,
	},
	data() {
		let assignedToMe = true
		let showClosed = false
		let activeConnection = ''
		try {
			const stored = window.localStorage.getItem('unity:assignedToMe')
			if (stored !== null) {
				assignedToMe = stored === 'true'
			}
			showClosed = window.localStorage.getItem('unity:showClosed') === 'true'
			activeConnection = window.localStorage.getItem('unity:activeConnection') || ''
		} catch (e) {
			// localStorage unavailable — fall back to the defaults
		}
		return {
			connections: [],
			issues: [],
			errors: [],
			nextCursors: {},
			term: '',
			sort: 'updated',
			order: 'desc',
			assignedToMe,
			showClosed,
			activeConnection,
			loading: false,
			// Whether the in-flight load is appending (Load more) vs a full reload.
			appending: false,
			selected: null,
			comments: [],
			loadingRef: null,
			searchTimer: null,
			showCreate: false,
			// The issue ref we want reflected in the URL hash ('' = none). Nextcloud
			// core navigates back once (~30ms) after we open an issue, stripping our
			// #issue/<ref>; onHistoryStrip re-asserts it (see setHashRef).
			hashTargetRef: '',
			// Loop guard: how many times we may re-assert the hash after a strip.
			hashReassertBudget: 0,
		}
	},
	computed: {
		sortOptions() {
			return SORT_OPTIONS()
		},
		hasMore() {
			return Object.keys(this.nextCursors).length > 0
		},
		/** True when at least one connected tracker can create issues. */
		canCreateAny() {
			return this.connections.some((c) => trackerById(c.tracker).create)
		},
		/** Ref to highlight in the list — the one being loaded, else the open one. */
		activeRef() {
			return this.loadingRef || (this.selected ? this.selected.ref : '')
		},
	},
	async mounted() {
		await this.loadConnections()
		// If the persisted connection filter no longer exists, fall back to "All".
		if (this.activeConnection !== '' && !this.connections.some((c) => c.id === this.activeConnection)) {
			this.activeConnection = ''
			try {
				window.localStorage.removeItem('unity:activeConnection')
			} catch (e) {
				// ignore
			}
		}
		if (this.connections.length > 0) {
			this.reload()
		}
		// Re-assert our #issue fragment whenever core's router strips it.
		window.addEventListener('popstate', this.onHistoryStrip)
		window.addEventListener('hashchange', this.onHistoryStrip)
		// Open the issue referenced in the (shareable) URL hash, if any.
		const ref = this.hashRef()
		if (ref !== '') {
			// Arm hash re-assertion for the restored deep link, then load it.
			this.setHashRef(ref)
			this.loadSelected(ref)
		}
		window.addEventListener('keydown', this.onKeydown)
	},
	beforeUnmount() {
		window.removeEventListener('popstate', this.onHistoryStrip)
		window.removeEventListener('hashchange', this.onHistoryStrip)
		window.removeEventListener('keydown', this.onKeydown)
	},
	methods: {
		trackerColor(id) {
			return trackerById(id).color
		},
		async loadConnections() {
			try {
				const { data } = await axios.get(generateUrl('/apps/unity/connections'))
				this.connections = data
			} catch (e) {
				showError(this.t('unity', 'Could not load connections'))
			}
		},
		onAssignedToggle(value) {
			this.assignedToMe = value
			try {
				window.localStorage.setItem('unity:assignedToMe', value ? 'true' : 'false')
			} catch (e) {
				// localStorage unavailable — preference just won't persist
			}
			this.reload()
		},
		onShowClosedToggle(value) {
			this.showClosed = value
			try {
				window.localStorage.setItem('unity:showClosed', value ? 'true' : 'false')
			} catch (e) {
				// localStorage unavailable — preference just won't persist
			}
			this.reload()
		},
		onSearchInput() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.reload(), 400)
		},
		clearSearch() {
			this.term = ''
			this.reload()
		},
		toggleOrder() {
			this.order = this.order === 'desc' ? 'asc' : 'desc'
			this.reload()
		},
		selectConnection(id) {
			this.activeConnection = id
			try {
				window.localStorage.setItem('unity:activeConnection', id)
			} catch (e) {
				// localStorage unavailable — preference just won't persist
			}
			this.reload()
		},
		async reload() {
			this.nextCursors = {}
			await this.fetchIssues(false)
		},
		async loadMore() {
			await this.fetchIssues(true)
		},
		async fetchIssues(append) {
			this.loading = true
			this.appending = append
			try {
				const params = {
					term: this.term,
					sort: this.sort,
					order: this.order,
					assignedToMe: this.assignedToMe ? 'true' : 'false',
					showClosed: this.showClosed ? 'true' : 'false',
				}
				if (this.activeConnection !== '') {
					params.connections = this.activeConnection
				}
				if (append) {
					params.cursors = JSON.stringify(this.nextCursors)
				}
				const { data } = await axios.get(generateUrl('/apps/unity/issues'), { params })
				this.issues = append ? this.issues.concat(data.issues) : data.issues
				this.errors = data.errors || []
				this.nextCursors = data.nextCursors || {}
			} catch (e) {
				showError(this.t('unity', 'Could not load issues'))
			} finally {
				this.loading = false
			}
		},
		openIssue(issue) {
			if (this.loadingRef === issue.ref || (this.selected && this.selected.ref === issue.ref)) {
				return
			}
			this.setHashRef(issue.ref)
			this.loadSelected(issue.ref)
		},
		/**
		 * Apply a freshly-fetched issue onto the open detail and the matching list
		 * row in place (Object.assign), so unchanged parts of the detail don't
		 * remount (no flicker) and the list reflects edits without a stale refetch.
		 */
		applyIssue(fresh) {
			if (this.selected && this.selected.ref === fresh.ref) {
				Object.assign(this.selected, fresh)
			} else {
				this.selected = fresh
				// The pane node persists (v-show), so reset its scroll on a real switch.
				this.$nextTick(() => {
					if (this.$refs.detailPane) {
						this.$refs.detailPane.scrollTop = 0
					}
				})
			}
			const row = this.issues.find((it) => it.ref === fresh.ref)
			if (row && row !== this.selected) {
				Object.assign(row, fresh)
			}
		},
		/**
		 * Load an issue into the detail pane. On a switch/open (silent=false) the
		 * pane stays visible and shows a loader (loadingRef) until the full issue is
		 * retrieved; on an in-place refresh after an edit (silent=true) the current
		 * issue keeps showing and is updated without a loader flash.
		 */
		async loadSelected(ref, silent = false) {
			// Idempotent open: don't re-load an issue that is already shown or loading
			// (a silent refresh is the intentional in-place update, so it's exempt).
			if (!silent && (this.loadingRef === ref || (this.selected && this.selected.ref === ref))) {
				return
			}
			if (!silent) {
				// Highlight the target in the list, but keep the current issue in the
				// detail pane until the new one is fully loaded (below).
				this.loadingRef = ref
			}
			try {
				const eref = encodeURIComponent(ref)
				const [detail, comments] = await Promise.all([
					axios.get(generateUrl('/apps/unity/issues/{ref}', { ref: eref })),
					axios.get(generateUrl('/apps/unity/issues/{ref}/comments', { ref: eref })),
				])
				if (detail.data && !detail.data.error) {
					this.applyIssue(detail.data)
					this.comments = Array.isArray(comments.data) ? comments.data : []
					// Viewing an issue dismisses its pending sync notification (fire-and-forget).
					axios.post(generateUrl('/apps/unity/issues/{ref}/seen', { ref: eref })).catch(() => {})
				} else {
					// The issue could not be loaded; surface it but keep the URL/state
					// so a transient failure can't wipe the open issue or the deep link.
					showError((detail.data && detail.data.error) || this.t('unity', 'Could not load issue details'))
				}
			} catch (e) {
				showError(this.t('unity', 'Could not load issue details'))
			} finally {
				this.loadingRef = null
			}
		},
		closeDetail() {
			this.selected = null
			this.loadingRef = null
			this.comments = []
			this.setHashRef('')
		},
		onKeydown(e) {
			// Close the detail on Escape — unless something else already consumed this
			// Escape, which is what a dialog closing itself does (NcModal and our
			// FilePreview both preventDefault). Checking the event is what makes this
			// deterministic; the `.modal-mask` probe alone is not enough, because the
			// DOM runs a microtask checkpoint after every listener, so Vue has already
			// flushed the dialog's unmount — and removed the mask — before this
			// bubble-phase listener runs. The mask is therefore reliably *absent* on the
			// very press that closed a dialog, which is precisely when we must not act.
			if (e.key === 'Escape' && this.selected && !e.defaultPrevented && !document.querySelector('.modal-mask')) {
				this.closeDetail()
			}
		},
		/** Read the issue ref from the URL hash (#issue/<ref>), or '' if none. */
		hashRef() {
			const match = (window.location.hash || '').match(/^#issue\/(.+)$/)
			return match ? decodeURIComponent(match[1]) : ''
		},
		/**
		 * Reflect the selected issue in the shareable URL (#issue/<ref>), or clear it
		 * with ref=''. Arms onHistoryStrip to re-assert the fragment: Nextcloud core
		 * runs a global router that, ~30ms after we open an issue, navigates back once
		 * and strips our fragment. Our write is a state-preserving replaceState (silent
		 * — fires no popstate/hashchange), so re-asserting does not re-trigger core.
		 */
		setHashRef(ref) {
			this.hashTargetRef = ref || ''
			// Enough re-asserts to outlast core's strip(s); capped so a genuine fight
			// can't loop forever (worst case: the URL ends up without the fragment).
			this.hashReassertBudget = 10
			this._writeHash()
		},
		/** Write hashTargetRef into the current history entry, preserving state. */
		_writeHash() {
			const base = window.location.pathname + window.location.search
			const url = this.hashTargetRef ? base + '#issue/' + encodeURIComponent(this.hashTargetRef) : base
			const current = window.location.pathname + window.location.search + window.location.hash
			if (current !== url) {
				window.history.replaceState(window.history.state, '', url)
			}
		},
		/**
		 * Re-assert our fragment after core's router strips it. Bounded by
		 * hashReassertBudget so an unexpected persistent fight degrades gracefully
		 * instead of looping.
		 */
		onHistoryStrip() {
			const wantHash = this.hashTargetRef ? '#issue/' + encodeURIComponent(this.hashTargetRef) : ''
			if ((window.location.hash || '') === wantHash) {
				return
			}
			if (this.hashReassertBudget <= 0) {
				return
			}
			this.hashReassertBudget--
			this._writeHash()
		},
		onCommentAdded(comment) {
			this.comments.push(comment)
		},
		onTimeLogged() {
			// Silent refresh so the logged-time total updates without a loader flash.
			if (this.selected) {
				this.loadSelected(this.selected.ref, true)
			}
		},
		onIssueUpdated() {
			// Silent re-fetch (uncached) — applyIssue() syncs the list row in place,
			// so no loader flash and no full (cache-stale) list reload.
			if (this.selected) {
				this.loadSelected(this.selected.ref, true)
			}
		},
		onIssueCreated(issue) {
			this.showCreate = false
			if (issue && issue.ref) {
				// Refresh the list (the new issue is now uncached) and open it.
				this.reload()
				this.setHashRef(issue.ref)
				this.loadSelected(issue.ref)
			}
		},
		openSettings() {
			window.location.href = generateUrl('/settings/user/unity')
		},
	},
}
</script>

<style scoped lang="scss">
.unity-filters {
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.unity-field-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-sort-row {
	display: flex;
	gap: 6px;
	align-items: center;
}
.unity-select {
	flex: 1;
	min-height: 34px;
	border-radius: var(--border-radius-element, 8px);
}
.unity-dot {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
}
.unity-main {
	padding: 12px 16px;
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	min-height: 0;
	/* Constrain to the (viewport-bound) app content so the panes scroll, not the page. */
	overflow: hidden;
}
.unity-empty-wrap {
	display: flex;
	flex: 1;
	align-items: center;
	justify-content: center;
}
.unity-split {
	display: flex;
	gap: 16px;
	flex: 1;
	/* Let the panes shrink below their content height so they can scroll. */
	min-height: 0;
	align-items: stretch;
	/* Positioning context for the mobile detail overlay (see media query below). */
	position: relative;
}
.unity-list-pane {
	/* Ratio-based basis:0 grow → the 40/60 split is fixed by ratio and never
	   shifts with the panes' content (e.g. detail content vs. a loading spinner).
	   When the detail pane is hidden, the list grows to fill the full width. */
	flex: 2 1 0;
	min-width: 0;
	min-height: 0;
	height: 100%;
	/* Positioning context for the loading overlay; scrolling lives on the inner
	   wrapper so the overlay covers the visible pane, not the full scroll height. */
	position: relative;
	overflow: hidden;
}
.unity-list-scroll {
	height: 100%;
	overflow-y: auto;
	scrollbar-gutter: stable;
}
.unity-list-overlay {
	position: absolute;
	inset: 0;
	z-index: 20;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-main-background-translucent, rgba(255, 255, 255, 0.6));
}
.unity-detail-pane {
	flex: 3 1 0;
	min-width: 0;
	min-height: 0;
	height: 100%;
	overflow-y: auto;
	scrollbar-gutter: stable;
	border-inline-start: 1px solid var(--color-border);
	padding-inline-start: 16px;
}
.unity-detail-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
}
.unity-list-toolbar {
	display: flex;
	justify-content: flex-end;
	padding: 0 0 8px;
}
.unity-list-footer {
	display: flex;
	justify-content: center;
	padding: 12px 0;
}

/* Phone-width layout: show one pane at a time. The list fills the screen; when
   an issue is opened the detail pane (v-show-toggled by `selected`) becomes a
   full-screen overlay, and its ✕ (→ closeDetail) acts as "back" to the list. */
@media (max-width: 700px) {
	.unity-main {
		padding: 8px 10px;
	}
	.unity-split {
		gap: 0;
	}
	.unity-list-pane {
		/* Fill the whole content area when nothing is selected. */
		flex-basis: 100%;
	}
	.unity-detail-pane {
		/* Overlay the content area. z-index stays below the framework's nav
		   drawer and NcDialog layers, so the hamburger and modals sit on top. */
		position: absolute;
		inset: 0;
		z-index: 30;
		flex: none;
		background: var(--color-main-background);
		/* Drop the desktop divider/gutter — the pane is full-width now. */
		border-inline-start: none;
		padding-inline-start: 0;
	}
}
</style>
