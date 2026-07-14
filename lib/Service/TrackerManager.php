<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Service;

use OCA\Unity\Service\Tracker\AsanaClient;
use OCA\Unity\Service\Tracker\GithubClient;
use OCA\Unity\Service\Tracker\GitLabClient;
use OCA\Unity\Service\Tracker\JiraClient;
use OCA\Unity\Service\Tracker\RedmineClient;
use OCA\Unity\Service\Tracker\TrackerClientInterface;

/**
 * Registry mapping a tracker id to its client. Concrete clients are injected
 * (autowired) and registered by their own getTrackerId().
 */
class TrackerManager {

	/** @var array<string, TrackerClientInterface> */
	private array $clients = [];

	public function __construct(
		JiraClient $jira,
		GitLabClient $gitlab,
		RedmineClient $redmine,
		GithubClient $github,
		AsanaClient $asana,
	) {
		foreach ([$jira, $gitlab, $redmine, $github, $asana] as $client) {
			$this->clients[$client->getTrackerId()] = $client;
		}
	}

	public function get(string $tracker): ?TrackerClientInterface {
		return $this->clients[$tracker] ?? null;
	}

	/** @return string[] */
	public function trackerIds(): array {
		return array_keys($this->clients);
	}
}
