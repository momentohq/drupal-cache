<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Drupal\momento_cache\Client\MomentoClientFactory;

class MomentoCacheBackendFactory implements CacheFactoryInterface {

    use LoggerChannelTrait;

    private $momentoFactory;
    private $checksumProvider;
    private $timestampInvalidator;

    private static $cacheName = 'momento-drupal';
    private $client;
    private $caches;
    private $cacheListGoodForSeconds = 3;
    private $cacheListTimespamp;
    private $authProvider;
    private $backends = [];


    public function __construct(
        MomentoClientFactory $momento_factory,
        CacheTagsChecksumInterface $checksum_provider
    ) {
        $this->momentoFactory = $momento_factory;
        $this->checksumProvider = $checksum_provider;
        $this->client = $this->momentoFactory->get();
    }

    public static function getCacheName() {
        $settings = Settings::get('momento_cache', []);
        $cacheNamePrefix = array_key_exists('cache_name_prefix', $settings) ?
            $settings['cache_name_prefix'] : getenv("MOMENTO_CACHE_NAME_PREFIX");
        return $cacheNamePrefix . static::$cacheName;
    }

    public function get($bin)
    {
        if (array_key_exists($bin, $this->backends)) {
            return $this->backends[$bin];
        }

        if (
            ! $this->caches
            || ($this->cacheListTimespamp && time() - $this->cacheListTimespamp > $this->cacheListGoodForSeconds)
        ) {
            error_log("backend for $bin populating cache list");
            $this->populateCacheList();
        }

        $cacheName = static::getCacheName();
        if (!in_array($cacheName, $this->caches)) {
            $this->createCache($cacheName);
        }
        $backend = new MomentoCacheBackend(
            $bin,
            $this->client,
            $this->checksumProvider
        );
        $this->backends[$bin] = $backend;
        return $backend;
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

    private function createCache($cacheName) {
        $createResponse = $this->client->createCache($cacheName);
        if ($createResponse->asError()) {
            $this->getLogger('momento_cache')->error(
                "Error creating cache $cacheName : " . $createResponse->asError()->message()
            );
        } elseif ($createResponse->asSuccess()) {
            $this->getLogger('momento_cache')->info("Created cache $cacheName");
        }
    }
}
