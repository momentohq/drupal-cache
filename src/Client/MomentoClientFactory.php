<?php

namespace Drupal\momento_cache\Client;

use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
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
        return $this->client;
    }
}
