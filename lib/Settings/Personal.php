<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Settings;

use OCA\Unity\AppInfo\Application;
use OCA\Unity\Service\ConnectionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IInitialState $initialState,
		private ConnectionService $connectionService,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState(
			'unity-connections',
			$this->connectionService->list($this->userId ?? ''),
		);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'unity';
	}

	public function getPriority(): int {
		return 10;
	}
}
