<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

class MomentoTimestampInvalidator implements CacheTagsInvalidatorInterface {

    private $backend;

    public function __construct(MomentoCacheBackendFactory $factory) {
        $this->backend = $factory->get('fakebin');
    }

    public function invalidateTags(array $tags) {
        $this->backend->invalidateTags($tags);
    }

}
