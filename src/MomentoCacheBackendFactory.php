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
    private $authProvider;
    private $cacheNamePrefix;
    private $tagsCacheId = '_momentoTags';
    private $tagsCacheName;

    public function __construct() {
        $settings = Settings::get('momento_cache', []);
        $this->cacheNamePrefix =
            array_key_exists('cache_name_prefix', $settings) ? $settings['cache_name_prefix'] : "drupal-";
        $authToken = array_key_exists('api_token', $settings) ? $settings['api_token'] : getenv("MOMENTO_API_TOKEN");
        $this->authProvider = new StringMomentoTokenProvider($authToken);
        $this->tagsCacheName = "$this->cacheNamePrefix$this->tagsCacheId";
    }

    public function get($bin)
    {
        $this->getMomentoClient();

        if (
            ! $this->caches
            || ($this->cacheListTimespamp && time() - $this->cacheListTimespamp > $this->cacheListGoodForSeconds)
        ) {
            $this->populateCacheList();
        }
        $this->checkTagsCache();

        $cacheName = $this->cacheNamePrefix . $bin;
        return new MomentoCacheBackend(
            $bin,
            $this->client,
            !in_array($cacheName, $this->caches),
            $cacheName,
            $this->tagsCacheName
        );
    }

    public function getForTagInvalidator() {
        $this->getMomentoClient();
        return new MomentoCacheBackend(
            'fakebin', $this->client, false, 'fakebin', $this->tagsCacheName
        );
    }

    private function getMomentoClient() {
        if (!$this->client) {
            $this->client = new CacheClient(Laptop::latest(), $this->authProvider, 30);
        }
    }

    private function populateCacheList() {
        $this->caches = [];
        $this->cacheListTimespamp = time();
        $listResponse = $this->client->listCaches();
        if ($listResponse->asSuccess()) {
            foreach ($listResponse->asSuccess()->caches() as $cache) {
                $this->caches[] = $cache->name();
            }
        }
    }

    private function checkTagsCache() {
        if (!in_array($this->tagsCacheName, $this->caches)) {
            $createResponse = $this->client->createCache($this->tagsCacheName);
            if ($createResponse->asError()) {
                $this->getLogger('momento_cache')->error(
                    "Error creating tags cache $this->tagsCacheName: " . $createResponse->asError()->message()
                );
            }
        }
    }
}
