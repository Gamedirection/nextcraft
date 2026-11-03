<?php

declare(strict_types=1);

namespace OCA\NextCraft\BackgroundJob;

use OCA\NextCraft\Service\CatalogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IMimeTypeLoader;

/**
 * Gives files already in the file cache NextCraft's MIME types. Each extension is a scan of the
 * whole file cache, which takes minutes on large instances, so it runs once in the background
 * instead of while the app is being enabled.
 */
class UpdateFileCacheMimeTypes extends QueuedJob {
	public function __construct(ITimeFactory $time, private CatalogService $catalog, private IMimeTypeLoader $mimeTypeLoader) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$dist = json_decode((string)file_get_contents(\OC::$SERVERROOT . '/resources/config/mimetypemapping.dist.json'), true) ?: [];
		foreach ($this->catalog->mimetypes() as $ext => $type) {
			if (!isset($dist[$ext])) {
				$this->mimeTypeLoader->updateFilecache($ext, $this->mimeTypeLoader->getId($type['mime']));
			}
		}
	}
}
