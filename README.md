# Momento Cache Backend For Drupal

## Prerequisites

A Momento API Token is required. You can generate one using the [Momento Console](https://console.gomomento.com/).

## Installation

Add the module with `composer require momentohq/drupal-cache:dev-main` and enable in Drupal.

Add the following to your `settings.php` file, replacing `<YOUR_MOMENTO_TOKEN>` with the token you generated in the console.

```php
$settings['cache']['default'] = 'cache.backend.momento_cache';
$settings['momento_cache']['auth_token'] = <YOUR_MOMENTO_TOKEN>;
```
