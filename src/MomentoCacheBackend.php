<?php

namespace Drupal\momento_cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Drupal\Component\Assertion\Inspector;

class MomentoCacheBackend implements CacheBackendInterface
{

    use LoggerChannelTrait;

    private $backendName = "momento-cache";
    private $bin;
    private $binTag;
    private $lastBinDeletionTime;
    private $client;
    private $checksumProvider;
    private $MAX_TTL;
    private $cacheName;
    private $logFile;
    private $batchSize;

    public function __construct(
        $bin,
        $client,
        CacheTagsChecksumInterface $checksum_provider
    ) {
        $this->MAX_TTL = intdiv(PHP_INT_MAX, 1000);
        $this->client = $client;
        $this->bin = $bin;
        $this->checksumProvider = $checksum_provider;
        $this->binTag = "$this->backendName:$this->bin";

        $settings = Settings::get('momento_cache', []);
        $this->cacheName = MomentoCacheBackendFactory::getCacheName();
        $this->logFile =
            array_key_exists('logfile', $settings) ? $settings['logfile'] : null;
        $this->batchSize =
            array_key_exists('batch_size', $settings) ? $settings['batch_size'] : 50;
        $this->ensureLastBinDeletionTimeIsSet();
    }

    public function get($cid, $allow_invalid = FALSE)
    {
        $cids = [$cid];
        $recs = $this->getMultiple($cids, $allow_invalid);
        return reset($recs);
    }

    private function getCidForBin($cid) {
        return "$this->bin:$cid";
    }

    public function getMultiple(&$cids, $allow_invalid = FALSE)
    {
        $start = $this->startStopwatch();
        $numRequested = count($cids);
        $keys = [];
        $fetched_results = [];
        $fetched = [];
        foreach ($cids as $cid) {
            $keys[] = $this->getCidForBin($cid);
        }

        $response = $this->client->getBatch($this->cacheName, $keys);

        if ($response->asError()) {
            $this->log(
                "GET_MULTIPLE error for bin $this->bin: " . $response->asError()->message(),
                true
            );
            return [];
        } else {
            $fetched_results = $response->asSuccess()->results();

            foreach ($fetched_results as $result) {
                if ($result->asHit()) {
                    $item = unserialize($result->asHit()->valueString());

                    if ($item->created <= $this->lastBinDeletionTime) {
                        continue;
                    }

                    if ($allow_invalid || $this->valid($item)) {
                        @error_log("Item: [$item]\n", 3, $this->logFile);
                        $fetched[$item->cid] = $item;
                    }
                } elseif ($result->asError()) {
                    $this->log(
                        "GET_MULTIPLE response error for bin $this->bin: " . $result->asError()->message(),
                        true
                    );
                }
            }
        }
        $cids = array_diff($cids, array_keys($fetched));
        $this->stopStopwatch(
            $start,
            "GET_MULTIPLE got " . count($fetched_results) . " items of $numRequested requested."
        );
        return $fetched_results;
    }

    public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = [])
    {
        $start = $this->startStopwatch();
        $item = $this->processItemForSet($cid, $data, $expire, $tags);
        $ttl = $item->ttl;
        unset($item->ttl);
        $serialized_item = serialize($item);


        $setResponse = $this->client->set($this->cacheName, $this->getCidForBin($cid), $serialized_item, $ttl);
        if ($setResponse->asError()) {
            $this->log("SET response error for bin $this->bin: " . $setResponse->asError()->message(), true);
        } else {
            $this->stopStopwatch(
                $start, "SET cid $cid in bin $this->bin with ttl $ttl and " . strlen($serialized_item) . " bytes"
            );
        }
    }

    public function setMultiple(array $items)
    {
        $start = $this->startStopwatch();

        $processed_items = [];

        foreach ($items as $cid => $item) {
            error_log("Item: " . print_r($item, true), 3, $this->logFile);
            $item = $this->processItemForSet(
                $cid,
                $item['data'],
                $item['expire'] ?? CacheBackendInterface::CACHE_PERMANENT,
                $item['tags'] ?? []
            );
            $ttl = $item->ttl;
            unset($item->ttl);
            $serialized_item = serialize($item);
            $processed_items[] = [
                'key' => $this->getCidForBin($item->cid),
                'value' => $serialized_item,
                'ttl' => $ttl
            ];
        }

        error_log("Processed items: " . print_r($processed_items, true), 3, $this->logFile);

        $response = $this->client->setBatch($this->cacheName, $processed_items);
        if ($response->asError()) {
            $this->log(
                "SET_MULTIPLE response error for bin $this->bin: " . $response->asError()->message(),
                true
            );
        }
        $this->stopStopwatch($start, "SET_MULTIPLE in bin $this->bin for " . count($items) . " items");
    }

    public function delete($cid)
    {
        $start = $this->startStopwatch();
        $deleteResponse = $this->client->delete($this->cacheName, $this->getCidForBin($cid));
        if ($deleteResponse->asError()) {
            $this->log(
                "DELETE response error for $cid in bin $this->bin: " . $deleteResponse->asError()->message(),
                true
            );
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
        $this->setLastBinDeletionTime();
        $this->stopStopwatch($start, "DELETE_ALL in bin $this->bin");
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
        $this->setLastBinDeletionTime();
        $this->stopStopwatch($start, "REMOVE_BIN $this->bin");
    }

    public function garbageCollection()
    {
        // Momento will invalidate items; That item's memory allocation is then
        // freed up and reused. So nothing needs to be deleted/cleaned up here.
    }

    private function processItemForSet($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = [])
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
        $item->checksum = $this->checksumProvider->getCurrentChecksum($tags);

        $requestTime = \Drupal::time()->getRequestTime();
        if ($expire != CacheBackendInterface::CACHE_PERMANENT) {
            if ($expire < $requestTime) {
                $item->valid = FALSE;
            }
        }
        $item->expire = $expire;
        $item->ttl = $ttl;
        return $item;
    }

    private function valid($item) {
        // If container isn't initialized yet, use $SERVER's request time value
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

    private function log(string $message, bool $logToDblog = false) {
        if ($logToDblog) {
            $this->getLogger('momento_cache')->error($message);
        }

        if (!$this->logFile) {
            return;
        }

        if ($message[-1] != "\n") {
            $message .= "\n";
        }
        $mt = microtime(true);
        @error_log("[$mt] $message", 3, $this->logFile);
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

    private function ensureLastBinDeletionTimeIsSet() {
        if (!$this->lastBinDeletionTime) {
            $getRequest = $this->client->get($this->cacheName, $this->bin);
            if ($getRequest->asError()) {
                $this->log(
                    "ERROR getting last deletion time for bin $this->bin: " . $getRequest->asError()->message()
                );
                $this->setLastBinDeletionTime();
            } elseif ($getRequest->asMiss()) {
                $this->setLastBinDeletionTime();
            } else {
                $this->lastBinDeletionTime = intval($getRequest->asHit()->valueString());
            }
        }
    }

    private function setLastBinDeletionTime() {
        $this->lastBinDeletionTime = round(microtime(TRUE), 3);
        $setRequest = $this->client->set($this->cacheName, $this->bin, $this->lastBinDeletionTime);
        if ($setRequest->asError()) {
            $this->log(
                "ERROR getting last deletion time for bin $this->bin: " . $setRequest->asError()->message()
            );
        }
    }
}
