# Momento Cache Backend For Drupal

## Prerequisites

A Momento API Token is required. You can generate one using the [Momento Console](https://console.gomomento.com/).

The Momento Cache module uses [Momento's PHP SDK](https://docs.momentohq.com/cache/develop/sdks/php) internally. While installing the Drupal module will automatically install the SDK for you, you will need to install and enable [the PHP gRPC extension](https://github.com/grpc/grpc/blob/master/src/php/README.md) separately for the SDK to function.

### Drupal and Momento Rate Limiting

Momento's free tier limits accounts' transactions per second (TPS) and throughput (KiBps), and requests that exceed those limits are throttled. Drupal's usage of the cache backend is significantly higher than the default limits, so prior to using the integration please reach out to `support@momentohq.com` to raise your account limits. You can check the Drupal dblog and/or the logfile you configure in the settings (instructions below) for rate limiting error messages.

## Installation and Configuration

Add the module with `composer require 'momentohq/drupal-cache:0.5.0'`.

Enable the `momento_cache` module in your Drupal administrator interface or using `drush en momento_cache`.  

Add the following to your `settings.php` file: 

```php
$settings['cache']['default'] = 'cache.backend.momento_cache';
$settings['momento_cache']['api_token'] = '<YOUR_MOMENTO_TOKEN>';
$settings['momento_cache']['cache_name'] = '<YOUR_CACHE_NAME>';
$settings['momento_cache']['logfile'] = '<YOUR_LOG_FILE_PATH>';
```

Replace `<YOUR_MOMENTO_TOKEN>` with the token you generated in the console. You may also use an environment variable named `MOMENTO_API_TOKEN` to pass your API token to the Momento cache backend. If both are supplied, the settings file takes precedence.

Replace `<YOUR_CACHE_NAME>` with the name of your underlying Momento cache. You may also use an environment variable named `MOMENTO_CACHE_NAME` to pass this value. If both are supplied, the settings file takes precedence. **You must create the cache before using the module.** If the cache doesn't exist, errors are written to the Drupal dblog as well as the logfile configured in your settings, if you have specified one.

Replace `<YOUR_LOG_FILE_PATH>` with the path of a file writable by your Drupal server or with `null` if you want to suppress logging. This logfile is used for logging and timing requests as they are handled by the module. Please be aware that this file will grow quickly over time, so if you choose to enable it long-term, you should probably use `logrotate` or some similar utility to keep it from growing out of control.

The example above uses Momento for all Drupal caches. If you prefer to use Momento for specific cache bins, you may assign them individually as well: `$settings['cache']['bins']['render'] = 'cache.backend.momento_cache'`

Finally, add the following to `settings.php`:

```php
$class_loader->addPsr4('Drupal\\momento_cache\\', 'modules/contrib/drupal-cache/src');
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
            'arguments' => ['container', '@momento_cache.backend.cache.container', '@cache_tags_provider.container']
        ]
    ]
];
```
