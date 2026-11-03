<?php

declare(strict_types=1);

namespace OCA\NextCraft\Controller;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\PrefsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PrefsController extends Controller {
	public function __construct(IRequest $request, private PrefsService $prefs, private IUserSession $userSession) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Sets the default handler for an extension; handler '' clears it. */
	#[NoAdminRequired]
	public function set(string $ext, string $handler = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse(['prefs' => $this->prefs->set($user->getUID(), $ext, $handler === '' ? null : $handler)]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
