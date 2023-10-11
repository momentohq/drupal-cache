<?php

// TODO: this class is not used anywhere, but it is referenced in the bootstrap_container_definition
//  in settings.php. It's not hurting anything, but needs to be removed.

namespace Drupal\momento_cache\Invalidator;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\momento_cache\Client\MomentoClientFactory;

class MomentoTimestampInvalidator {

    private $bin = '_momentoTags';

    public function __construct(MomentoClientFactory $factory) {
        $this->client = $factory->get();
        print("\n\n\nTIMESTAMP INVALIDATOR CLIENT IS ALIVE");
    }

    public function invalidateTags(array $tags) {
        return;
    }

}
