<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service\Tracker;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Model\Attachment;
use OCA\Unity\Model\Connection;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Shared HTTP plumbing for tracker clients: auth headers, timeout, and a single
 * 429 + Retry-After backoff. Concrete clients implement authHeaders() and their
 * own JSON→DTO normalization.
 */
abstract class AbstractTrackerClient implements TrackerClientInterface {

	protected IClient $client;

	public function __construct(
		IClientService $clientService,
		protected LoggerInterface $logger,
	) {
		$this->client = $clientService->newClient();
	}

	public function supportsTimeTracking(): bool {
		return true;
	}

	/** Default: no structured attachment support (e.g. GitLab, GitHub). */
	public function supportsAttachments(): bool {
		return false;
	}

	/** Default: no issue relations. Overridden by trackers with a relations API. */
	public function supportsRelations(): bool {
		return false;
	}

	/** Default: creating issues is unsupported. Concrete clients override this. */
	public function supportsCreate(): bool {
		return false;
	}

	/** Default: no @mention support. Overridden by trackers that can encode mentions. */
	public function supportsMentions(): bool {
		return false;
	}

	/**
	 * Default: no create metadata. Overridden by trackers that support creation.
	 *
	 * @return array{projects: list<array{id: string, name: string, types: list<array{id: string, name: string}>}>, capabilities: array{type: bool, typeRequired: bool}, fields: list<array<string, mixed>>}
	 */
	public function getCreateMeta(Connection $connection, ?string $query = null, ?string $project = null, ?string $type = null): array {
		throw new TrackerException('Creating issues is not supported for this tracker');
	}

	/**
	 * Build a normalized field descriptor for the dynamic-field channel. `$extra` may
	 * carry `required`, `options`, `default`, `value`, `group`, `help`, `multiple`.
	 *
	 * @param 'text'|'textarea'|'int'|'float'|'date'|'bool'|'select'|'multiselect' $type
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	protected function field(string $id, string $name, string $type, array $extra = []): array {
		return array_merge([
			'id' => $id,
			'name' => $name,
			'type' => $type,
			'required' => false,
		], $extra);
	}

	/**
	 * Case-insensitive substring filter over normalized project entries. Used by
	 * trackers whose provider API has no server-side project search of its own, so
	 * the query typed in the create dialog still narrows the list.
	 *
	 * @param list<array{id: string, name: string, types: list<array{id: string, name: string}>}> $projects
	 * @param string|null $query
	 * @return list<array{id: string, name: string, types: list<array{id: string, name: string}>}>
	 */
	protected function filterProjectsByQuery(array $projects, ?string $query): array {
		$query = $query === null ? '' : trim($query);
		if ($query === '') {
			return $projects;
		}
		return array_values(array_filter($projects, static function (array $p) use ($query): bool {
			return stripos($p['name'], $query) !== false || stripos($p['id'], $query) !== false;
		}));
	}

	/**
	 * Default: creating issues is unsupported. Trackers with a create API override.
	 *
	 * @param array{project: string, type?: string, title: string, description?: string, assignee?: string, fields?: array<string, mixed>} $target
	 */
	public function createIssue(Connection $connection, array $target): \OCA\Unity\Model\Issue {
		throw new TrackerException('Creating issues is not supported for this tracker');
	}

	/**
	 * Default: no attachment list. Overridden by trackers with an attachment API.
	 *
	 * @param array $refParts
	 * @return Attachment[]
	 */
	public function getAttachments(Connection $connection, array $refParts): array {
		return [];
	}

	/**
	 * Default: uploading attachments is unsupported. Trackers with an attachment
	 * API override this.
	 *
	 * @param array $refParts
	 */
	public function uploadAttachment(Connection $connection, array $refParts, string $filename, string $mimeType, string $content): Attachment {
		throw new TrackerException('Attachments are not supported for this tracker');
	}

	/**
	 * Default: deleting attachments is unsupported. Trackers with an attachment
	 * API override this.
	 *
	 * @param array $refParts
	 */
	public function deleteAttachment(Connection $connection, array $refParts, string $attachmentId): void {
		throw new TrackerException('Attachments are not supported for this tracker');
	}

	/**
	 * Default: no relations. Overridden by trackers with a relations API.
	 *
	 * @param array $refParts
	 * @return \OCA\Unity\Model\Relation[]
	 */
	public function getRelations(Connection $connection, array $refParts): array {
		return [];
	}

	/**
	 * Default: no addable relation types. Overridden by trackers with a
	 * relations API.
	 *
	 * @param array $refParts
	 * @return list<array{id: string, name: string}>
	 */
	public function getRelationTypes(Connection $connection, array $refParts): array {
		return [];
	}

	/**
	 * Default: creating relations is unsupported. Trackers with a relations API
	 * override this.
	 *
	 * @param array $refParts
	 * @param array $targetParts
	 */
	public function addRelation(Connection $connection, array $refParts, string $type, array $targetParts): \OCA\Unity\Model\Relation {
		throw new TrackerException('Relations are not supported for this tracker');
	}

	/**
	 * Default: deleting relations is unsupported. Trackers with a relations API
	 * override this.
	 *
	 * @param array $refParts
	 */
	public function deleteRelation(Connection $connection, array $refParts, string $relationId): void {
		throw new TrackerException('Relations are not supported for this tracker');
	}

	/**
	 * Default: no assignable-user search. Overridden by trackers that support
	 * assigning issues.
	 *
	 * @param array{refParts?: array, project?: string} $context
	 * @return list<array{id: string, name: string, mention?: string}>
	 */
	public function searchAssignees(Connection $connection, array $context, string $query): array {
		return [];
	}

	/**
	 * Default: no itemized records (e.g. GitHub). Overridden by trackers that
	 * expose a worklog / time-entry list.
	 *
	 * @param array $refParts
	 * @return \OCA\Unity\Model\TimeRecord[]
	 */
	public function getTimeRecords(Connection $connection, array $refParts): array {
		return [];
	}

	/**
	 * Default: editing time entries is unsupported. Trackers that expose editable
	 * worklogs override this.
	 *
	 * @param array $refParts
	 */
	public function updateTime(Connection $connection, array $refParts, string $recordId, int $seconds, string $comment, ?string $startedAt): void {
		throw new TrackerException('Editing time entries is not supported for this tracker');
	}

	/**
	 * Default: deleting time entries is unsupported. Trackers that expose
	 * deletable worklogs override this.
	 *
	 * @param array $refParts
	 */
	public function deleteTime(Connection $connection, array $refParts, string $recordId): void {
		throw new TrackerException('Deleting time entries is not supported for this tracker');
	}

	/**
	 * Default: deleting comments is unsupported. Trackers whose API can delete a
	 * comment override this; the rest never flag a comment deletable.
	 *
	 * @param array $refParts
	 */
	public function deleteComment(Connection $connection, array $refParts, string $commentId): void {
		throw new TrackerException('Deleting comments is not supported for this tracker');
	}

	/**
	 * Fetch a referenced file with the connection's credentials. The URL is
	 * resolved and host-validated by resolveFileUrl() (SSRF guard) before any
	 * request is made.
	 *
	 * @param array $refParts
	 * @return array{body: string, contentType: string}
	 */
	public function fetchFile(Connection $connection, array $refParts, string $src): array {
		$url = $this->resolveFileUrl($connection, $refParts, $src);
		$response = $this->request('GET', $url, [
			'headers' => $this->fileHeaders($connection),
			'allow_redirects' => true,
		], $connection);
		if ($response->getStatusCode() >= 400) {
			throw new TrackerException('File fetch failed (HTTP ' . $response->getStatusCode() . ')');
		}
		$contentType = $response->getHeader('Content-Type');
		return [
			'body' => (string)$response->getBody(),
			'contentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
		];
	}

	/**
	 * Resolve and validate a file source. Default policy: only absolute URLs on
	 * the connection's own host are allowed. Subclasses override to also resolve
	 * provider-specific relative attachment paths.
	 *
	 * @param array $refParts
	 * @throws TrackerException when the source is not allowed (SSRF guard)
	 */
	protected function resolveFileUrl(Connection $connection, array $refParts, string $src): string {
		$src = trim($src);
		if ($src === '' || preg_match('#^https?://#i', $src) !== 1) {
			throw new TrackerException('Unsupported file source');
		}
		$host = strtolower((string)parse_url($src, PHP_URL_HOST));
		$baseHost = strtolower((string)parse_url($connection->baseUrl, PHP_URL_HOST));
		if ($host === '' || $host !== $baseHost) {
			throw new TrackerException('File host not allowed');
		}
		return $src;
	}

	/** Auth headers used when fetching a binary file (no JSON Accept). */
	protected function fileHeaders(Connection $connection): array {
		return $this->authHeaders($connection);
	}

	/**
	 * Case-insensitive name filter over {id, name} option lists. Used by trackers
	 * whose user API has no server-side search to filter assignees client-side.
	 * An empty query returns the list unchanged.
	 *
	 * @param list<array{id: string, name: string}> $options
	 * @return list<array{id: string, name: string}>
	 */
	protected function filterByName(array $options, string $query): array {
		$query = trim($query);
		if ($query === '') {
			return $options;
		}
		return array_values(array_filter(
			$options,
			static fn (array $o): bool => stripos($o['name'], $query) !== false,
		));
	}

	/**
	 * Canonical mention-token grammar, shared with the frontend editor. A picked
	 * user is stored in the body as `@"user/<handle>"`, where <handle> is the
	 * provider-native mention id (login, username, accountId, gid, …). The
	 * `user/`-prefixed quoted shape is the one NcRichContenteditable recognizes for
	 * ids that contain a colon (e.g. Jira accountIds), so it round-trips as a pill.
	 * Group 1 captures the handle.
	 */
	protected const MENTION_TOKEN_PATTERN = '/@"user\/([^"]+)"/';

	/**
	 * Rewrite every canonical mention token in $body to a provider-native form.
	 * `$format` receives the raw handle and returns its native representation
	 * (e.g. `@login`, `[~user]`); non-mention text is left untouched. Used by the
	 * plain-string providers and Jira Server; Jira Cloud (ADF) and Asana (HTML)
	 * encode mentions inside their own converters instead.
	 */
	protected function replaceMentionTokens(string $body, callable $format): string {
		return preg_replace_callback(
			self::MENTION_TOKEN_PATTERN,
			static fn (array $m): string => (string)$format($m[1]),
			$body,
		) ?? $body;
	}

	/** @return array<string, string> */
	abstract protected function authHeaders(Connection $connection): array;

	/** @return array<string, string> */
	protected function defaultHeaders(Connection $connection): array {
		return array_merge([
			'User-Agent' => Application::USER_AGENT,
			'Accept' => 'application/json',
		], $this->authHeaders($connection));
	}

	/**
	 * Perform an HTTP request, transparently retrying once on HTTP 429.
	 *
	 * @param array<string, mixed> $options
	 * @throws TrackerException on transport failure
	 */
	protected function request(string $method, string $url, array $options = [], ?Connection $connection = null, int $retriesLeft = 1): IResponse {
		$options['timeout'] ??= 30;
		$options['http_errors'] = false;
		// Opt out of Nextcloud's SSRF protection for this single request when the
		// connection was explicitly configured to allow an internal/local host.
		if ($connection !== null && !empty($connection->settings['allowLocalAddress'])) {
			$options['nextcloud']['allow_local_address'] = true;
		}
		try {
			$response = $this->client->request($method, $url, $options);
		} catch (\Throwable $e) {
			try {
				$response = $this->client->getResponseFromThrowable($e);
			} catch (\Throwable $inner) {
				$this->logger->warning('Unity request failed: ' . $e->getMessage(), ['exception' => $e]);
				throw new TrackerException('Request to ' . $url . ' failed: ' . $e->getMessage(), 0, $e);
			}
		}

		if ($response->getStatusCode() === 429 && $retriesLeft > 0) {
			$retryAfter = (int)($response->getHeader('Retry-After') ?: '2');
			$retryAfter = max(1, min($retryAfter, 10));
			sleep($retryAfter);
			return $this->request($method, $url, $options, $connection, $retriesLeft - 1);
		}

		return $response;
	}

	/**
	 * Decode a JSON response, raising a TrackerException on any non-2xx status.
	 *
	 * @return array<mixed>
	 * @throws TrackerException
	 */
	protected function json(IResponse $response, string $context): array {
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			$message = $this->extractError($body);
			$this->logger->warning('Unity API error: ' . $context . ' failed (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : ''));
			throw new TrackerException($context . ' failed (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : ''));
		}
		if ($body === '') {
			return [];
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new TrackerException($context . ': invalid JSON response');
		}
		return $data;
	}

	/**
	 * Extract the `page` query value of the rel="next" entry from an RFC 5988
	 * Link header (used by GitLab and GitHub for pagination).
	 */
	protected function nextPageFromLink(string $link): ?string {
		if ($link === '') {
			return null;
		}
		foreach (explode(',', $link) as $part) {
			if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $part, $m) === 1) {
				$queryString = parse_url($m[1], PHP_URL_QUERY);
				parse_str(is_string($queryString) ? $queryString : '', $params);
				return isset($params['page']) ? (string)$params['page'] : null;
			}
		}
		return null;
	}

	/**
	 * Best-effort extraction of a human-readable error from an API error body.
	 * Aggregates every available detail (rather than only the first message) so
	 * field-level validation errors surface — notably Jira, which puts them in a
	 * separate `errors` map that a bare `errorMessages` read would miss.
	 */
	protected function extractError(string $body): string {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			return '';
		}
		$parts = [];
		foreach (['message', 'error', 'error_description'] as $key) {
			if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
				$parts[] = $data[$key];
			}
		}
		// Jira: top-level human messages plus a field => reason map.
		foreach ($data['errorMessages'] ?? [] as $message) {
			if (is_string($message) && $message !== '') {
				$parts[] = $message;
			}
		}
		if (is_array($data['errors'] ?? null)) {
			foreach ($data['errors'] as $field => $reason) {
				if (is_string($reason) && $reason !== '') {
					$parts[] = (is_string($field) ? $field . ': ' : '') . $reason;
				}
			}
		}
		return implode('; ', array_unique($parts));
	}
}
