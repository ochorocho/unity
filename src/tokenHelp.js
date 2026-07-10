/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Per-provider guidance on where to create an access token and which scopes /
 * permissions it must be granted for Unity to read issues, post comments and
 * log time.
 */
import { translate as t } from '@nextcloud/l10n'

function stripSlash(url) {
	return (url || '').replace(/\/+$/, '')
}

export function tokenHelp(tracker, baseUrl) {
	const base = stripSlash(baseUrl)
	switch (tracker) {
	case 'jira': {
		// Jira Cloud is always *.atlassian.net; any other host is Jira Server / Data Center.
		const isCloud = base === '' || /atlassian\.net/i.test(base)
		if (!isCloud) {
			return {
				createUrl: base + '/secure/ViewProfile.jspa',
				createLabel: t('unity', 'Open your Jira profile → Personal Access Tokens'),
				auth: t('unity', 'This looks like Jira Server / Data Center. Authentication is a Bearer Personal Access Token (PAT) — paste the token into the API token field and leave the email blank.'),
				scopes: [
					{ name: t('unity', 'View issues'), purpose: t('unity', 'Browse projects, read issues, comments and worklogs') },
					{ name: t('unity', 'Add comments & log work'), purpose: t('unity', 'Post comments and record time') },
				],
				notes: [
					t('unity', 'Create the token in Jira → your avatar → Profile → Personal Access Tokens → Create token (requires Jira Server/DC 8.14+).'),
					t('unity', 'The PAT inherits your account permissions, so your Jira user must be able to Browse Projects, Add Comments and Log Work in the relevant projects.'),
					t('unity', 'Descriptions and comments use wiki markup and are shown as plain text. Tempo Cloud is not used for Server connections.'),
				],
			}
		}
		return {
			createUrl: 'https://id.atlassian.net/manage-profile/security/api-tokens',
			createLabel: t('unity', 'Create a Jira API token'),
			auth: t('unity', 'Jira Cloud: authentication is HTTP Basic — use your Atlassian account email as the username and the API token as the password.'),
			scopes: [
				{ name: 'read:jira-work', purpose: t('unity', 'View issues, comments and worklogs') },
				{ name: 'write:jira-work', purpose: t('unity', 'Add comments and log work') },
			],
			notes: [
				t('unity', 'A classic (unscoped) API token inherits your account permissions — your Jira user must be able to Browse Projects, View Issues, Add Comments and Log Work in the relevant projects.'),
				t('unity', 'For a scoped API token, grant the two scopes listed above.'),
				t('unity', 'The optional Tempo token is created separately in Jira → Tempo → Settings → API Integration and needs worklog read/write access.'),
			],
		}
	}
	case 'github':
		return {
			createUrl: 'https://github.com/settings/personal-access-tokens/new',
			createLabel: t('unity', 'Create a GitHub fine-grained token'),
			auth: t('unity', 'Authentication is a Bearer token. Leave the base URL as https://api.github.com for github.com, or use https://your-host/api/v3 for GitHub Enterprise.'),
			scopes: [
				{ name: 'Issues: Read and write', purpose: t('unity', 'View issues & comments, and post comments') },
				{ name: 'Metadata: Read-only', purpose: t('unity', 'Required by GitHub for every fine-grained token') },
			],
			notes: [
				t('unity', 'Fine-grained token: select the repositories you want to see, then grant the two repository permissions above.'),
				t('unity', 'Classic token alternative: select the "repo" scope (or "public_repo" for public repositories only).'),
				t('unity', 'GitHub has no time-tracking API, so time logging is disabled for GitHub connections.'),
			],
		}
	case 'gitlab':
		return {
			createUrl: (base || 'https://gitlab.com') + '/-/user_settings/personal_access_tokens',
			createLabel: t('unity', 'Create a GitLab personal access token'),
			auth: t('unity', 'Authentication uses the PRIVATE-TOKEN header. Base URL is https://gitlab.com or your self-hosted GitLab host.'),
			scopes: [
				{ name: 'api', purpose: t('unity', 'Read issues and write comments / log time (read + write)') },
				{ name: 'read_api', purpose: t('unity', 'Read-only alternative — browsing only, no comments or time logging') },
			],
			notes: [
				t('unity', 'Choose the "api" scope to comment and log time; "read_api" is enough only if you want a read-only view.'),
			],
		}
	case 'redmine':
		return {
			createUrl: (base || '') + '/my/account',
			createLabel: t('unity', 'Open your Redmine account page'),
			auth: t('unity', 'Authentication uses the X-Redmine-API-Key header. Your API access key is shown on My account → API access key.'),
			scopes: [
				{ name: t('unity', 'View issues'), purpose: t('unity', 'List and open issues') },
				{ name: t('unity', 'Add notes'), purpose: t('unity', 'Post comments') },
				{ name: t('unity', 'Log spent time'), purpose: t('unity', 'Record time entries') },
			],
			notes: [
				t('unity', 'An administrator must enable the REST API under Administration → Settings → API → "Enable REST web service".'),
				t('unity', 'The API key inherits your account permissions, so your Redmine role needs the project permissions listed above.'),
			],
		}
	default:
		return null
	}
}
