# Momento Cache Backend For Drupal

## Prerequisites

A Momento API Token is required. You can generate one using the [Momento Console](https://console.gomomento.com/).

## Installation

Add the module with `composer require 'momentohq/drupal-cache:v0.2.1'`. You may need to edit your `composer.json` to set `minimum-stability` to `dev`.

Enable the module in your Drupal administrator interface.

Add the following to your `settings.php` file: 

```php
$settings['cache']['default'] = 'cache.backend.momento_cache';
$settings['momento_cache']['api_token'] = '<YOUR_MOMENTO_TOKEN>';
$settings['momento_cache']['cache_name_prefix'] = '<YOUR_CACHE_NAME_PREFIX>';
```

Replace `<YOUR_MOMENTO_TOKEN>` with the token you generated in the console. You may also use an environment variable named `MOMENTO_API_TOKEN` to pass your API token to the Momento cache backend. The module will check for the token in the settings file first and will fall back to the environment variable if a token is not found in the settings.

Replace `<YOUR_CACHE_NAME_PREFIX>` with a string to be prepended to the names of the underlying caches. The prefix will prevent cache name collisions when multiple Drupal installs are backed by the same Momento account. If you don't provide a prefix in settings, the prefix "drupal-" is used.

## Settings

$settings['bootstrap_container_definition'] = [
    'parameters'=>[],
    'services' => [
        'database' => [
            'class' => 'Drupal\Core\Database\Connection',
            'factory' => 'Drupal\Core\Database\Database::getConnection',
            'arguments' => ['default'],
        ],
        'momento_cache.factory' => [
            'class' => 'Drupal\momento_cache\Client\MomentoClientFactory'
        ],
        'momento_cache.timestamp.invalidator.bin' => [
            'class' => 'Drupal\momento_cache\Invalidator\MomentoTimestampInvalidator',
            'arguments' => ['@momento_cache.factory']
        ],
        'momento_cache.backend.cache.container' => [
            'class' => 'Drupal\momento_cache\MomentoCacheBackend',
            'factory' => ['@momento_cache.factory', 'get'],
            'arguments' => ['container']
        ],
        'cache_tags_provider.container' => [
            'class' => 'Drupal\Core\Cache\DatabaseCacheTagsChecksum',
            'arguments' => ['@database']
        ],
        'cache.container' => [
            'class' => 'Drupal\momento_cache\MomentoCacheBackend',
            'arguments' => ['container', '@momento_cache.backend.cache.container', '@cache_tags_provider.container', 'momento_cache.timestamp.invalidator.bin']
        ]
    ]
];
