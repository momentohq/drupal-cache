<?php

namespace Drupal\momento_cache\Client;

use Drupal\Core\DependencyInjection\ContainerNotInitializedException;
use Drupal\Core\Site\Settings;
use Drupal\Core\Logger\LoggerChannelTrait;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\CacheClient;
use Momento\Config\Configurations\Laptop;

class MomentoClientFactory {

    use LoggerChannelTrait;

    private $authProvider;
    private $cachePrefix;
    private $client;
    private $containerCacheCreated = false;

    public function __construct() {
        $settings = Settings::get('momento_cache', []);
        $authToken = array_key_exists('api_token', $settings) ?
            $settings['api_token'] : getenv("MOMENTO_API_TOKEN");
        $this->cachePrefix = array_key_exists('cache_name_prefix', $settings) ?
            $settings['cache_name_prefix'] : 'drupal-';
        $this->authProvider = new StringMomentoTokenProvider($authToken);
    }

    public function get() {
        if (!$this->client) {
            $this->client = new CacheClient(Laptop::latest(), $this->authProvider, 30);
            // Ensure "container" cache exists
            if (!$this->containerCacheCreated) {
                $createResponse = $this->client->createCache($this->cachePrefix . 'container');
                if ($createResponse->asSuccess()) {
                    $this->containerCacheCreated = true;
                } elseif ($createResponse->asError()) {
                    try {
                        // TODO: unify logging interface and direct this message to the logfile.
                        $this->getLogger('momento_cache')->error(
                            "Error getting Momento client: " . $createResponse->asError()->message()
                        );
                    } catch(ContainerNotInitializedException $e) {
                        // we don't have access to getLogger() until the container is initialized
                    }
                }
            }
        }
        return $this->client;
    }
}
