/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright E2E config, run inside the ddev-playwright container
 * (`ddev playwright test`). The container mounts the harness root at
 * /var/www/html and PLAYWRIGHT_TEST_DIR=app/unity sets the working dir, so this
 * config and node_modules resolve from app/unity.
 */
import { defineConfig, devices } from '@playwright/test'
import type { DbConnectorConfig } from '@ochorocho/playwright-db-connector'

// Nextcloud is pinned to overwritehost https://nextcloud-app-dev.ddev.site, so that
// URL (not http://web) is what the app generates redirects for. The ddev-router
// resolves it from inside the DDEV network; the cert is self-signed.
const BASE_URL = process.env.UNITY_BASE_URL || 'https://nextcloud-app-dev.ddev.site'

// The DB, reachable inside the DDEV network. DDEV's default Nextcloud creds are
// db/db/db on host `db`. cleanupStrategy 'none': the suite is read-only, and the app
// reads over its own PHP connection so transaction-rollback would be invisible anyway.
const dbConfig: DbConnectorConfig = {
	client: 'mysql2',
	connection: { host: 'db', port: 3306, user: 'db', password: 'db', database: 'db' },
	cleanupStrategy: 'none',
}

export default defineConfig<object, { dbConfig: DbConnectorConfig }>({
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	globalSetup: './tests/e2e/global-setup.ts',
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: BASE_URL,
		ignoreHTTPSErrors: true,
		storageState: 'tests/e2e/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		dbConfig,
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
})
