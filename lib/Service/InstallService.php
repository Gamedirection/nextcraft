<?php

declare(strict_types=1);

namespace OCA\NextCraft\Service;

use OCA\NextCraft\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\ITempManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Installs Craft web builds into <app dir>/craft/<id>/<version>/ so Nextcloud's web server serves
 * them from its own origin. Each release zip is checked against the release's SHA256SUMS.txt and
 * kept in app data, so the app folder can be rebuilt after a NextCraft upgrade replaces it.
 * The active and the previous version stay on disk; switching back is a config change.
 */
class InstallService {
	/** Inserted first in each app's <head>: hooks open dialogs, drops and downloads. */
	public const SHIM_TAG = '<script src="../../../js/nextcraft-shim.js"></script>';

	public function __construct(
		private CatalogService $catalog,
		private ReleaseService $releases,
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private IAppData $appData,
		private ITempManager $tempManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, array{version: string, tag: string, previous: ?string, installedAt: int, icon: ?string}> */
	public function installed(): array {
		return $this->appConfig->getValueArray(Application::APP_ID, 'installed', []);
	}

	public function installedVersion(string $id): ?string {
		return $this->installed()[$id]['version'] ?? null;
	}

	/** URL path (no host) of the folder that holds the active build of $id, with a trailing slash. */
	public function webBase(string $id): ?string {
		$version = $this->installedVersion($id);
		if ($version === null) {
			return null;
		}
		return $this->appManager->getAppWebPath(Application::APP_ID) . '/craft/' . $id . '/' . $version . '/';
	}

	public function craftDir(): string {
		return $this->appManager->getAppPath(Application::APP_ID) . '/craft';
	}

	public function versionDir(string $id, string $version): string {
		return $this->craftDir() . '/' . $id . '/' . $version;
	}

	/**
	 * Installs (or updates to) $tag, the latest release when null.
	 * @return array{id: string, version: string, changed: bool}
	 */
	public function install(string $id, ?string $tag = null, bool $force = false): array {
		$app = $this->catalog->app($id) ?? throw new \InvalidArgumentException("Unknown Craft app: {$id}");
		if ($app['launcher'] ?? false) {
			// The official launcher only offers downloads; NextCraft needs the GameDirection build.
			throw new \RuntimeException("{$app['name']} is installed from the GameDirection build: run deploy/install-launcher.sh (see README)");
		}
		$release = $this->releases->release($app['repo'], $tag, true);
		$version = $this->safeSegment($release['version']);
		$current = $this->installed()[$id] ?? null;
		if (!$force && $current !== null && $current['version'] === $version && is_file($this->versionDir($id, $version) . '/index.html')) {
			return ['id' => $id, 'version' => $version, 'changed' => false];
		}

		$tmp = $this->tempManager->getTemporaryFile('.zip');
		try {
			$this->releases->download($release['zipUrl'], $tmp);
			$this->verify($release, $tmp);
			return $this->installZip($id, $version, $tmp, $release['tag']);
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Installs a web build from a local zip the admin supplies (e.g. a self-built ArtCraft
	 * Launcher that opens NextCraft instead of offering downloads). Not checksum-verified.
	 * @return array{id: string, version: string, changed: bool}
	 */
	public function installZip(string $id, string $version, string $zipFile, ?string $tag = null): array {
		$this->catalog->app($id) ?? throw new \InvalidArgumentException("Unknown Craft app: {$id}");
		$version = $this->safeSegment($version);
		$current = $this->installed()[$id] ?? null;
		$this->unpack($id, $version, $zipFile);
		$this->cacheZip($id, $version, $zipFile);

		$installed = $this->installed();
		$previous = $current !== null && $current['version'] !== $version ? $current['version'] : ($current['previous'] ?? null);
		$installed[$id] = [
			'version' => $version,
			'tag' => $tag,
			'previous' => $previous,
			'installedAt' => time(),
			'icon' => $this->findIcon($this->versionDir($id, $version)),
		];
		$this->appConfig->setValueArray(Application::APP_ID, 'installed', $installed);
		$this->prune($id, [$version, $previous]);
		$this->writeLauncherLinks();
		$this->logger->info("NextCraft installed {$id} {$version}");
		return ['id' => $id, 'version' => $version, 'changed' => true];
	}

	/**
	 * Writes craft-links.json next to every installed launcher build: app id => this
	 * Nextcloud's editor URL for each installed Craft app, so the launcher's Open buttons open
	 * them here (apps that aren't installed keep their download buttons). Called again on each
	 * launcher page view, so the addresses follow the host the page was opened on (occ may not
	 * know it).
	 */
	public function writeLauncherLinks(): void {
		$installed = $this->installed();
		$links = [];
		foreach ($installed as $id => $entry) {
			if (!($this->catalog->app($id)['launcher'] ?? false)) {
				$links[$id] = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.editor.show', ['app' => $id]);
			}
		}
		foreach ($installed as $id => $entry) {
			if (!($this->catalog->app($id)['launcher'] ?? false)) {
				continue;
			}
			foreach (array_filter([$entry['version'], $entry['previous'] ?? null]) as $version) {
				$dir = $this->versionDir($id, $version);
				$json = json_encode((object)$links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
				if (is_dir($dir) && @file_get_contents($dir . '/craft-links.json') !== $json) {
					file_put_contents($dir . '/craft-links.json', $json);
				}
			}
		}
	}

	/** Switches $id back to its previous version, if that is still on disk. */
	public function rollback(string $id): string {
		$installed = $this->installed();
		$entry = $installed[$id] ?? throw new \InvalidArgumentException("{$id} is not installed");
		$previous = $entry['previous'] ?? null;
		if ($previous === null || !is_file($this->versionDir($id, $previous) . '/index.html')) {
			throw new \RuntimeException("{$id} has no previous version to go back to");
		}
		$installed[$id] = array_merge($entry, ['version' => $previous, 'previous' => $entry['version'], 'installedAt' => time()]);
		$this->appConfig->setValueArray(Application::APP_ID, 'installed', $installed);
		$this->writeLauncherLinks();
		return $previous;
	}

	public function remove(string $id): void {
		$installed = $this->installed();
		unset($installed[$id]);
		$this->appConfig->setValueArray(Application::APP_ID, 'installed', $installed);
		$this->deleteTree($this->craftDir() . '/' . $id);
		$this->writeLauncherLinks();
		try {
			$folder = $this->appData->getFolder('releases');
			foreach ($folder->getDirectoryListing() as $file) {
				if (str_starts_with($file->getName(), $id . '-')) {
					$file->delete();
				}
			}
		} catch (NotFoundException) {
		}
	}

	/**
	 * Re-creates app folders that are missing (a NextCraft upgrade replaces the app directory),
	 * from the zips kept in app data, downloading again only when the cache is gone.
	 * @return string[] ids restored
	 */
	public function restoreMissing(): array {
		$restored = [];
		foreach ($this->installed() as $id => $entry) {
			if (is_file($this->versionDir($id, $entry['version']) . '/index.html')) {
				continue;
			}
			try {
				$file = $this->appData->getFolder('releases')->getFile($id . '-' . $entry['version'] . '.zip');
				$tmp = $this->tempManager->getTemporaryFile('.zip');
				file_put_contents($tmp, $file->read());
				$this->unpack($id, $entry['version'], $tmp);
				@unlink($tmp);
			} catch (NotFoundException) {
				$this->install($id, $entry['tag'] ?? null, true);
			}
			$restored[] = $id;
		}
		$this->writeLauncherLinks();
		return $restored;
	}

	private function verify(array $release, string $file): void {
		if ($release['sumsUrl'] === null) {
			throw new \RuntimeException("{$release['zipName']}: the release has no SHA256SUMS.txt, refusing to install an unverified build");
		}
		$expected = null;
		foreach (preg_split('/\R/', $this->releases->fetchText($release['sumsUrl'])) as $line) {
			if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m) && trim($m[2]) === $release['zipName']) {
				$expected = strtolower($m[1]);
			}
		}
		if ($expected === null) {
			throw new \RuntimeException("{$release['zipName']} is not listed in SHA256SUMS.txt");
		}
		$actual = hash_file('sha256', $file);
		if (!hash_equals($expected, (string)$actual)) {
			throw new \RuntimeException("{$release['zipName']}: checksum mismatch (expected {$expected}, got {$actual})");
		}
	}

	/** Extracts the zip's single top-level folder into craft/<id>/<version>/ and injects the shim. */
	private function unpack(string $id, string $version, string $zipFile): void {
		$zip = new \ZipArchive();
		if ($zip->open($zipFile) !== true) {
			throw new \RuntimeException('Not a zip file');
		}
		$target = $this->versionDir($id, $version);
		$staging = $target . '.partial';
		$this->deleteTree($staging);
		if (!@mkdir($staging, 0755, true) && !is_dir($staging)) {
			throw new \RuntimeException("Cannot create {$staging}: is the app folder writable by the web server?");
		}
		try {
			$prefix = null;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
					throw new \RuntimeException("Unsafe path in zip: {$name}");
				}
				$top = explode('/', $name, 2)[0];
				$prefix ??= $top;
				if ($top !== $prefix) {
					$prefix = '';
				}
			}
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				$relative = $prefix !== '' ? substr($name, strlen($prefix) + 1) : $name;
				if ($relative === '' || $relative === false) {
					continue;
				}
				$dest = $staging . '/' . $relative;
				if (str_ends_with($name, '/')) {
					@mkdir($dest, 0755, true);
					continue;
				}
				@mkdir(dirname($dest), 0755, true);
				$in = $zip->getStream($name);
				$out = fopen($dest, 'wb');
				if ($in === false || $out === false) {
					throw new \RuntimeException("Cannot extract {$name}");
				}
				stream_copy_to_stream($in, $out);
				fclose($in);
				fclose($out);
			}
		} finally {
			$zip->close();
		}

		$index = $staging . '/index.html';
		$html = @file_get_contents($index);
		if ($html === false) {
			$this->deleteTree($staging);
			throw new \RuntimeException('The web build has no index.html');
		}
		file_put_contents($index, $this->injectShim($html));
		$this->fixPackaging($staging);

		$this->deleteTree($target);
		if (!rename($staging, $target)) {
			throw new \RuntimeException("Cannot move {$staging} into place");
		}
	}

	/**
	 * Works around packaging mistakes seen in released web builds, so an affected release still
	 * starts. PhotoCraft 0.17–0.19 ship app/<name>.js importing ./snippets/… while snippets/ sits
	 * at the site root (storytold/photocraft#10590): copy it next to the script that needs it.
	 */
	private function fixPackaging(string $dir): void {
		if (!is_dir($dir . '/snippets')) {
			return;
		}
		foreach (glob($dir . '/*/*.js') ?: [] as $script) {
			$sub = dirname($script);
			if (!is_dir($sub . '/snippets') && str_contains((string)file_get_contents($script), './snippets/')) {
				$this->copyTree($dir . '/snippets', $sub . '/snippets');
				$this->logger->info('NextCraft: copied snippets/ into ' . basename($sub) . '/ (upstream packaging fix)');
			}
		}
	}

	private function copyTree(string $from, string $to): void {
		@mkdir($to, 0755, true);
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
			$target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
			$item->isDir() ? @mkdir($target, 0755, true) : copy($item->getPathname(), $target);
		}
	}

	public function injectShim(string $html): string {
		if (str_contains($html, 'nextcraft-shim.js')) {
			return $html;
		}
		$count = 0;
		$html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . "\n  " . self::SHIM_TAG, $html, 1, $count);
		return $count === 1 ? $html : self::SHIM_TAG . "\n" . $html;
	}

	private function cacheZip(string $id, string $version, string $file): void {
		try {
			$folder = $this->appData->getFolder('releases');
		} catch (NotFoundException) {
			$folder = $this->appData->newFolder('releases');
		}
		$name = $id . '-' . $version . '.zip';
		$stream = fopen($file, 'rb');
		try {
			$folder->newFile($name)->putContent($stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	/** Deletes versions of $id other than $keep, on disk and in the zip cache. */
	private function prune(string $id, array $keep): void {
		$keep = array_filter($keep);
		foreach (glob($this->craftDir() . '/' . $id . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (!in_array(basename($dir), $keep, true)) {
				$this->deleteTree($dir);
			}
		}
		try {
			foreach ($this->appData->getFolder('releases')->getDirectoryListing() as $file) {
				$name = $file->getName();
				if (str_starts_with($name, $id . '-') && !in_array(substr($name, strlen($id) + 1, -4), $keep, true)) {
					$file->delete();
				}
			}
		} catch (NotFoundException) {
		}
	}

	private function findIcon(string $dir): ?string {
		foreach (['favicon.svg', 'icon-256.png', 'favicon.png', 'icon.png', 'favicon.ico'] as $candidate) {
			if (is_file($dir . '/' . $candidate)) {
				return $candidate;
			}
		}
		$html = @file_get_contents($dir . '/index.html') ?: '';
		if (preg_match('/<link[^>]+rel="[^"]*icon[^"]*"[^>]+href="([^"]+)"/i', $html, $m)
			&& !str_contains($m[1], '..') && !str_contains($m[1], ':') && is_file($dir . '/' . ltrim($m[1], './'))) {
			return ltrim($m[1], './');
		}
		return null;
	}

	private function safeSegment(string $value): string {
		if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $value)) {
			throw new \RuntimeException("Unexpected version string: {$value}");
		}
		return $value;
	}

	private function deleteTree(string $dir): void {
		if (!file_exists($dir) && !is_link($dir)) {
			return;
		}
		if (is_file($dir) || is_link($dir)) {
			@unlink($dir);
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $file) {
			$file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}
		@rmdir($dir);
	}
}
