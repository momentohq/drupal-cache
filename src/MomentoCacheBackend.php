<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheTagsInvalidator;

class MomentoCacheBackend extends CacheTagsInvalidator implements CacheBackendInterface
{

    use LoggerChannelTrait;

    protected $backendName = "momento-cache";
    protected $bin;
    protected $binTag;
    protected $client;
    private $MAX_TTL;
    private $cacheName;
    private $tagsCacheName;

    public function __construct($bin, $client, $createCache, $cacheName, $tagsCacheName)
    {
        $this->MAX_TTL = intdiv(PHP_INT_MAX, 1000);
        $this->client = $client;
        $this->bin = $bin;
        $this->binTag = "$this->backendName:$this->bin";
        $this->cacheName = $cacheName;
        $this->tagsCacheName = $tagsCacheName;

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

    public function get($cid, $allow_invalid = FALSE)
    {
        $this->getLogger('momento_cache')->debug("GET with bin $this->bin, cid " . $cid);
        $cids = [$cid];
        $recs = $this->getMultiple($cids, $allow_invalid);
        return reset($recs);
    }

    private function isValid($item) {
        $invalidatedTags = [];
        $requestTime = \Drupal::time()->getRequestTime();
        $isValid = TRUE;
        if ($item->expire != CacheBackendInterface::CACHE_PERMANENT && $item->expire < $requestTime) {
            $item->valid = FALSE;
            return FALSE;
        }
        foreach ($item->tags as $tag) {
            if (isset($invalidatedTags[$tag]) && $invalidatedTags[$tag] > $item->created) {
                $isValid = FALSE;
                break;
            }
            // see if there's an invalidation timestamp in the cache
            $getResponse = $this->client->get($this->tagsCacheName, $tag);
            if ($getResponse->asHit()) {
                $this->getLogger('momento_cache_tag_validation')->debug(
                    "FOUND INVALIDATION RECORD FOR $tag in bin $this->bin"
                );
                $invalidatedTags[$tag] = (float)$getResponse->asHit()->valueString();
                $this->getLogger('momento_cache_tag_validation')->debug(
                    "INVALIDATED: " . implode(', ', array_keys($invalidatedTags))
                );
                if ($invalidatedTags[$tag] > $item->created) {
                    $isValid = FALSE;
                    break;
                }
            } elseif ($getResponse->asError()) {
                $this->getLogger('momento_cache_tag_validation')->error(
                    "Error fetching invalidated tag record for $tag: " . $getResponse->asError()->message()
                );
            }
        }
        $item->valid = $isValid;
        return $isValid;
    }

    public function getMultiple(&$cids, $allow_invalid = FALSE)
    {
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
                    $result = unserialize($getResponse->asHit()->valueString());
                    if ($allow_invalid || $this->isValid($result)) {
                        $fetched[$cid] = $result;
                        $this->getLogger('momento_cache')->debug("Successful GET for cid $cid in bin $this->bin");
                    }
                    if (!$result->valid) {
                        $this->getLogger('momento_cache')->debug("GET got INVALID for cid $cid in bin $this->bin");
                    }
                } elseif ($getResponse->asError()) {
                    $this->getLogger('momento_cache')->error(
                        "GET error for cid $cid in bin $this->bin: " . $getResponse->asError()->message()
                    );
                }
            }
        }
        $cids = array_diff($cids, array_keys($fetched));
        return $fetched;
    }

    public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = [])
    {
        assert(Inspector::assertAllStrings($tags));

        // Add tag for invalidateAll()
        $tags[] = $this->binTag;
        $tags = array_unique($tags);
        sort($tags);

        $ttl = $this->MAX_TTL;
        $item = new \stdClass();
        $item->cid = $cid;
        $item->tags = $tags;
        $item->data = $data;
        $item->created = round(microtime(TRUE), 3);
        $item->valid = TRUE;

        $requestTime = \Drupal::time()->getRequestTime();
        if ($expire != CacheBackendInterface::CACHE_PERMANENT) {
            if ($expire < $requestTime) {
                $item->valid = FALSE;
            } else {
                $ttl = $expire - $requestTime;
            }
        }
        $item->expire = $expire;

        $this->getLogger('momento_cache')->debug("SET cid $cid in bin $this->bin with ttl $ttl");
        $setResponse = $this->client->set($this->cacheName, $cid, serialize($item), $ttl);
        if ($setResponse->asError()) {
            $this->getLogger('momento_cache')->error("SET response error for bin $this->bin: " . $setResponse->asError()->message());
        } else {
            $this->getLogger('momento_cache_tags')->debug(
                "SET $this->bin:$cid with tags: " . implode(', ', $item->tags)
            );
        }
    }

    public function setMultiple(array $items)
    {
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

    public function delete($cid)
    {
        // TODO: remove this cid from tags sets? would require fetching the item and accessing the tags.
        $this->getLogger('momento_cache')->debug("DELETE cid $cid from bin $this->bin");
        $deleteResponse = $this->client->delete($this->cacheName, $cid);
        if ($deleteResponse->asError()) {
            $this->getLogger('momento_cache')->error("DELETE response error for $cid in bin $this->bin: " . $deleteResponse->asError()->message());
        } else {
            $this->getLogger('momento_cache')->debug("DELETED $cid from bin $this->bin");
        }
    }

    public function deleteMultiple(array $cids)
    {
        $this->getLogger('momento_cache')->debug(
            "DELETE_MULTIPLE in bin $this->bin for cids: " . implode(', ', $cids)
        );
        foreach ($cids as $cid) {
            $this->delete($cid);
        }
    }

    public function deleteAll()
    {
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

    public function invalidate($cid)
    {
        $this->getLogger('momento_cache')->debug("INVALIDATE cid $cid for bin $this->bin");
        $this->invalidateMultiple([$cid]);
    }

    public function invalidateMultiple(array $cids)
    {
        $this->getLogger('momento_cache')->debug("INVALIDATE_MULTIPLE for bin $this->bin");
        $requestTime = \Drupal::time()->getRequestTime();
        foreach ($cids as $cid) {
            if ($item = $this->get($cid)) {
                $this->set($item->cid, $item->data, $requestTime - 1, $item->tags);
            }
        }
    }

    public function invalidateAll()
    {
        $invalidateTime = round(microtime(TRUE), 3);
        $this->getLogger('momento_cache')->debug("INVALIDATE_ALL for bin $this->bin");
        $setResponse = $this->client->set($this->tagsCacheName, $this->binTag, $invalidateTime, $this->MAX_TTL);
        if ($setResponse->asError()) {
            $this->getLogger('momento_cache')->error(
                "INVALIDATE_ALL response error for $this->tagsCacheName: " . $setResponse->asError()->message()
            );
        }
    }

    public function invalidateTags(array $tags)
    {
        $tags = array_unique($tags);
        $invalidateTime = round(microtime(TRUE), 3);
        $this->getLogger('momento_cache_tag_validation')->debug(
            "INVALIDATE_TAGS in bin $this->bin with tags: " . implode(', ', $tags)
        );
        foreach ($tags as $tag) {
            $setResponse = $this->client->set($this->tagsCacheName, $tag, $invalidateTime, $this->MAX_TTL);
            if ($setResponse->asError()) {
                $this->getLogger('momento_cache_tag_validation')->error(
                    "INVALIDATE_TAGS response error $tag: " . $setResponse->asError()->message()
                );
            } else {
                $this->getLogger('momento_cache_tag_validation')->debug(
                    "INVALIDATE_TAGS invalidated $tag in $this->bin at $invalidateTime"
                );
            }
        }
    }

    public function removeBin()
    {
        $this->getLogger('momento_cache')->debug("REMOVE_BIN $this->bin");
        $deleteResponse = $this->client->deleteCache($this->cacheName);
        if ($deleteResponse->asError()) {
            $this->getLogger('momento_cache')->error(
                "DELETE_CACHE response error for bin $this->cacheName: " . $deleteResponse->asError()->message()
            );
        }
    }

    public function garbageCollection()
    {
        // Momento will invalidate items; That item's memory allocation is then
        // freed up and reused. So nothing needs to be deleted/cleaned up here.
    }

}
