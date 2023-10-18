<?php

namespace Drupal\Tests\momento_cache\Kernel;

use Drupal\Core\Cache\DatabaseCacheTagsChecksum;
use Drupal\KernelTests\Core\Cache\GenericCacheBackendUnitTestBase;
use Drupal\momento_cache\MomentoCacheBackendFactory;

/**
 * Tests the MomentoCacheBackend.
 *
 * @group momento_cache
 */
class MomentoCacheBackendTest extends GenericCacheBackendUnitTestBase {

    /**
     * Modules to enable.
     *
     * @var array
     */
    protected static $modules = ['system', 'momento_cache'];
    private $cacheNamePrefix;

    public function setUpCacheBackend() {
        $this->cacheNamePrefix = $this->cacheNamePrefix ?? uniqid("drupal-cache-test-");
        putenv("MOMENTO_CACHE_NAME_PREFIX=$this->cacheNamePrefix-");
    }

    public function tearDownCacheBackend() {
        $clientFactory = $this->container->get('momento_cache.factory');
        $cacheName = MomentoCacheBackendFactory::getCacheName();
        $client = $clientFactory->get();
        $deleteResponse = $client->deleteCache($cacheName);
        if ($deleteResponse->asError()) {
            error_log("Error deleting test cache $cacheName: " . $deleteResponse->asError()->message());
        }
    }

    /**
     * Creates a new instance of MomentoBackend.
     *
     * @return \Drupal\momento_cache\MomentoCacheBackend
     *   A new MomentoCacheBackend object.
     */
    protected function createCacheBackend($bin) {
        $clientFactory = $this->container->get('momento_cache.factory');
        $checksumProvider = $this->container->get('cache_tags.invalidator.checksum');
        $backendFactory = new MomentoCacheBackendFactory($clientFactory, $checksumProvider);
        return $backendFactory->get($bin);
    }

    public function testTtl() {
        $backend = $this->getCacheBackend();
        $backend->set('test1', 1, time() + 1);
        $backend->set('test2', 1);
        sleep(3);
        $this->assertFalse($backend->get('test1'), 'Cache id test1 expired.');
        $this->assertNotFalse($backend->get('test2'), 'Cache id test2 still exists.');
    }

}
