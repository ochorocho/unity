/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Offline, deterministic coverage of the connection form for ALL five providers.
 * Opening the form and switching the tracker calls no tracker API. Assertions use
 * structural signatures (option values, field counts) rather than localized label
 * text, so they hold regardless of the admin's UI language.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import { TRACKERS } from './helpers'

test.describe('personal settings — connection form', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/index.php/settings/user/unity')
		await expect(page.locator('#unity_prefs_content')).toBeVisible({ timeout: 20_000 })
	})

	// The "Add connection" button is the only <button> that is a direct child of the
	// section (Edit/Delete live inside .unity-connection-row).
	const openForm = async (page: import('@playwright/test').Page) => {
		await page.locator('#unity_prefs_content > button').click()
		const form = page.locator('.unity-form')
		await expect(form).toBeVisible()
		return form
	}

	test('offers all five trackers', async ({ page }) => {
		const form = await openForm(page)
		const values = await form
			.locator('select.unity-select')
			.first()
			.locator('option')
			.evaluateAll((opts) => opts.map((o) => (o as HTMLOptionElement).value))
		expect(new Set(values)).toEqual(new Set(TRACKERS))
	})

	for (const tracker of TRACKERS) {
		test(`renders the ${tracker} fields`, async ({ page }) => {
			const form = await openForm(page)
			await form.locator('select.unity-select').first().selectOption(tracker)

			// Token field is always present.
			await expect(form.locator('input[type="password"]').first()).toBeVisible()
			// Jira adds a second password field (Tempo token).
			await expect(form.locator('input[type="password"]')).toHaveCount(tracker === 'jira' ? 2 : 1)
			// Redmine adds a second <select> (text format).
			await expect(form.locator('select.unity-select')).toHaveCount(tracker === 'redmine' ? 2 : 1)
		})
	}
})
