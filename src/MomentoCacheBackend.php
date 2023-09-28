<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Momento\Requests\CollectionTtl;

class MomentoCacheBackend implements CacheBackendInterface, CacheTagsInvalidatorInterface {

    use LoggerChannelTrait;

    protected $bin;
    protected $client;
    private $MAX_TTL;
    private $cacheName;

    public function __construct($bin, $client, $createCache, $cacheName) {
        $this->MAX_TTL = intdiv(PHP_INT_MAX, 1000);
        $this->client = $client;
        $this->bin = $bin;
        $this->cacheName = $cacheName;

        if ($createCache) {
            $createResponse = $this->client->createCache($this->cacheName);
            if ($createResponse->asError()) {
                $this->getLogger('momento_cache')->error(
                    "Error creating cache $this->cacheName : " . $createResponse->asError()->message()
                );
            } elseif ($createResponse->asSuccess()) {
                $this->getLogger('momento_cache')->info("Created cache $this->cacheName");
            }
        }
    }

    public function get($cid, $allow_invalid = FALSE) {
        $this->getLogger('momento_cache')->debug("GET with bin $this->bin, cid " . $cid);
        $cids = [$cid];
        $recs = $this->getMultiple($cids);
        return reset($recs);
    }

    public function getMultiple(&$cids, $allow_invalid = FALSE) {
        $this->getLogger('momento_cache')->debug(
            "GET_MULTIPLE for bin $this->bin, cids: " . implode(', ', $cids)
        );

        $fetched = [];
        foreach (array_chunk($cids, 100) as $cidChunk) {
            $futures = [];
            foreach ($cidChunk as $cid) {
                $futures[$cid] = $this->client->getAsync($this->cacheName, $cid);
            }

            foreach ($futures as $cid => $future) {
                $getResponse = $future->wait();
                if ($getResponse->asHit()) {
                    $fetched[$cid] = unserialize($getResponse->asHit()->valueString());
                    $this->getLogger('momento_cache')->debug("Successful GET for cid $cid in bin $this->bin");
                } elseif ($getResponse->asError()) {
                    $this->getLogger('momento_cache')->error(
                        "GET error for cid $cid in bin $this->bin: " . $getResponse->asError()->message()
                    );
                }
            }
        }

        return $fetched;
    }

    public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []) {
        $ttl = $this->MAX_TTL;
        $item = new \stdClass();
        $item->cid = $cid;
        $item->data = $data;
        $item->created = round(microtime(TRUE), 3);
        if ($expire != CacheBackendInterface::CACHE_PERMANENT) {
            $ttl = $expire - \Drupal::time()->getRequestTime();
        }
        $item->expire = $expire;
        $item->tags = $tags;

        $this->getLogger('momento_cache')->debug("SET cid $cid in bin $this->bin with ttl $ttl");
        $setResponse = $this->client->set($this->cacheName, $cid, serialize($item), $ttl);
        if ($setResponse->asError()) {
            $this->getLogger('momento_cache')->error("SET response error for bin $this->bin: " . $setResponse->asError()->message());
        }
        foreach ($tags as $tag) {
            $tagSetName = $this->getTagSetName($tag);
            // TODO: either prefix or hash set name so we don't accidentally overwrite keys if there is ever a collision
            $setAddElementResponse = $this->client->setAddElement($this->cacheName, $tagSetName, $cid, CollectionTtl::of($this->MAX_TTL));
            if ($setAddElementResponse->asError()) {
                $this->getLogger('momento_cache')->error("TAG add error in bin $this->bin for tag $tag: " . $setAddElementResponse->asError()->message());
            }
        }
    }

    public function setMultiple(array $items) {
        $this->getLogger('momento_cache')->debug("SET_MULTIPLE in bin $this->bin for " . count($items) . " items");

        foreach ($items as $cid => $item) {
            $this->set(
                $cid,
                $item['data'],
                $item['expire'] ?? CacheBackendInterface::CACHE_PERMANENT,
                $item['tags'] ?? []
            );
        }
    }

    public function delete($cid) {
        // TODO: remove this cid from tags sets? would require fetching the item and accessing the tags.
        $this->getLogger('momento_cache')->debug("DELETE cid $cid from bin $this->bin");
        $deleteResponse = $this->client->delete($this->cacheName, $cid);
        if ($deleteResponse->asError()) {
            $this->getLogger('momento_cache')->error("DELETE response error for $cid in bin $this->bin: " . $deleteResponse->asError()->message());
        } else {
            $this->getLogger('momento_cache')->debug("DELETED $cid from bin $this->bin");
        }
    }

    public function deleteMultiple(array $cids) {
        $this->getLogger('momento_cache')->debug(
            "DELETE_MULTIPLE in bin $this->bin for cids: " . implode(', ', $cids)
        );
        foreach ($cids as $cid) {
            $this->delete($cid);
        }
    }

    public function deleteAll() {
        $this->getLogger('momento_cache')->debug("DELETE_ALL in bin $this->bin");
        // TODO: we don't have flushCache in the PHP SDK yet
        $deleteResponse = $this->client->deleteCache($this->cacheName);
        if ($deleteResponse->asError()) {
            $this->getLogger('momento_cache')->error(
                "DELETE_CACHE response error for $this->cacheName: " . $deleteResponse->asError()->message()
            );
            return;
        }
        $createResponse = $this->client->createCache($this->cacheName);
        if ($createResponse->asError()) {
            $this->getLogger('momento_cache')->error(
                "CREATE_CACHE response error for $this->cacheName: " . $createResponse->asError()->message()
            );
        }
    }

    public function invalidate($cid) {
        $this->getLogger('momento_cache')->debug("INVALIDATE cid $cid for bin $this->bin");
        $this->delete($cid);
    }

    public function invalidateMultiple(array $cids) {
        $this->getLogger('momento_cache')->debug("INVALIDATE_MULTIPLE for bin $this->bin");
        $this->deleteMultiple($cids);
    }

    public function invalidateAll() {
        $this->getLogger('momento_cache')->debug("INVALIDATE_ALL for bin $this->bin");
        $this->deleteAll();
    }

    public function invalidateTags(array $tags) {
        $this->getLogger('momento_cache')->debug("INVALIDATE_TAGS in bin $this->bin with tags: " . implode(', ', $tags));
        foreach ($tags as $tag) {
            $tagSetName = $this->getTagSetName($tag);
            $this->getLogger('momento_cache')->debug("Processing tag '$tag' in bin $this->bin");
            $setFetchResponse = $this->client->setFetch($this->cacheName, $tagSetName);
            if ($setFetchResponse->asError()) {
                $this->getLogger('momento_cache')->error(
                    "Error fetching TAG $tag from bin $this->bin: " . $setFetchResponse->asError()->message()
                );
            } elseif ($setFetchResponse->asHit()) {
                $cids = $setFetchResponse->asHit()->valuesArray();
                $this->getLogger('momento_cache')->debug(
                    "Deleting $tag from bin $this->bin: " . implode(', ', $cids)
                );
                $this->deleteMultiple($cids);
                $tagSetDeleteResponse = $this->client->delete($this->cacheName, $tagSetName);
                if ($tagSetDeleteResponse->asError()) {
                    $this->getLogger('momento_cache')->error(
                        "Error deleting TAG $tag from bin $this->bin: " . $setFetchResponse->asError()->message()
                    );
                }
            } elseif ($setFetchResponse->asMiss()) {
                $this->getLogger('momento_cache')->debug("No cids found for tag $tag in bin $this->bin");
            }
        }
    }

    public function removeBin() {
        $this->getLogger('momento_cache')->debug("REMOVE_BIN $this->bin");
        $deleteResponse = $this->client->deleteCache($this->cacheName);
        if ($deleteResponse->asError()) {
            $this->getLogger('momento_cache')->error("DELETE_CACHE response error for bin $this->cacheName: " . $deleteResponse->asError()->message());
        }
    }

    public function garbageCollection() {
        // Momento will invalidate items; That item's memory allocation is then
        // freed up and reused. So nothing needs to be deleted/cleaned up here.
    }

    private function getTagSetName($tag) {
        return "_tagSet:$tag";
    }

}
