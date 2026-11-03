<?php

declare(strict_types=1);

namespace OCA\NextCraft\Service;

use OCA\NextCraft\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Reads Craft app releases from the GitHub REST API: the latest (or a given) tag, its web build
 * zip and its SHA256SUMS.txt.
 */
class ReleaseService {
	private const API = 'https://api.github.com/repos/';
	private ICache $cache;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '-releases');
	}

	/**
	 * @return array{tag: string, version: string, published: ?string, zipName: string, zipUrl: string, zipSize: int, sumsUrl: ?string}
	 * @throws \RuntimeException when there is no release or it has no web build
	 */
	public function release(string $repo, ?string $tag = null, bool $fresh = false): array {
		$key = $repo . '@' . ($tag ?? 'latest');
		if (!$fresh && ($cached = $this->cache->get($key)) !== null) {
			return $cached;
		}
		$path = $tag === null ? 'releases/latest' : 'releases/tags/' . rawurlencode($tag);
		$data = $this->getJson(self::API . $repo . '/' . $path);

		$zip = null;
		$sums = null;
		foreach ($data['assets'] ?? [] as $asset) {
			$name = (string)($asset['name'] ?? '');
			if (preg_match('/-web-[0-9][^\/]*\.zip$/', $name) && $zip === null) {
				$zip = $asset;
			} elseif ($name === 'SHA256SUMS.txt') {
				$sums = $asset;
			}
		}
		if ($zip === null) {
			throw new \RuntimeException("{$repo} {$data['tag_name']} has no web build");
		}
		$tagName = (string)$data['tag_name'];
		$result = [
			'tag' => $tagName,
			'version' => ltrim($tagName, 'v'),
			'published' => $data['published_at'] ?? null,
			'zipName' => (string)$zip['name'],
			'zipUrl' => (string)$zip['browser_download_url'],
			'zipSize' => (int)($zip['size'] ?? 0),
			'sumsUrl' => $sums['browser_download_url'] ?? null,
		];
		$this->cache->set($key, $result, 3600);
		return $result;
	}

	/** Downloads $url to the local file $target. */
	public function download(string $url, string $target): void {
		$this->clientService->newClient()->get($url, [
			'sink' => $target,
			'timeout' => 600,
			'headers' => $this->headers(false),
		]);
	}

	public function fetchText(string $url): string {
		$response = $this->clientService->newClient()->get($url, ['timeout' => 60, 'headers' => $this->headers(false)]);
		return (string)$response->getBody();
	}

	private function getJson(string $url): array {
		try {
			$response = $this->clientService->newClient()->get($url, ['timeout' => 30, 'headers' => $this->headers(true)]);
		} catch (\Throwable $e) {
			if (str_contains($e->getMessage(), '404')) {
				throw new \RuntimeException('No such release: ' . $url, 0, $e);
			}
			if (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), '429')) {
				throw new \RuntimeException('GitHub rate limit reached; set a GitHub token in the NextCraft admin settings', 0, $e);
			}
			throw new \RuntimeException('Could not reach GitHub: ' . $e->getMessage(), 0, $e);
		}
		return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
	}

	private function headers(bool $api): array {
		$headers = ['User-Agent' => 'NextCraft (Nextcloud app)'];
		if ($api) {
			$headers['Accept'] = 'application/vnd.github+json';
			$token = $this->appConfig->getValueString(Application::APP_ID, 'github_token', '');
			if ($token !== '') {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}
		return $headers;
	}
}
