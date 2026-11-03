<?php

declare(strict_types=1);

namespace OCA\NextCraft\Listener;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use OCA\NextCraft\Service\PrefsService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Adds the "Open with…" file actions to Files, with the catalog and the user's choices as
 * initial state (file actions decide synchronously whether they apply).
 * @template-implements IEventListener<Event>
 */
class LoadFilesScripts implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private CatalogService $catalog,
		private InstallService $installer,
		private PrefsService $prefs,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return; // public share pages: editing needs an account
		}
		$installed = $this->installer->installed();
		if ($installed === []) {
			return;
		}
		$this->initialState->provideInitialState('catalog', $this->catalog->forClient($installed));
		$this->initialState->provideInitialState('prefs', $this->prefs->get($user->getUID()));
		Util::addInitScript(Application::APP_ID, 'nextcraft-files');
	}
}
