<?php

declare(strict_types=1);

namespace OCA\NextCraft\BackgroundJob;

use OCA\NextCraft\AppInfo\Application;
use OCA\NextCraft\Service\CatalogService;
use OCA\NextCraft\Service\InstallService;
use OCA\NextCraft\Service\ReleaseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily: looks for new Craft releases. Installs them when auto-update is on, otherwise tells the
 * admins once per new version.
 */
class UpdateCheck extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private CatalogService $catalog,
		private InstallService $installer,
		private ReleaseService $releases,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$auto = $this->appConfig->getValueBool(Application::APP_ID, 'auto_update', false);
		$notified = $this->appConfig->getValueArray(Application::APP_ID, 'notified', []);
		foreach ($this->installer->installed() as $id => $entry) {
			$app = $this->catalog->app($id);
			if ($app === null) {
				continue;
			}
			try {
				$latest = $this->releases->release($app['repo'], null, true)['version'];
			} catch (\Throwable $e) {
				$this->logger->info("NextCraft update check for {$id} failed: " . $e->getMessage());
				continue;
			}
			if ($latest === $entry['version']) {
				continue;
			}
			if ($auto) {
				try {
					$this->installer->install($id);
				} catch (\Throwable $e) {
					$this->logger->warning("NextCraft auto-update of {$id} failed: " . $e->getMessage());
				}
				continue;
			}
			if (($notified[$id] ?? null) !== $latest) {
				$this->notifyAdmins($app['name'], $latest);
				$notified[$id] = $latest;
			}
		}
		$this->appConfig->setValueArray(Application::APP_ID, 'notified', $notified);
	}

	private function notifyAdmins(string $name, string $version): void {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}
		foreach ($admins->getUsers() as $admin) {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($admin->getUID())
				->setDateTime(new \DateTime())
				->setObject('update', $name . '-' . $version)
				->setSubject('update_available', ['app' => $name, 'version' => $version]);
			$this->notifications->notify($notification);
		}
	}
}
