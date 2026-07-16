<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Unity\Controller;

use OCA\Unity\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;

class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private IEventDispatcher $eventDispatcher,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('unity-initial-state', [
			'userId' => $this->userId,
		]);
		// Load the Viewer app's scripts onto this page so OCA.Viewer.open() is available
		// client-side (issue images and previewable attachments open in it). Its LoadViewer
		// listeners also pull in the PDF and text viewers. Guarded: the Viewer app can be
		// disabled. dispatchTyped() runs Util::addScript(), which registers into the same
		// page-script list this TemplateResponse renders.
		if (class_exists(\OCA\Viewer\Event\LoadViewer::class)) {
			$this->eventDispatcher->dispatchTyped(new \OCA\Viewer\Event\LoadViewer());
		}
		$response = new TemplateResponse(Application::APP_ID, 'main');
		// Allow same-origin framing so previewable attachments (PDF, text) can render
		// in an iframe pointing at the credential-proxied /file endpoint. The default
		// CSP is default-src 'none' with no frame-src, which would block it.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
