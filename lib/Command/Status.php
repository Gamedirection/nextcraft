<?php

declare(strict_types=1);

namespace OCA\NextCraft\Command;

use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use OCA\NextCraft\Service\ReleaseService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	public function __construct(private CatalogService $catalog, private InstallService $installer, private ReleaseService $releases) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextcraft:status')
			->setDescription('List the Craft apps, installed versions and (with --check) the latest releases')
			->addOption('check', 'c', InputOption::VALUE_NONE, 'Ask GitHub for the latest release of each app');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$installed = $this->installer->installed();
		$table = new Table($output);
		$table->setHeaders(['App', 'Installed', 'Previous', 'Latest']);
		$updates = 0;
		foreach ($this->catalog->apps() as $id => $app) {
			$latest = '';
			if ($input->getOption('check')) {
				try {
					$latest = $this->releases->release($app['repo'], null, true)['version'];
				} catch (\Throwable $e) {
					$latest = 'error: ' . $e->getMessage();
				}
				if (isset($installed[$id]) && $latest !== $installed[$id]['version'] && !str_starts_with($latest, 'error')) {
					$latest .= ' (update)';
					$updates++;
				}
			}
			$table->addRow([$id, $installed[$id]['version'] ?? '-', $installed[$id]['previous'] ?? '', $latest]);
		}
		$table->render();
		if ($input->getOption('check')) {
			$output->writeln($updates === 0 ? 'Everything installed is up to date.' : "{$updates} update(s) available: occ nextcraft:update");
		}
		return 0;
	}
}
