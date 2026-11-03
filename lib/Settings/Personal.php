<?php

declare(strict_types=1);

namespace OCA\NextCraft\Settings;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use OCA\NextCraft\Service\PrefsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private CatalogService $catalog,
		private InstallService $installer,
		private PrefsService $prefs,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->initialState->provideInitialState('catalog', $this->catalog->forClient($this->installer->installed()));
		$this->initialState->provideInitialState('prefs', $uid === '' ? [] : $this->prefs->get($uid));
		Util::addScript(Application::APP_ID, 'nextcraft-personal');
		Util::addStyle(Application::APP_ID, 'settings');
		return new TemplateResponse(Application::APP_ID, 'settings-personal');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
