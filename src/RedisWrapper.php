<?php

namespace LaravelFallbackCache;

use Throwable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LaravelFallbackCache\Config\Configuration;

class RedisWrapper
{
    /** @var object */
    private $redis;
    
    /** @var FallbackCacheServiceProvider */
    private $provider;

    public function __construct($redis, $provider)
    {
        $this->redis = $redis;
        $this->provider = $provider;
    }

    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0.0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0.0,
        ?array $context = null
    ): bool {
        try {
            // Check if the redis instance has a connect method
            if (method_exists($this->redis, 'connect')) {
                $result = $this->redis->connect(
                    $host,
                    $port,
                    $timeout,
                    $persistent_id,
                    $retry_interval,
                    $read_timeout,
                    $context
                );
            } else {
                // For Laravel Redis connections, assume connection is established
                $result = true;
            }
            
            // Test connection with ping if available
            if (method_exists($this->redis, 'ping')) {
                $this->redis->ping();
            }
            
            return $result;
        } catch (Throwable $e) {
            // error_log("Redis connection failed in wrapper: " . $e->getMessage());
            // error_log("Setting failover flag in wrapper...");
            
            try {
                $this->provider->setFailedOver(true);
                // error_log("Failover flag set in wrapper: " . ($this->provider->hasFailedOver() ? "true" : "false"));
                $this->handleFailover($e);
            } catch (\Exception $e2) {
                // error_log("Error in failover: " . $e2->getMessage());
                // error_log("Stack trace: " . $e2->getTraceAsString());
            }
            throw $e;
        }
    }

    private function handleFailover(Throwable $e): void
    {
        $fallbackStore = Config::get(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, Configuration::CACHE_DRIVER_ARRAY);
        
        // Log::warning('Cache store failed', [
        //     'from' => 'redis',
        //     'to' => $fallbackStore,
        //     'error' => $e->getMessage()
        // ]);

        Config::set('cache.default', $fallbackStore);
    }

    public function __call($name, $args)
    {
        try {
            if ($this->provider->hasFailedOver()) {
                // Log::info('Already failed over, skipping Redis operation', [
                //     'method' => $name
                // ]);
                return false;
            }

            // Log::debug('Redis method call', [
            //     'method' => $name,
            //     'arguments' => $args
            // ]);

            $result = $this->redis->$name(...$args);
            return $result;
        } catch (Throwable $e) {
            $this->handleFailover($e);
            throw $e;
        }
    }
}
