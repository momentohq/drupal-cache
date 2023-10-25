<?php

namespace Drupal\momento_cache\Client;

use Drupal\Core\Site\Settings;
use Drupal\momento_cache\MomentoCacheBackendFactory;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\CacheClient;
use Momento\Config\Configurations\Laptop;

class MomentoClientFactory {

    private $authProvider;
    private $client;
    private $createdCaches = [];

    public function __construct() {
        $settings = Settings::get('momento_cache', []);
        $authToken = array_key_exists('api_token', $settings) ?
            $settings['api_token'] : getenv("MOMENTO_API_TOKEN");
        $this->authProvider = new StringMomentoTokenProvider($authToken);
    }

    public function get() {
        $config = Laptop::latest();
        $config = $config->withTransportStrategy(
            $config->getTransportStrategy()->withGrpcConfig(
                $config->getTransportStrategy()->getGrpcConfig()->withForceNewChannel(true)
            )
        );

        if (!$this->client) {
            $this->client = new CacheClient($config, $this->authProvider, 30);
        }
        $cacheName = MomentoCacheBackendFactory::getCacheName();
        error_log("Got cache $cacheName");

//        $listResponse = $this->client->listCaches();
//        if ($listResponse->asSuccess()) {
//            foreach ($listResponse->asSuccess()->caches() as $cache) {
//                error_log("client got cache: " . $cache->name());
//            }
//        } elseif ($listResponse->asError()) {
//            error_log("client got error: " . $listResponse->asError()->message());
//        }

        error_log("Created caches list is: " . implode(', ', $this->createdCaches));
        if (!array_key_exists($cacheName, $this->createdCaches)) {
            $createResponse = $this->client->createCache($cacheName);
            if ($createResponse->asError()) {
                error_log(
                    "Error creating cache $cacheName : " . $createResponse->asError()->message()
                );
            } elseif ($createResponse->asSuccess()) {
                $this->createdCaches[] = $cacheName;
                error_log("Created cache $cacheName");
            } elseif ($createResponse->asAlreadyExists()) {
                error_log("skipping create for existing cache: $cacheName");
            }
        }
        return $this->client;
    }
}
