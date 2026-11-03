<?php

declare(strict_types=1);

namespace OCA\NextCraft\Command;

use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Remove extends Command {
	public function __construct(private CatalogService $catalog, private InstallService $installer) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextcraft:remove')
			->setDescription('Remove installed Craft apps (files and cached releases)')
			->addArgument('apps', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'App ids');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		foreach ($input->getArgument('apps') as $id) {
			if (!$this->catalog->exists($id)) {
				$output->writeln("<error>Unknown app {$id}</error>");
				continue;
			}
			$this->installer->remove($id);
			$output->writeln("{$id}: removed");
		}
		return 0;
	}
}
