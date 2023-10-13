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

    private $client;
    private $caches;
    private $cacheListGoodForSeconds = 3;
    private $cacheListTimespamp;
    private $authProvider;
    private $cacheNamePrefix;
    private $tagsCacheId = '_momentoTags';
    private $tagsCacheName;


    public function __construct(
        MomentoClientFactory $momento_factory,
        CacheTagsChecksumInterface $checksum_provider
    ) {
        $this->momentoFactory = $momento_factory;
        $this->checksumProvider = $checksum_provider;
        $settings = Settings::get('momento_cache', []);
        $this->cacheNamePrefix = array_key_exists('cache_name_prefix', $settings) ?
            $settings['cache_name_prefix'] : "drupal-";
        $this->client = $this->momentoFactory->get();
    }

    public function get($bin)
    {
        if (
            ! $this->caches
            || ($this->cacheListTimespamp && time() - $this->cacheListTimespamp > $this->cacheListGoodForSeconds)
        ) {
            $this->populateCacheList();
        }

        $cacheName = $this->cacheNamePrefix . $bin;
        if (!in_array($cacheName, $this->caches)) {
            $this->createCache($cacheName);
        }
        return new MomentoCacheBackend(
            $bin,
            $this->client,
            $this->checksumProvider
        );
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
