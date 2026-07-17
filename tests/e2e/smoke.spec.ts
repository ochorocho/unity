/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Offline, deterministic smoke tests: the app shell loads, and the connections
 * the UI relies on are present in the database (read-only DB assertion via the
 * db-connector). No tracker API is called.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import { readStoredConnections, TRACKERS } from './helpers'

test.describe('app shell', () => {
	test('the Unity app page loads and mounts', async ({ page }) => {
		await page.goto('/index.php/apps/unity/')
		await expect(page).toHaveTitle(/Unity|Nextcloud/i)
		// The Vue app mounts into #content; either the split view (has connections) or
		// the empty-state wrapper (no connections) renders.
		await expect(page.locator('.unity-split, .unity-empty-wrap').first()).toBeVisible({ timeout: 20_000 })
	})

	test('personal settings section renders', async ({ page }) => {
		await page.goto('/index.php/settings/user/unity')
		await expect(page.locator('#unity_prefs_content')).toBeVisible({ timeout: 20_000 })
	})
})

test.describe('database', () => {
	test("admin's connections are valid JSON with known trackers", async ({ db }) => {
		const connections = await readStoredConnections(db)
		// The store may legitimately be empty; when present, every entry must be a
		// well-formed connection for one of the five known trackers.
		for (const conn of connections) {
			expect(conn.id, 'connection has an id').toBeTruthy()
			expect(TRACKERS, `tracker "${conn.tracker}" is known`).toContain(conn.tracker)
		}
		// Surface the configured set in the report for convenience.
		console.log('Configured connections:', connections.map((c) => `${c.tracker}:${c.label ?? c.id}`).join(', ') || '(none)')
	})
})
