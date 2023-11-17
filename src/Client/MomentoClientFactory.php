<?php

namespace Drupal\momento_cache\Client;

use Drupal\Core\Site\Settings;
use Momento\Auth\StringMomentoTokenProvider;
use Momento\Cache\CacheClient;
use Momento\Config\Configurations\Laptop;

class MomentoClientFactory {

    private $authProvider;
    private $client;
    private $forceNewChannel;
    private $numGrpcChannels;
    private $logFile;

    public function __construct() {
        $settings = Settings::get('momento_cache', []);
        $authToken = array_key_exists('api_token', $settings) ?
            $settings['api_token'] : getenv("MOMENTO_API_TOKEN");
        $this->authProvider = new StringMomentoTokenProvider($authToken);
        $this->forceNewChannel = array_key_exists('force_new_channel', $settings) ?
            $settings['force_new_channel'] : true;
        $this->logFile =
            array_key_exists('logfile', $settings) ? $settings['logfile'] : null;
        $this->numGrpcChannels =
            array_key_exists('num_grpc_channels', $settings) ? $settings['num_grpc_channels'] : 1;
    }

    public function get() {
        if ($this->client) {
            return $this->client;
        }
        $start = hrtime(true);
        $config = Laptop::latest();
        $config = $config->withTransportStrategy(
            $config->getTransportStrategy()->withGrpcConfig(
                $config
                    ->getTransportStrategy()
                    ->getGrpcConfig()
                    ->withForceNewChannel($this->forceNewChannel)
                    ->withNumGrpcChannels($this->numGrpcChannels)
            )
        );
        $this->client = new CacheClient($config, $this->authProvider, 30);
        if ($this->logFile) {
            $totalTimeMs = (hrtime(true) - $start) / 1e6;
            $mt = microtime(true);
            @error_log("[$mt] Instantiated cache client [$totalTimeMs ms]\n", 3, $this->logFile);
        }
        return $this->client;
    }
}
