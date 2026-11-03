<?php

declare(strict_types=1);

namespace OCA\NextCraft\Command;

use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Install extends Command {
	public function __construct(protected CatalogService $catalog, protected InstallService $installer) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextcraft:install')
			->setDescription('Install Craft apps from their latest GitHub release (or --tag)')
			->addArgument('apps', InputArgument::IS_ARRAY, 'App ids, e.g. wordcraft gridcraft')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'All apps in the catalog')
			->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Install this release tag (single app only)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Reinstall even when the version is already installed')
			->addOption('zip', null, InputOption::VALUE_REQUIRED, 'Install a local web build zip instead of a release (single app; needs --build-version)')
			->addOption('build-version', null, InputOption::VALUE_REQUIRED, 'Version label for --zip, e.g. 0.13.0-selfhosted');
	}

	protected function selection(InputInterface $input, bool $defaultInstalled): array {
		$ids = $input->getArgument('apps');
		if ($input->getOption('all')) {
			return array_keys($this->catalog->apps());
		}
		if ($ids === [] && $defaultInstalled) {
			return array_keys($this->installer->installed());
		}
		foreach ($ids as $id) {
			if (!$this->catalog->exists($id)) {
				throw new \InvalidArgumentException("Unknown app {$id}; known: " . implode(', ', array_keys($this->catalog->apps())));
			}
		}
		return $ids;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ids = $this->selection($input, false);
		if ($input->hasOption('zip') && $input->getOption('zip') !== null) {
			$zip = (string)$input->getOption('zip');
			$version = (string)$input->getOption('build-version');
			if (count($ids) !== 1 || $version === '' || !is_file($zip)) {
				$output->writeln('<error>--zip needs one app, an existing file and --build-version</error>');
				return 1;
			}
			$result = $this->installer->installZip($ids[0], $version, $zip);
			$output->writeln("{$ids[0]}: installed local build {$result['version']}");
			return 0;
		}
		if ($ids === []) {
			$output->writeln('<error>Name the apps to install, or use --all</error>');
			return 1;
		}
		return $this->installEach($ids, $input, $output);
	}

	protected function installEach(array $ids, InputInterface $input, OutputInterface $output): int {
		$tag = $input->getOption('tag');
		if ($tag !== null && count($ids) !== 1) {
			$output->writeln('<error>--tag needs exactly one app</error>');
			return 1;
		}
		$failed = 0;
		foreach ($ids as $id) {
			$output->write("{$id}: ");
			try {
				$result = $this->installer->install($id, $tag, (bool)$input->getOption('force'));
				$output->writeln($result['changed'] ? "installed {$result['version']}" : "{$result['version']} already installed");
			} catch (\Throwable $e) {
				$failed++;
				$output->writeln('<error>' . $e->getMessage() . '</error>');
			}
		}
		return $failed === 0 ? 0 : 1;
	}
}
