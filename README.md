# Unity — unified issue & ticket tracking for Nextcloud

Unity brings your issues from **Jira, GitLab, Redmine and GitHub** into a single,
sortable, cached view inside Nextcloud. Search across all your trackers, read issue
details and comments, add comments, and log time — using **your own API tokens**,
configured per user.

- 🔎 One search box across every tracker you connect
- 🗂️ Combined issue list with sorting and caching (fast, offline-friendly view)
- 💬 Read and post comments
- ⏱️ Log time (Jira native worklog + Tempo, GitLab, Redmine)
- 🔔 Dashboard widget, unified search, and background sync with notifications
- 🔐 Per-user tokens stored encrypted — no shared admin credentials

---

## Requirements

| Thing                                 | Version      |
|---------------------------------------|--------------|
| Nextcloud                             | **31 – 34**  |
| PHP                                   | **8.1+**     |
| Node.js *(only to build from source)* | 20, 22 or 24 |
| npm *(only to build from source)*     | 10 or 11     |

You also need a running Nextcloud instance with **background jobs (cron)** enabled so
Unity can sync issues and send notifications (see [Enable background sync](#5-enable-background-sync-recommended)).

---

## Installation

Unity is a standard Nextcloud app. It lives in your Nextcloud `custom_apps/` (or `apps/`)
directory under the folder name **`unity`**.

### Option A — Install from source (recommended)

The frontend assets (`js/`) are **not** committed to git, so you must build them once.

```bash
# 1. Get the code into your Nextcloud apps directory
cd /path/to/nextcloud/custom_apps
git clone <repository-url> unity
cd unity

# 2. Build the frontend (produces js/)
npm ci
npm run build

# 3. (optional) install PHP dev tools — only needed to run tests/linters
composer install
```

> **Note:** Unity has no runtime PHP dependencies, so a production install does not
> require `composer install` — building the frontend with `npm run build` is the only
> mandatory build step.

Then enable it:

```bash
# from your Nextcloud root
php occ app:enable unity
```

### Option B — Install a release archive

If you have a packaged `unity.tar.gz` release:

```bash
cd /path/to/nextcloud/custom_apps
tar xf unity.tar.gz          # extracts into ./unity (js/ already built)
php occ app:enable unity
```

Release archives already contain the built `js/`, so no npm step is needed.

### Option C — Nextcloud App Store

If Unity is available in your Nextcloud's **Apps → Integration** catalog, install it there
with one click — no command line needed.

---

## First-time setup

### 1. Open Unity

After enabling, a **Unity** entry appears in the Nextcloud top navigation. Open it.

### 2. Add a connection

Go to **Settings → Personal → Unity** (the app's personal settings section) and click
**Add connection**. For each connection choose the tracker and fill in:

| Field               | Meaning                                                    |
|---------------------|------------------------------------------------------------|
| **Tracker**         | Jira, GitLab, Redmine or GitHub                            |
| **Label**           | A friendly name shown in the UI (e.g. "Work Jira", "GEZE") |
| **Base URL**        | Your instance URL (see the table below)                    |
| **Username**        | Only for Jira Cloud (your Atlassian account email)         |
| **API token / key** | Your personal access token (created on the tracker)        |

You can add **multiple connections**, including several of the same tracker type.

### 3. Create your API tokens

Each connection needs a **personal access token** that you create on the tracker itself.
Full step-by-step instructions and the exact scopes to grant are in
**[`docs/tokens.md`](docs/tokens.md)**. Quick reference:

| Provider             | Auth                           | Base URL example                                          | Minimum access                                                                            |
|----------------------|--------------------------------|-----------------------------------------------------------|-------------------------------------------------------------------------------------------|
| **Jira Cloud**       | account email + API token      | `https://your-site.atlassian.net`                         | Browse projects, view issues, add comments, log work                                      |
| **Jira Server / DC** | Personal Access Token (Bearer) | `https://jira.your-company.com`                           | Same as above                                                                             |
| **GitHub**           | Personal access token          | `https://api.github.com` (or `.../api/v3` for Enterprise) | Issues: Read & write + Metadata: Read (fine-grained), or `repo` / `public_repo` (classic) |
| **GitLab**           | Personal access token          | `https://gitlab.com` (or self-hosted)                     | `api` scope (`read_api` for read-only)                                                    |
| **Redmine**          | API access key                 | `https://redmine.your-company.com`                        | View issues, Add notes, Log spent time (REST API enabled by admin)                        |

> Unity only ever needs **issue + comment + worklog** access. Never grant admin, delete,
> or user-management scopes — they are not used.

### 4. Search and browse

Type in Unity's search box to query all connected trackers at once. Click an issue to read
its description and comments, add a comment, or log time (where the tracker supports it).
Issues also appear in Nextcloud's global **unified search** and in a **Dashboard widget**.

### 5. Enable background sync (recommended)

Unity uses a background job to keep issues fresh and to notify you of updates. This needs
Nextcloud's background jobs running in **Cron** mode:

```bash
php occ background:cron        # switch to system cron mode (recommended)
```

Then make sure a system cron entry runs `cron.php` every 5 minutes, e.g.:

```
*/5 * * * * php -f /path/to/nextcloud/cron.php
```

---

## Local development (this repo)

This repository is a **DDEV harness** that spins up a Nextcloud instance and wires this app
in automatically. From the repo root:

```bash
ddev start                     # boots Nextcloud, symlinks this app, enables it
ddev occ app:list              # verify "unity" is enabled
```

App URL: **https://nextcloud-app-dev.ddev.site** (login `admin` / `admin`).

Frontend dev loop (run inside `app/unity/`):

```bash
npm run watch                  # rebuild js/ on every change
```

> After a rebuild, **hard-refresh** the browser — Nextcloud serves the built JS with an
> immutable cache header.

Backend tooling (run in the DDEV web container so PHP 8.3 + extensions are available):

```bash
ddev exec -d /var/www/html/app/unity composer run test:unit    # PHPUnit
ddev exec -d /var/www/html/app/unity composer run cs:check      # coding standard (dry-run)
ddev exec -d /var/www/html/app/unity composer run cs:fix        # auto-fix style
```

---

## Testing

```bash
composer run test:unit         # PHPUnit suite (tests/php/)
composer run cs:check          # Nextcloud coding standard, dry-run
composer run lint              # php -l on all sources
```

---

## Troubleshooting

- **The Unity page is blank / old after an update** — the frontend wasn't rebuilt or the
  browser cached it. Run `npm run build` and hard-refresh (`Cmd/Ctrl+Shift+R`).
- **`app:enable` fails with a version error** — your Nextcloud is outside the supported
  range (31–34). Check `php occ status`.
- **A connection shows no issues** — re-check the Base URL and that the token has the
  scopes listed in [`docs/tokens.md`](docs/tokens.md). Redmine also needs the REST API
  enabled by an administrator.
- **No notifications / stale issues** — background jobs aren't running in cron mode; see
  [Enable background sync](#5-enable-background-sync-recommended).

---

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html). © Jochen Roth.
