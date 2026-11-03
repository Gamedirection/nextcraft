<?php

declare(strict_types=1);

namespace OCA\NextCraft\Service;

use OCA\NextCraft\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Per-user default handler per extension: 'ask', 'builtin' (Nextcloud's own viewer/editor) or a
 * Craft app id. Extensions without a choice fall back to the catalog defaults in the browser.
 */
class PrefsService {
	public function __construct(
		private IUserConfig $userConfig,
		private CatalogService $catalog,
	) {
	}

	/** @return array<string, string> */
	public function get(string $userId): array {
		return $this->userConfig->getValueArray($userId, Application::APP_ID, 'handlers', []);
	}

	/** Sets (or with $handler === null clears) the default for $ext. */
	public function set(string $userId, string $ext, ?string $handler): array {
		$ext = strtolower(ltrim($ext, '.'));
		if (!preg_match('/^[a-z0-9]{1,16}$/', $ext)) {
			throw new \InvalidArgumentException('Invalid extension');
		}
		if ($handler !== null && !in_array($handler, ['ask', 'builtin'], true)) {
			$app = $this->catalog->app($handler) ?? throw new \InvalidArgumentException('Unknown app');
			if (!in_array($ext, $app['opens'], true) && !in_array('*', $app['opens'], true)) {
				throw new \InvalidArgumentException("{$app['name']} can't open .{$ext}");
			}
		}
		$prefs = $this->get($userId);
		if ($handler === null) {
			unset($prefs[$ext]);
		} else {
			$prefs[$ext] = $handler;
		}
		$this->userConfig->setValueArray($userId, Application::APP_ID, 'handlers', $prefs);
		return $prefs;
	}
}
