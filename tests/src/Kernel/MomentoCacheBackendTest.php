<?php

namespace Drupal\Tests\momento_cache\Kernel;

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

    /**
     * Creates a new instance of MomentoBackend.
     *
     * @return \Drupal\momento_cache\MomentoCacheBackend
     *   A new MomentoCacheBackend object.
     */
    protected function createCacheBackend($bin) {
        $factory = new MomentoCacheBackendFactory();
        return $factory->get($bin);
    }

    public function testTtl() {
        $backend = $this->getCacheBackend();
        $backend->set('test1', 1, time() + 2);
        $backend->set('test2', 1);
        sleep(3);
        $this->assertFalse($backend->get('test1'), 'Cache id test1 expired.');
        $this->assertNotFalse($backend->get('test2'), 'Cache id test2 still exists.');
    }

}
