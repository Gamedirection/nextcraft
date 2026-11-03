<?php

declare(strict_types=1);

namespace OCA\NextCraft\Migration;

use OCA\NextCraft\Service\CatalogService;
use OCP\Files\IMimeTypeLoader;

/**
 * Adds or removes NextCraft's extension → MIME type mappings in config/mimetypemapping.json and
 * config/mimetypealiases.json (the documented way; there is no public API, see
 * https://github.com/nextcloud/server/issues/9192). Only keys NextCraft owns are touched, so
 * mappings other apps added stay.
 */
abstract class MimeTypes {
	public function __construct(protected CatalogService $catalog, protected IMimeTypeLoader $mimeTypeLoader) {
	}

	protected function configFile(string $name): string {
		return rtrim(\OC::$configDir, '/') . '/' . $name;
	}

	protected function read(string $file): array {
		if (!is_file($file)) {
			return [];
		}
		$data = json_decode((string)file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}

	protected function write(string $file, array $data): void {
		if ($data === []) {
			@unlink($file);
			return;
		}
		file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	/** Extensions in the catalog that Nextcloud's own mapping doesn't know yet. */
	protected function ours(): array {
		$dist = $this->read(\OC::$SERVERROOT . '/resources/config/mimetypemapping.dist.json');
		return array_filter($this->catalog->mimetypes(), fn ($ext) => !isset($dist[$ext]), ARRAY_FILTER_USE_KEY);
	}
}
