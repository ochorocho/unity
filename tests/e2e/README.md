# Unity E2E tests (Playwright)

Browser tests run via the [`ddev-playwright`](https://github.com/ochorocho/ddev-playwright)
add-on, with DB access through [`@ochorocho/playwright-db-connector`](https://www.npmjs.com/package/@ochorocho/playwright-db-connector).
They are **read-only**: no issue is ever created or edited.

## Run

From the **harness root** (`nextcloud-app-dev/`):

```bash
ddev playwright test                 # run all
ddev playwright test settings        # a single spec
ddev playwright show-report          # open the HTML report
ddev playwright browser              # interactive UI mode
```

The add-on runs Playwright in its own container against the app at
`https://nextcloud-app-dev.ddev.site` and the DB at `db:3306`. `PLAYWRIGHT_TEST_DIR=app/unity`
(in `.ddev/.env.playwright`) points it at this app's `node_modules` and `playwright.config.ts`.

## One-time setup (already done)

- Add-on: `ddev add-on get ochorocho/ddev-playwright` → `ddev restart`.
- Dev deps in `app/unity`: `@playwright/test@1.50.0` (must match the add-on image),
  `@ochorocho/playwright-db-connector`, `mysql2`.
- Login is handled once in `global-setup.ts` (admin/admin → `tests/e2e/.auth/admin.json`, gitignored).

## What's covered

- **`smoke.spec.ts`** (offline): the app page and settings section load; the admin's connections in
  `oc_preferences` are valid JSON for known trackers (a read-only DB assertion via the connector).
- **`settings.spec.ts`** (offline): the connection form for **all five** providers — the tracker
  select offers all of them, and each renders its expected fields (asserted by structure, so it
  holds in any UI language).
- **`providers.spec.ts`** (live, read-only): per configured provider — open its issue list and view a
  named issue's detail. **Opt-in**: a test only runs once you set `issueRef` (or `searchTerm`) for it.

## Enabling the live provider tests

Edit `tests/e2e/fixtures/providers.json`. Labels are pre-filled from the configured connections; add
an `issueRef` (a string that appears in the issue's list row/title) or a `searchTerm` for each
provider you want to exercise:

```json
{ "tracker": "jira", "label": "Jira Cloud", "issueRef": "B13INTERN-2531", "searchTerm": "" }
```

Live tests hit the real tracker APIs, so they depend on the network, the tracker being up, and the
stored token — treat failures there as environmental, not necessarily app regressions.
