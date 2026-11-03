<?php

declare(strict_types=1);

namespace OCA\NextCraft\Controller;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class EditorController extends Controller {
	public function __construct(
		IRequest $request,
		private CatalogService $catalog,
		private InstallService $installer,
		private IInitialState $initialState,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The editor page: frames the Craft app (same origin) and connects it to the user's files.
	 * ?path= is the file's path in the user's Files, ?fileId= is used when no path is given.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $app, string $path = '', int $fileId = 0): TemplateResponse {
		$meta = $this->catalog->app($app);
		$base = $meta === null ? null : $this->installer->webBase($app);
		if ($meta === null || $base === null) {
			return $this->error($meta === null
				? $this->l10n->t('Unknown app.')
				: $this->l10n->t('%s is not installed yet. Ask your administrator to install it in Administration settings ▸ NextCraft.', [$meta['name']]));
		}

		$user = $this->userSession->getUser();
		$file = null;
		if ($user !== null && ($path !== '' || $fileId > 0)) {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			try {
				$node = $path !== '' ? $userFolder->get($path) : $userFolder->getFirstNodeById($fileId);
			} catch (\Throwable) {
				$node = null;
			}
			if ($node === null || $node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
				return $this->error($this->l10n->t('The file could not be found.'));
			}
			$file = [
				'path' => $userFolder->getRelativePath($node->getPath()),
				'name' => $node->getName(),
				'id' => $node->getId(),
				'size' => $node->getSize(),
				'mime' => $node->getMimetype(),
				'etag' => $node->getEtag(),
				'canWrite' => $node->isUpdateable(),
			];
		}

		$entry = $this->installer->installed()[$app];
		$version = $entry['version'];
		$this->initialState->provideInitialState('editor', [
			'app' => $app,
			'name' => $meta['name'],
			'bridge' => $meta['bridge'],
			'native' => $meta['native'],
			'openOnly' => $meta['openOnly'],
			'version' => $version,
			'appUrl' => $base . 'index.html',
			'assetUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.show', ['app' => $app, 'version' => $version, 'path' => '_']),
			'file' => $file,
			'user' => $user === null ? null : ['id' => $user->getUID(), 'name' => $user->getDisplayName()],
			'liveServer' => ($meta['liveSessions'] ?? false) ? $this->appConfig->getValueString(Application::APP_ID, 'live_server', '') : '',
		]);
		Util::addScript(Application::APP_ID, 'nextcraft-editor');
		Util::addStyle(Application::APP_ID, 'editor');

		$response = new TemplateResponse(Application::APP_ID, 'editor', [
			'title' => $file !== null ? $file['name'] . ' – ' . $meta['name'] : $meta['name'],
		]);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	private function error(string $message): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'error', ['message' => $message], TemplateResponse::RENDER_AS_USER);
	}
}
