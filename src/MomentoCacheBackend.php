<?php

namespace Drupal\momento_cache;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Drupal\Tests\momento_cache\Kernel\MomentoCacheBackendTest;

/**
 * Provides a cache backend implementation using the Momento caching system.
 */
class MomentoCacheBackend implements CacheBackendInterface {

  use LoggerChannelTrait;

  /**
   * The name of the backend.
   *
   * @var string
   */
  private $backendName = "momento-cache";

  /**
   * The cache bin.
   *
   * @var string
   */
  private $bin;

  /**
   * The bin tag.
   *
   * @var string
   */
  private $binTag;

  /**
   * The last bin deletion time.
   *
   * @var int
   */
  private $lastBinDeletionTime;

  /**
   * The Momento client.
   *
   * @var \Momento\Cache\CacheClient
   */
  private $client;

  /**
   * The checksum provider.
   *
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  private $checksumProvider;

  /**
   * The maximum time to live.
   *
   * @var int
   */
  private $maxTtl;

  /**
   * The cache name.
   *
   * @var string
   */
  private $cacheName;

  /**
   * The log file.
   *
   * @var string
   */
  private $logFile;

  /**
   * MomentoCacheBackend constructor.
   */
  public function __construct(
        $bin,
        $client,
        CacheTagsChecksumInterface $checksum_provider
    ) {
    $this->maxTtl = intdiv(PHP_INT_MAX, 1000);
    $this->client = $client;
    $this->bin = $bin;
    $this->checksumProvider = $checksum_provider;
    $this->binTag = "$this->backendName:$this->bin";

    $settings = Settings::get('momento_cache', []);
    $this->cacheName = MomentoCacheBackendFactory::getCacheName();
    $this->logFile =
            array_key_exists('logfile', $settings) ? $settings['logfile'] : NULL;
    $this->ensureLastBinDeletionTimeIsSet();
  }

  /**
   * Gets cache record(s) for a specific cache ID(s).
   */
  public function get($cid, $allow_invalid = FALSE) {
    $cids = [$cid];
    $recs = $this->getMultiple($cids, $allow_invalid);
    return reset($recs);
  }

  /**
   * Gets cid for bin.
   */
  private function getCidForBin($cid) {
    return "$this->bin:$cid";
  }

  /**
   * Gets multiple cache records for multiple cache IDs.
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $start = $this->startStopwatch();
    $fetched = [];
    foreach (array_chunk($cids, 100) as $cidChunk) {
      $futures = [];
      foreach ($cidChunk as $cid) {
        $futures[$cid] = $this->client->getAsync($this->cacheName, $this->getCidForBin($cid));
      }

      foreach ($futures as $cid => $future) {
        $getResponse = $future->wait();
        if ($getResponse->asHit()) {
          $result = unserialize(
            $getResponse->asHit()->valueString(),
            [
              'allowed_classes' => [
                MomentoCacheBackend::class,
                MomentoCacheBackendTest::class,
              ],
            ]
                 );

          if ($result->created <= $this->lastBinDeletionTime) {
            continue;
          }

          if ($allow_invalid || $this->valid($result)) {
            $fetched[$cid] = $result;
          }
        }
        elseif ($getResponse->asError()) {
          $this->log(
                "GET error for cid $cid in bin $this->bin: " . $getResponse->asError()->message(),
                TRUE
            );
        }
      }
    }
    $cids = array_diff($cids, array_keys($fetched));
    $this->stopStopwatch($start, "GET_MULTIPLE got " . count($fetched) . " items");
    return $fetched;
  }

  /**
   * Sets cache record(s) for a specific cache ID(s).
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []) {
    $start = $this->startStopwatch();
    $item = $this->processItemForSet($cid, $data, $expire, $tags);
    $ttl = $item->ttl;
    unset($item->ttl);

    $setResponse = $this->client->set($this->cacheName, $this->getCidForBin($cid), serialize($item), $ttl);
    if ($setResponse->asError()) {
      $this->log("SET response error for bin $this->bin: " . $setResponse->asError()->message(), TRUE);
    }
    else {
      $this->stopStopwatch(
            $start, "SET cid $cid in bin $this->bin with ttl $ttl"
        );
    }
  }

  /**
   * Sets multiple cache records for multiple cache IDs.
   */
  public function setMultiple(array $items) {
    $start = $this->startStopwatch();
    $futures = [];
    foreach ($items as $cid => $item) {
      $item = $this->processItemForSet(
            $cid,
            $item['data'],
            $item['expire'] ?? CacheBackendInterface::CACHE_PERMANENT,
            $item['tags'] ?? []
        );
      $ttl = $item->ttl;
      unset($item->ttl);
      $futures[] = $this->client->setAsync(
            $this->cacheName,
            $this->getCidForBin($cid),
            serialize($item),
            $ttl
        );
    }

    foreach ($futures as $future) {
      $setResponse = $future->wait();
      if ($setResponse->asError()) {
        $this->log(
              "SET_MULTIPLE response error for bin $this->bin: " . $setResponse->asError()->message(),
              TRUE
          );
      }
    }
    $this->stopStopwatch($start, "SET_MULTIPLE in bin $this->bin for " . count($items) . " items");
  }

  /**
   * Deletes a cache record for a specific cache ID.
   */
  public function delete($cid) {
    $start = $this->startStopwatch();
    $deleteResponse = $this->client->delete($this->cacheName, $this->getCidForBin($cid));
    if ($deleteResponse->asError()) {
      $this->log(
            "DELETE response error for $cid in bin $this->bin: " . $deleteResponse->asError()->message(),
            TRUE
        );
    }
    else {
      $this->stopStopwatch($start, "DELETE cid $cid from bin $this->bin");
    }
  }

  /**
   * Deletes multiple cache records for multiple cache IDs.
   */
  public function deleteMultiple(array $cids) {
    $start = $this->startStopwatch();
    foreach ($cids as $cid) {
      $this->delete($cid);
    }
    $this->stopStopwatch($start, "DELETE_MULTIPLE in bin $this->bin for " . count($cids) . " items");
  }

  /**
   * Deletes all cache records in a bin.
   */
  public function deleteAll() {
    $start = $this->startStopwatch();
    $this->setLastBinDeletionTime();
    $this->stopStopwatch($start, "DELETE_ALL in bin $this->bin");
  }

  /**
   * Invalidates a cache record for a specific cache ID.
   */
  public function invalidate($cid) {
    $start = $this->startStopwatch();
    $this->invalidateMultiple([$cid]);
    $this->stopStopwatch($start, "INVALIDATE $cid for bin $this->bin");
  }

  /**
   * Invalidates multiple cache records for multiple cache IDs.
   */
  public function invalidateMultiple(array $cids) {
    $start = $this->startStopwatch();
    $requestTime = \Drupal::time()->getRequestTime();
    foreach ($cids as $cid) {
      if ($item = $this->get($cid)) {
        $this->set($item->cid, $item->data, $requestTime - 1, $item->tags);
      }
    }
    $this->stopStopwatch($start, "INVALIDATE_MULTIPLE for " . count($cids) . " items in $this->bin");
  }

  /**
   * Invalidates all cache records in a bin.
   */
  public function invalidateAll() {
    $start = $this->startStopwatch();
    $this->invalidateTags([$this->binTag]);
    $this->stopStopwatch($start, "INVALIDATE_ALL for $this->bin");
  }

  /**
   * Invalidates cache records by tag.
   */
  public function invalidateTags(array $tags) {
    $start = $this->startStopwatch();
    $this->checksumProvider->invalidateTags($tags);
    $this->stopStopwatch($start, "INVALIDATE_TAGS for " . count($tags));
  }

  /**
   * Removes a bin.
   */
  public function removeBin() {
    $start = $this->startStopwatch();
    $this->setLastBinDeletionTime();
    $this->stopStopwatch($start, "REMOVE_BIN $this->bin");
  }

  /**
   * Garbage collection.
   */
  public function garbageCollection() {
    // Momento will invalidate items; That item's memory allocation is then
    // freed up and reused. So nothing needs to be deleted/cleaned up here.
  }

  /**
   * Process item for set.
   */
  private function processItemForSet($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []) {
    assert(Inspector::assertAllStrings($tags));

    // Add tag for invalidateAll()
    $tags[] = $this->binTag;
    $tags = array_unique($tags);
    sort($tags);

    $ttl = $this->maxTtl;
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
      else {
        $ttl = $expire - $requestTime;
      }
    }
    $item->expire = $expire;
    $item->ttl = $ttl;
    return $item;
  }

  /**
   * Validate item.
   */
  private function valid($item) {
    // If container isn't initialized yet, use $SERVER's request time value.
    try {
      $requestTime = \Drupal::time()->getRequestTime();
    }
    catch (ContainerNotInitializedException $e) {
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

  /**
   * Log.
   */
  private function log(string $message, bool $logToDblog = FALSE) {
    if ($logToDblog) {
      $this->getLogger('momento_cache')->error($message);
    }

    if (!$this->logFile) {
      return;
    }

    if ($message[-1] != "\n") {
      $message .= "\n";
    }
    $mt = microtime(TRUE);
    @error_log("[$mt] $message", 3, $this->logFile);
  }

  /**
   * Start stopwatch.
   */
  private function startStopwatch() {
    return hrtime(TRUE);
  }

  /**
   * Stop stopwatch.
   */
  private function stopStopwatch($startTime, $message = NULL) {
    if (!$this->logFile) {
      return;
    }
    $totalTimeMs = (hrtime(TRUE) - $startTime) / 1e+6;
    if ($message) {
      $this->log("$message [$totalTimeMs ms]\n");
    }
  }

  /**
   * Ensure last bin deletion time is set.
   */
  private function ensureLastBinDeletionTimeIsSet() {
    if (!$this->lastBinDeletionTime) {
      $getRequest = $this->client->get($this->cacheName, $this->bin);
      if ($getRequest->asError()) {
        $this->log(
              "ERROR getting last deletion time for bin $this->bin: " . $getRequest->asError()->message()
          );
        $this->setLastBinDeletionTime();
      }
      elseif ($getRequest->asMiss()) {
        $this->setLastBinDeletionTime();
      }
      else {
        $this->lastBinDeletionTime = intval($getRequest->asHit()->valueString());
      }
    }
  }

  /**
   * Set last bin deletion time.
   */
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
