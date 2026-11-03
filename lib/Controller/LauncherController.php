<?php

declare(strict_types=1);

namespace OCA\NextCraft\Controller;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\InstallService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Util;

/** The "ArtCraft Launcher" entry in the app menu: the launcher, framed, opening apps here. */
class LauncherController extends Controller {
	public function __construct(IRequest $request, private InstallService $installer, private IL10N $l10n) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): TemplateResponse {
		$base = $this->installer->webBase('artcraft-launcher');
		if ($base === null) {
			return new TemplateResponse(Application::APP_ID, 'error', [
				'message' => $this->l10n->t('The ArtCraft Launcher is not installed yet. Ask your administrator to install it (see Administration settings ▸ NextCraft).'),
			]);
		}
		Util::addStyle(Application::APP_ID, 'editor');
		$response = new TemplateResponse(Application::APP_ID, 'launcher', ['url' => $base . 'index.html']);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
