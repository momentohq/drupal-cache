<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\CacheClient;
use Momento\Config\Configurations\Laptop;

class MomentoCacheBackendFactory implements CacheFactoryInterface {

    use LoggerChannelTrait;

    private $client;
    private $caches;
    private $cacheListGoodForSeconds = 3;
    private $cacheListTimespamp;

    private function populateCacheList($bin) {
        $this->caches = [];
        $this->cacheListTimespamp = time();
        $listResponse = $this->client->listCaches();
        if ($listResponse->asSuccess()) {
            foreach ($listResponse->asSuccess()->caches() as $cache) {
                $this->caches[] = $cache->name();
            }
        }
    }

    public function get($bin)
    {
        if (!$this->client) {
            $settings = Settings::get('momento_cache');
            $authToken = $settings['auth_token'];
            $authProvider = new StringMomentoTokenProvider($authToken);
            $this->client = new CacheClient(Laptop::latest(), $authProvider, 30);
        }

        if (
            ! $this->caches
            || ($this->cacheListTimespamp && time() - $this->cacheListTimespamp > $this->cacheListGoodForSeconds)
        ) {
            $this->populateCacheList($bin);
        }

        return new MomentoCacheBackend($bin, $this->client, !in_array($bin, $this->caches));
    }
}
