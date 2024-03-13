<?php

namespace Drupal\momento_cache\Client;

use Drupal\Core\Site\Settings;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\CacheClient;
use Momento\Config\Configurations\Laptop;

/**
 * Momento client factory.
 */
class MomentoClientFactory {

  /**
   * The authentication provider.
   *
   * @var \Momento\Auth\StringMomentoTokenProvider
   */
  private $authProvider;

  /**
   * The cache client.
   *
   * @var \Momento\Cache\CacheClient
   */
  private $client;

  /**
   * The client timeout in milliseconds.
   *
   * @var int
   */
  private $clientTimeoutMsec;

  /**
   * Whether to force a new channel.
   *
   * @var bool
   */
  private $forceNewChannel;

  /**
   * The number of gRPC channels.
   *
   * @var int
   */
  private $numGrpcChannels;

  /**
   * The log file.
   *
   * @var string
   */
  private $logFile;

  /**
   * MomentoClientFactory constructor.
   */
  public function __construct() {
    $settings = Settings::get('momento_cache', []);
    $authToken = array_key_exists('api_token', $settings) ?
            $settings['api_token'] : getenv("MOMENTO_API_TOKEN");
    $this->authProvider = new StringMomentoTokenProvider($authToken);
    $this->forceNewChannel = array_key_exists('force_new_channel', $settings) ?
            $settings['force_new_channel'] : TRUE;
    $this->logFile =
            array_key_exists('logfile', $settings) ? $settings['logfile'] : NULL;
    $this->numGrpcChannels =
            array_key_exists('num_grpc_channels', $settings) ? $settings['num_grpc_channels'] : 1;
    $this->clientTimeoutMsec =
            array_key_exists('client_timeout_msec', $settings) ? $settings['client_timeout_msec'] : 15000;
  }

  /**
   * Get the momento client.
   */
  public function get() {
    if ($this->client) {
      return $this->client;
    }
    $start = hrtime(TRUE);
    $config = Laptop::latest();
    $config = $config->withTransportStrategy(
          $config->getTransportStrategy()->withGrpcConfig(
              $config
                ->getTransportStrategy()
                ->getGrpcConfig()
                ->withForceNewChannel($this->forceNewChannel)
                ->withNumGrpcChannels($this->numGrpcChannels)
          )
      )->withClientTimeout($this->clientTimeoutMsec);
    $this->client = new CacheClient($config, $this->authProvider, 30);
    if ($this->logFile) {
      $totalTimeMs = (hrtime(TRUE) - $start) / 1e6;
      $mt = microtime(TRUE);
      @error_log("[$mt] Instantiated cache client [$totalTimeMs ms]\n", 3, $this->logFile);
    }
    return $this->client;
  }

}
