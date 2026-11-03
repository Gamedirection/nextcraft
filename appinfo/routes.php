<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'editor#show', 'url' => '/edit/{app}', 'verb' => 'GET'],
		['name' => 'asset#show', 'url' => '/asset/{app}/{version}/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'prefs#set', 'url' => '/prefs', 'verb' => 'POST'],
		['name' => 'admin#status', 'url' => '/admin/status', 'verb' => 'GET'],
		['name' => 'admin#install', 'url' => '/admin/apps/{app}', 'verb' => 'POST'],
		['name' => 'admin#remove', 'url' => '/admin/apps/{app}', 'verb' => 'DELETE'],
		['name' => 'admin#settings', 'url' => '/admin/settings', 'verb' => 'POST'],
	],
];
