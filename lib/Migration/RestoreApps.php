<?php

declare(strict_types=1);

namespace OCA\NextCraft\Migration;

use OCA\NextCraft\Service\InstallService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** After a NextCraft upgrade replaced the app folder, unpack the installed Craft apps again. */
class RestoreApps implements IRepairStep {
	public function __construct(private InstallService $installer) {
	}

	public function getName(): string {
		return 'NextCraft: restore installed Craft apps';
	}

	public function run(IOutput $output): void {
		try {
			$restored = $this->installer->restoreMissing();
			$output->info($restored === [] ? 'All Craft apps are in place.' : 'Restored: ' . implode(', ', $restored));
		} catch (\Throwable $e) {
			$output->warning('Could not restore Craft apps: ' . $e->getMessage() . ' (run occ nextcraft:update --force)');
		}
	}
}
