<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\momento_cache\Client\MomentoClientFactory;

/**
 *
 */
class MomentoCacheBackendFactory implements CacheFactoryInterface {

    private $momentoFactory;
    private $checksumProvider;

    private static $cacheName;
    private $client;
    private $backends = [];

    /**
     *
     */
    public function __construct(
        MomentoClientFactory $momento_factory,
        CacheTagsChecksumInterface $checksum_provider
    ) {
        $this->momentoFactory = $momento_factory;
        $this->checksumProvider = $checksum_provider;
        $this->client = $this->momentoFactory->get();
        $settings = Settings::get('momento_cache', []);
        static::$cacheName = array_key_exists('cache_name', $settings) ?
            $settings['cache_name'] : getenv("MOMENTO_CACHE_NAME");
    }

    /**
     *
     */
    public static function getCacheName() {
        return static::$cacheName ?? '';
    }

    /**
     *
     */
    public function get($bin) {
        if (array_key_exists($bin, $this->backends)) {
            return $this->backends[$bin];
        }

        $backend = new MomentoCacheBackend(
            $bin,
            $this->client,
            $this->checksumProvider
        );
        $this->backends[$bin] = $backend;
        return $backend;
    }

}
