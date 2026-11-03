<?php

declare(strict_types=1);

namespace OCA\NextCraft\Service;

/**
 * The Craft app catalog (lib/catalog.json): apps, the extensions they open, default handlers
 * and the MIME types NextCraft registers.
 */
class CatalogService {
	private ?array $catalog = null;

	public function all(): array {
		if ($this->catalog === null) {
			$json = file_get_contents(__DIR__ . '/../catalog.json');
			$this->catalog = json_decode($json === false ? '{}' : $json, true, 512, JSON_THROW_ON_ERROR);
		}
		return $this->catalog;
	}

	/** @return array<string, array> app id => metadata */
	public function apps(): array {
		return $this->all()['apps'] ?? [];
	}

	public function app(string $id): ?array {
		return $this->apps()[$id] ?? null;
	}

	public function exists(string $id): bool {
		return $this->app($id) !== null;
	}

	/** @return array<string, string> extension => default handler ('ask', 'builtin' or an app id) */
	public function defaults(): array {
		return $this->all()['defaults'] ?? [];
	}

	/** @return array<string, array{mime: string, alias: string}> */
	public function mimetypes(): array {
		return $this->all()['mimetypes'] ?? [];
	}

	/** Every extension some app opens (without the '*' wildcard). @return string[] */
	public function extensions(): array {
		$exts = [];
		foreach ($this->apps() as $app) {
			foreach ($app['opens'] as $ext) {
				if ($ext !== '*') {
					$exts[$ext] = true;
				}
			}
		}
		$exts = array_keys($exts);
		sort($exts);
		return $exts;
	}

	/** The catalog as the browser needs it: apps (subset of fields) and defaults. */
	public function forClient(array $installed): array {
		$apps = [];
		foreach ($this->apps() as $id => $app) {
			$apps[$id] = [
				'name' => $app['name'],
				'description' => $app['description'],
				'bridge' => $app['bridge'],
				'native' => $app['native'],
				'opens' => $app['opens'],
				'openOnly' => $app['openOnly'],
				'liveSessions' => $app['liveSessions'] ?? false,
				'installed' => isset($installed[$id]),
			];
		}
		return ['apps' => $apps, 'defaults' => $this->defaults()];
	}
}
