<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\momento_cache\Client\MomentoClientFactory;

/**
 * Defines the MomentoCacheBackendFactory service.
 */
class MomentoCacheBackendFactory implements CacheFactoryInterface {

  /**
   * The Momento client factory.
   *
   * @var \Drupal\momento_cache\Client\MomentoClientFactory
   */
  private $momentoFactory;

  /**
   * The cache tags checksum provider.
   *
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  private $checksumProvider;

  /**
   * The cache name.
   *
   * @var string
   */
  private static $cacheName;

  /**
   * The Momento client.
   *
   * @var \Momento\Cache\CacheClient
   */
  private $client;

  /**
   * The cache backends.
   *
   * @var array
   */
  private $backends = [];

  /**
   * Constructs a new \Drupal\momento_cache\MomentoCacheBackendFactory object.
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
   * Gets the configured cache name.
   */
  public static function getCacheName() {
    return static::$cacheName ?? '';
  }

  /**
   * Gets a cache backend instance for the specified bin.
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
