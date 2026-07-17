/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Live, STRICTLY read-only tests, one per configured provider. Each opens the
 * connection's issue list and views a specific issue (never edits or creates).
 * A provider is skipped unless BOTH a fixture entry (tests/e2e/fixtures/providers.json)
 * and a matching connection in the DB exist, so partial setups don't fail the run.
 *
 * Issue data is fetched live from the real tracker, so these depend on the network,
 * the tracker API, and the stored token — hence the generous waits and presence-based
 * assertions. Fill in providers.json (label + issueRef/searchTerm) to enable them.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import { readProviders, readStoredConnections } from './helpers'

for (const fx of readProviders()) {
	test.describe(`provider (live): ${fx.tracker}`, () => {
		test(`views the issue list and an issue for "${fx.label || fx.tracker}"`, async ({ page, db }) => {
			// Only run when an issue to view is named — keeps live tracker calls opt-in.
			test.skip(
				!(fx.issueRef || fx.searchTerm),
				`Set issueRef or searchTerm for ${fx.tracker} in tests/e2e/fixtures/providers.json to enable`,
			)
			const conn = (await readStoredConnections(db)).find(
				(c) => c.tracker === fx.tracker && (!fx.label || c.label === fx.label),
			)
			test.skip(!conn, `No ${fx.tracker} connection named "${fx.label}" is configured on admin`)

			await page.goto('/index.php/apps/unity/')
			await expect(page.locator('.unity-split').first()).toBeVisible({ timeout: 20_000 })

			// Select this connection in the sidebar. The nav lists connections by label.
			await page.getByText(fx.label, { exact: false }).first().click()

			// The issue list pane renders (rows, an empty note, or an error note — all
			// are valid live states; we assert the pane itself is present).
			await expect(page.locator('.unity-list-pane').first()).toBeVisible({ timeout: 30_000 })

			// Open a specific issue when one is named, then assert the detail pane.
			if (fx.issueRef || fx.searchTerm) {
				const term = fx.issueRef || fx.searchTerm
				const row = page.locator('.unity-issue-list').getByText(term, { exact: false }).first()
				await expect(row).toBeVisible({ timeout: 30_000 })
				await row.click()
				await expect(page.locator('.unity-detail-pane').first()).toBeVisible({ timeout: 30_000 })
			}
		})
	})
}
