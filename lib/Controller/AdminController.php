<?php

declare(strict_types=1);

namespace OCA\NextCraft\Controller;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use OCA\NextCraft\Service\ReleaseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/** Admin-only (no #[NoAdminRequired]): install, update, remove, settings. */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private CatalogService $catalog,
		private InstallService $installer,
		private ReleaseService $releases,
		private IAppConfig $appConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function status(bool $check = false): JSONResponse {
		$installed = $this->installer->installed();
		$apps = [];
		foreach ($this->catalog->apps() as $id => $app) {
			$latest = null;
			$error = null;
			if ($check && !($app['launcher'] ?? false)) {
				try {
					$latest = $this->releases->release($app['repo'])['version'];
				} catch (\Throwable $e) {
					$error = $e->getMessage();
				}
			}
			$apps[$id] = [
				'name' => $app['name'],
				'description' => $app['description'],
				'repo' => $app['repo'],
				'installed' => $installed[$id]['version'] ?? null,
				'previous' => $installed[$id]['previous'] ?? null,
				'latest' => $latest,
				'error' => $error,
				'launcher' => $app['launcher'] ?? false,
			];
		}
		return new JSONResponse(['apps' => $apps, 'settings' => $this->readSettings()]);
	}

	public function install(string $app, bool $rollback = false): JSONResponse {
		@set_time_limit(0);
		ignore_user_abort(true);
		try {
			$result = $rollback ? ['id' => $app, 'version' => $this->installer->rollback($app), 'changed' => true] : $this->installer->install($app);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	public function remove(string $app): JSONResponse {
		if (!$this->catalog->exists($app)) {
			return new JSONResponse(['error' => 'Unknown app'], Http::STATUS_NOT_FOUND);
		}
		$this->installer->remove($app);
		return new JSONResponse(['id' => $app]);
	}

	public function settings(?bool $autoUpdate = null, ?string $liveServer = null, ?string $githubToken = null): JSONResponse {
		if ($autoUpdate !== null) {
			$this->appConfig->setValueBool(Application::APP_ID, 'auto_update', $autoUpdate);
		}
		if ($liveServer !== null) {
			$liveServer = trim($liveServer);
			if ($liveServer !== '' && !preg_match('#^wss?://[^\s"<>]+$#', $liveServer)) {
				return new JSONResponse(['error' => 'The live session server must be a ws:// or wss:// address'], Http::STATUS_BAD_REQUEST);
			}
			$this->appConfig->setValueString(Application::APP_ID, 'live_server', $liveServer);
		}
		if ($githubToken !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'github_token', trim($githubToken), sensitive: true);
		}
		return new JSONResponse($this->readSettings());
	}

	private function readSettings(): array {
		return [
			'autoUpdate' => $this->appConfig->getValueBool(Application::APP_ID, 'auto_update', false),
			'liveServer' => $this->appConfig->getValueString(Application::APP_ID, 'live_server', ''),
			'githubToken' => $this->appConfig->getValueString(Application::APP_ID, 'github_token', '') !== '',
		];
	}
}
