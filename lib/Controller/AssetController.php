<?php

declare(strict_types=1);

namespace OCA\NextCraft\Controller;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\InstallService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * Serves files of an installed Craft build whose extension the web server won't serve directly
 * (Nextcloud's .htaccess sends anything but a few static types to index.php). The shim routes
 * such requests here when the direct URL fails.
 */
class AssetController extends Controller {
	private const TYPES = [
		'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'txt' => 'text/plain',
		'wasm' => 'application/wasm', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
		'html' => 'text/html', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png',
	];

	public function __construct(IRequest $request, private InstallService $installer) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $app, string $version, string $path): Response {
		$installed = $this->installer->installed()[$app] ?? null;
		if ($installed === null || !in_array($version, [$installed['version'], $installed['previous'] ?? null], true)
			|| str_contains($path, '..') || str_contains($path, "\0")) {
			return new Response(Http::STATUS_NOT_FOUND);
		}
		$base = realpath($this->installer->versionDir($app, $version));
		$file = realpath($this->installer->versionDir($app, $version) . '/' . $path);
		if ($base === false || $file === false || !str_starts_with($file, $base . '/') || !is_file($file)) {
			return new Response(Http::STATUS_NOT_FOUND);
		}
		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$response = new StreamResponse(fopen($file, 'rb'));
		$response->addHeader('Content-Type', self::TYPES[$ext] ?? 'application/octet-stream');
		$response->addHeader('Content-Length', (string)filesize($file));
		$response->cacheFor(86400);
		return $response;
	}
}
