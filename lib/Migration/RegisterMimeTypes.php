<?php

declare(strict_types=1);

namespace OCA\NextCraft\Migration;

use OCA\NextCraft\BackgroundJob\UpdateFileCacheMimeTypes;
use OCA\NextCraft\Service\CatalogService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RegisterMimeTypes extends MimeTypes implements IRepairStep {
	public function __construct(CatalogService $catalog, IMimeTypeLoader $mimeTypeLoader, private IJobList $jobList) {
		parent::__construct($catalog, $mimeTypeLoader);
	}

	public function getName(): string {
		return 'NextCraft: register MIME types for Craft file formats';
	}

	public function run(IOutput $output): void {
		$mappingFile = $this->configFile('mimetypemapping.json');
		$aliasFile = $this->configFile('mimetypealiases.json');
		$mapping = $this->read($mappingFile);
		$aliases = $this->read($aliasFile);
		foreach ($this->ours() as $ext => $type) {
			$mapping[$ext] = [$type['mime']];
			$aliases[$type['mime']] = $type['alias'];
		}
		$this->write($mappingFile, $mapping);
		$this->write($aliasFile, $aliases);

		// Files already in the file cache keep their old type until the background job updates them.
		$this->jobList->add(UpdateFileCacheMimeTypes::class);
		// Not running maintenance:mimetype:update-js on purpose: it rewrites core/js/mimetypelist.js,
		// which then fails Nextcloud's code integrity check. The types work without it; their files
		// show a generic icon.
		$output->info('Registered ' . count($this->ours()) . ' file types.');
	}
}
