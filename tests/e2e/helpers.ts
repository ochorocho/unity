/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

// Playwright runs from app/unity (the container working dir), so resolve fixtures
// against the cwd — avoids __dirname/import.meta differences under ESM ("type":"module").
const E2E_DIR = join(process.cwd(), 'tests', 'e2e')

export const TRACKERS = ['jira', 'gitlab', 'redmine', 'github', 'asana'] as const
export type Tracker = (typeof TRACKERS)[number]

export interface ProviderFixture {
	tracker: Tracker
	label: string
	issueRef: string
	searchTerm: string
}

/** Per-provider fixtures (tests/e2e/fixtures/providers.json), filtered to known trackers. */
export function readProviders(): ProviderFixture[] {
	const path = join(E2E_DIR, 'fixtures', 'providers.json')
	const data = JSON.parse(readFileSync(path, 'utf8'))
	return (data.providers || []).filter((p: ProviderFixture) => TRACKERS.includes(p.tracker))
}

/** The admin user's stored connections, read straight from oc_preferences. */
export interface StoredConnection {
	id: string
	tracker: Tracker
	label?: string
	baseUrl?: string
}

export async function readStoredConnections(
	db: { query<T = unknown[]>(sql: string, bindings?: readonly unknown[]): Promise<T> },
	userId = 'admin',
): Promise<StoredConnection[]> {
	const rows = await db.query<Array<{ configvalue: string }>>(
		"select configvalue from oc_preferences where userid = ? and appid = 'unity' and configkey = 'connections'",
		[userId],
	)
	if (!rows[0]?.configvalue) {
		return []
	}
	try {
		const parsed = JSON.parse(rows[0].configvalue)
		return Array.isArray(parsed) ? parsed : []
	} catch {
		return []
	}
}
