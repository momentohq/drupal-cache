<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\DependencyInjection\ContainerNotInitializedException;
use Drupal\Core\Site\Settings;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

class MomentoCacheBackend implements CacheBackendInterface
{

    private $backendName = "momento-cache";
    private $bin;
    private $binTag;
    private $client;
    private $checksumProvider;
    private $MAX_TTL;
    private $cacheName;
    private $logFile;

    public function __construct(
        $bin,
        $client,
        CacheTagsChecksumInterface $checksum_provider
    ) {
        $start = hrtime(true);
        $this->MAX_TTL = intdiv(PHP_INT_MAX, 1000);
        $this->client = $client;
        $this->bin = $bin;
        $this->checksumProvider = $checksum_provider;
        $this->binTag = "$this->backendName:$this->bin";

        $settings = Settings::get('momento_cache', []);
        $cacheNamePrefix =
            array_key_exists('cache_name_prefix', $settings) ? $settings['cache_name_prefix'] : "drupal-";
        $this->cacheName = $cacheNamePrefix . $this->bin;
        $this->logFile =
            array_key_exists('logfile', $settings) ? $settings['logfile'] : null;
    }

    public function get($cid, $allow_invalid = FALSE)
    {
        $cids = [$cid];
        $recs = $this->getMultiple($cids, $allow_invalid);
        return reset($recs);
    }

    public function getMultiple(&$cids, $allow_invalid = FALSE)
    {
        $start = $this->startStopwatch();
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
                    if ($allow_invalid || $this->valid($result)) {
                        $fetched[$cid] = $result;
                    }
                } elseif ($getResponse->asError()) {
                    $this->log(
                        "GET error for cid $cid in bin $this->bin: " . $getResponse->asError()->message()
                    );
                }
            }
        }
        $cids = array_diff($cids, array_keys($fetched));
        $this->stopStopwatch($start, "GET_MULTIPLE got " . count($fetched) . " items");
        return $fetched;
    }

    public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = [])
    {
        $start = $this->startStopwatch();
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
        $item->checksum = $this->checksumProvider->getCurrentChecksum($tags);

        $requestTime = \Drupal::time()->getRequestTime();
        if ($expire != CacheBackendInterface::CACHE_PERMANENT) {
            if ($expire < $requestTime) {
                $item->valid = FALSE;
            } else {
                $ttl = $expire - $requestTime;
            }
        }
        $item->expire = $expire;

        $setResponse = $this->client->set($this->cacheName, $cid, serialize($item), $ttl);
        if ($setResponse->asError()) {
            $this->log("SET response error for bin $this->bin: " . $setResponse->asError()->message());
        } else {
            $this->stopStopwatch($start, "SET cid $cid in bin $this->bin with ttl $ttl");
        }
    }

    public function setMultiple(array $items)
    {
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
        $start = $this->startStopwatch();
        $deleteResponse = $this->client->delete($this->cacheName, $cid);
        if ($deleteResponse->asError()) {
            $this->log("DELETE response error for $cid in bin $this->bin: " . $deleteResponse->asError()->message());
        } else {
            $this->stopStopwatch($start, "DELETE cid $cid from bin $this->bin");
        }
    }

    public function deleteMultiple(array $cids)
    {
        $start = $this->startStopwatch();
        foreach ($cids as $cid) {
            $this->delete($cid);
        }
        $this->stopStopwatch($start, "DELETE_MULTIPLE in bin $this->bin for " . count($cids) . " items");
    }

    public function deleteAll()
    {
        $start = $this->startStopwatch();
        $flushResponse = $this->client->flushCache($this->cacheName);
        if ($flushResponse->asError()) {
            $this->log(
                "FLUSH_CACHE response error for $this->cacheName: " . $flushResponse->asError()->message()
            );
        } else {
            $this->stopStopwatch($start, "FLUSH_CACHE for $this->bin");
        }
    }

    public function invalidate($cid)
    {
        $start = $this->startStopwatch();
        $this->invalidateMultiple([$cid]);
        $this->stopStopwatch($start, "INVALIDATE $cid for bin $this->bin");
    }

    public function invalidateMultiple(array $cids)
    {
        $start = $this->startStopwatch();
        $requestTime = \Drupal::time()->getRequestTime();
        foreach ($cids as $cid) {
            if ($item = $this->get($cid)) {
                $this->set($item->cid, $item->data, $requestTime - 1, $item->tags);
            }
        }
        $this->stopStopwatch($start, "INVALIDATE_MULTIPLE for " . count($cids) . " items in $this->bin");
    }

    public function invalidateAll()
    {
        $start = $this->startStopwatch();
        $this->invalidateTags([$this->binTag]);
        $this->stopStopwatch($start, "INVALIDATE_ALL for $this->bin");
    }

    public function invalidateTags(array $tags)
    {
        $start = $this->startStopwatch();
        $this->checksumProvider->invalidateTags($tags);
        $this->stopStopwatch($start, "INVALIDATE_TAGS for " . count($tags));
    }

    public function removeBin()
    {
        $start = $this->startStopwatch();
        $deleteResponse = $this->client->deleteCache($this->cacheName);
        if ($deleteResponse->asError()) {
            $this->log("DELETE_CACHE response error for bin $this->cacheName: " . $deleteResponse->asError()->message());
        }
        $this->stopStopwatch($start, "REMOVE_BIN for $this->bin");
    }

    public function garbageCollection()
    {
        // Momento will invalidate items; That item's memory allocation is then
        // freed up and reused. So nothing needs to be deleted/cleaned up here.
    }

    private function valid($item) {
        // TODO: see https://www.drupal.org/project/memcache/issues/3302086 for discussion of why I'm using
        //  $_SERVER instead of Drupal::time() and potential suggestions on how to inject the latter for use here.
        try {
            $requestTime = \Drupal::time()->getRequestTime();
        } catch (ContainerNotInitializedException $e) {
            $requestTime = $_SERVER['REQUEST_TIME'];
        }
        $isValid = TRUE;
        if ($item->expire != CacheBackendInterface::CACHE_PERMANENT && $item->expire < $requestTime) {
            $item->valid = FALSE;
            return FALSE;
        }

        if (!$this->checksumProvider->isValid($item->checksum, $item->tags)) {
            $isValid = FALSE;
        }
        $item->valid = $isValid;
        return $isValid;
    }

    private function log(string $message) {
        if (!$this->logFile) {
            return;
        }
        if ($message[-1] != "\n") {
            $message .= "\n";
        }
        error_log($message, 3, $this->logFile);
    }

    private function startStopwatch() {
        return hrtime(true);
    }

    private function stopStopwatch($startTime, $message=null) {
        if (!$this->logFile) {
            return;
        }
        $totalTimeMs = (hrtime(true) - $startTime) / 1e+6;
        if ($message) {
            $this->log("$message [$totalTimeMs ms]\n");
        }
    }

}
