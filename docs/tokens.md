# Access token permissions

Unity connects to each tracker with a **per-user access token** you create yourself.
Tokens are stored encrypted in Nextcloud and are never shown again after saving.

Below is exactly where to create the token for each provider and which scopes /
permissions it must have so Unity can **read issues**, **read + post comments**, and
**log time** (where supported).

> Unity only ever needs issue + comment + worklog access. Never grant admin, repo
> administration, user management or delete scopes — they are not used.

---

## Jira (Cloud)

- **Authentication:** HTTP Basic — username = your Atlassian **account email**,
  password = the **API token**.
- **Create token:** <https://id.atlassian.net/manage-profile/security/api-tokens>
- **Base URL:** `https://your-site.atlassian.net`

**Required permissions**

| Type                        | What to grant                                                                                                                                                                   |
|-----------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Classic API token (default) | Unscoped — inherits your account permissions. Your Jira user must be able to **Browse Projects**, **View Issues**, **Add Comments**, and **Log Work** in the relevant projects. |
| Scoped API token (optional) | `read:jira-work` (view issues, comments, worklogs) and `write:jira-work` (add comments, log work).                                                                              |

**Time tracking**

- **Native worklog** uses the same Jira token (needs *Log Work* permission).
- **Tempo** (optional) uses a *separate* token created in **Jira → Tempo → Settings →
  API Integration**, granted worklog read/write access. Added on the Jira connection as
  the "Tempo API token".

---

## GitHub

- **Authentication:** Bearer token (`Authorization: Bearer <token>`), with
  `X-GitHub-Api-Version: 2022-11-28`.
- **Create token (fine-grained):** <https://github.com/settings/personal-access-tokens/new>
- **Base URL:** `https://api.github.com` (github.com) or `https://your-host/api/v3`
  (GitHub Enterprise Server).

**Required permissions**

| Token type                     | What to grant                                                                                                                                                                                                        |
|--------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Fine-grained PAT (recommended) | Select the repositories you want, then **Repository permissions → Issues: Read and write** (read = view issues/comments, write = post comments) and **Metadata: Read-only** (mandatory for all fine-grained tokens). |
| Classic PAT                    | Scope **`repo`** for private repositories, or **`public_repo`** for public repositories only.                                                                                                                        |

**Time tracking:** GitHub has **no time-tracking API** — time logging is disabled for
GitHub connections.

---

## GitLab

- **Authentication:** `PRIVATE-TOKEN: <token>` header.
- **Create token:** *User settings → Access tokens*
  (`https://gitlab.com/-/user_settings/personal_access_tokens`, or the same path on your
  self-hosted host).
- **Base URL:** `https://gitlab.com` or your self-hosted GitLab host.

**Required scopes**

| Scope      | Use                                                                                                                   |
|------------|-----------------------------------------------------------------------------------------------------------------------|
| **`api`**  | Read issues **and** write comments / log spent time (read + write). Choose this if you want to comment or track time. |
| `read_api` | Read-only alternative — browsing issues only, no comments or time logging.                                            |

**Time tracking:** uses `POST .../issues/:iid/add_spent_time` — requires the `api` scope.

---

## Redmine

- **Authentication:** `X-Redmine-API-Key: <key>` header.
- **Find your key:** *My account → API access key* (`https://your-host/my/account`).
- **Base URL:** your self-hosted Redmine host.

**Prerequisites & permissions**

- An administrator must **enable the REST API** under *Administration → Settings → API →
  "Enable REST web service"*.
- The API key inherits **your account's project permissions**. Your Redmine role needs:

| Permission         | Use                                                  |
|--------------------|------------------------------------------------------|
| **View issues**    | List and open issues                                 |
| **Add notes**      | Post comments (Redmine adds comments as issue notes) |
| **Log spent time** | Record time entries (time tracking)                  |

**Time tracking:** creates entries via `POST /time_entries.json`. If your Redmine requires
an activity, set a default **Activity ID** in the connection settings.

---

## Summary

| Provider    | Auth                  | Read + comment                                                                             | Time tracking                                         |
|-------------|-----------------------|--------------------------------------------------------------------------------------------|-------------------------------------------------------|
| **Jira**    | Basic (email + token) | Classic token / `read:jira-work` + `write:jira-work`                                       | Native worklog (same token) or Tempo (separate token) |
| **GitHub**  | Bearer                | Fine-grained: *Issues: Read and write* + *Metadata: Read*; classic: `repo` / `public_repo` | Not supported                                         |
| **GitLab**  | `PRIVATE-TOKEN`       | `api` (or `read_api` read-only)                                                            | `api` scope                                           |
| **Redmine** | `X-Redmine-API-Key`   | *View issues* + *Add notes* (REST enabled)                                                 | *Log spent time* permission                           |
