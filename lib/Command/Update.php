<?php

declare(strict_types=1);

namespace OCA\NextCraft\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Update extends Install {
	protected function configure(): void {
		$this->setName('nextcraft:update')
			->setDescription('Update installed Craft apps to their latest release, or go back with --rollback')
			->addArgument('apps', InputArgument::IS_ARRAY, 'App ids (default: every installed app)')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Same as no arguments')
			->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Update to this tag instead of the latest (single app only)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Reinstall even when up to date')
			->addOption('rollback', null, InputOption::VALUE_NONE, 'Switch back to the previously installed version');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ids = $input->getArgument('apps') === [] ? array_keys($this->installer->installed()) : $this->selection($input, true);
		if ($input->getOption('rollback')) {
			foreach ($ids as $id) {
				try {
					$output->writeln("{$id}: back to " . $this->installer->rollback($id));
				} catch (\Throwable $e) {
					$output->writeln("{$id}: <error>" . $e->getMessage() . '</error>');
				}
			}
			return 0;
		}
		if ($ids === []) {
			$output->writeln('Nothing installed yet: occ nextcraft:install --all');
			return 0;
		}
		return $this->installEach($ids, $input, $output);
	}
}
