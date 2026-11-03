<?php

declare(strict_types=1);

namespace OCA\NextCraft\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Runs on uninstall (and, by Nextcloud's design, on disable). */
class UnregisterMimeTypes extends MimeTypes implements IRepairStep {
	public function getName(): string {
		return 'NextCraft: unregister MIME types';
	}

	public function run(IOutput $output): void {
		$mappingFile = $this->configFile('mimetypemapping.json');
		$aliasFile = $this->configFile('mimetypealiases.json');
		$mapping = $this->read($mappingFile);
		$aliases = $this->read($aliasFile);
		foreach ($this->ours() as $ext => $type) {
			if (($mapping[$ext] ?? null) === [$type['mime']]) {
				unset($mapping[$ext]);
			}
			if (($aliases[$type['mime']] ?? null) === $type['alias']) {
				unset($aliases[$type['mime']]);
			}
		}
		$this->write($mappingFile, $mapping);
		$this->write($aliasFile, $aliases);
		$output->info('Removed NextCraft file type mappings.');
	}
}
