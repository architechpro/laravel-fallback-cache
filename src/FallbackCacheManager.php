<?php

namespace LaravelFallbackCache;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\ArrayStore;
use LaravelFallbackCache\Config\Configuration;
use Throwable;

class FallbackCacheManager extends CacheManager
{
    private FallbackCacheServiceProvider $provider;
    
    public function __construct($app, FallbackCacheServiceProvider $provider)
    {
        parent::__construct($app);
        $this->provider = $provider;
        
        // Bind manager for failover access
        $app->instance(self::class, $this);
    }

    /**
     * Create Redis cache driver with failover support.
     *
     * @param array $config
     * @return \Illuminate\Cache\Repository
     */
    protected function createRedisDriver(array $config)
    {
        $fallbackStore = $this->getFallbackStore();
        // error_log("Creating Redis driver. Failover state: " . ($this->provider->hasFailedOver() ? "true" : "false"));

        // Ensure fallback store is configured
        $fallbackConfig = [
            'driver' => $fallbackStore
        ];
        
        // Add database specific configuration if needed
        if ($fallbackStore === 'database') {
            $fallbackConfig['table'] = 'cache';
            $fallbackConfig['connection'] = null;
            $fallbackConfig['lock_connection'] = null;
        }
        
        $this->app['config']->set("cache.stores.{$fallbackStore}", $fallbackConfig);

        if ($this->provider->hasFailedOver()) {
            // error_log("Already failed over, using fallback: " . $fallbackStore);
            return $this->createFallbackDriver($fallbackStore);
        }

        try {
            $store = parent::createRedisDriver($config);
            $redisConfig = $config['default'] ?? [];
            
            // error_log("Testing Redis connection to " . ($redisConfig['host'] ?? '127.0.0.1'));
            
            // Wrap Redis instance for failover support
            $redis = $store->getRedis();
            $wrapper = new RedisWrapper($redis, $this->provider);
            
            // Test connection
            try {
                $wrapper->connect(
                    $redisConfig['host'] ?? '127.0.0.1',
                    (int)($redisConfig['port'] ?? 6379),
                    (float)($redisConfig['timeout'] ?? 0.0),
                    null,
                    (int)($redisConfig['retry_interval'] ?? 0)
                );
            } catch (Throwable $e) {
                $this->handleFailover();
                throw $e;
            }
            
            // error_log("Redis connection successful");
            
            // Replace Redis instance with our wrapper
            $store->setRedis($wrapper);
            
            return $store;
        } catch (Throwable $e) {
            // error_log("Redis connection failed: " . $e->getMessage());
            // error_log("Stack trace: " . $e->getTraceAsString());
            
            $this->handleFailover();
            
            return $this->createFallbackDriver($fallbackStore);
        }
    }
    
    /**
     * Create a fallback driver.
     *
     * @param string $driver
     * @return \Illuminate\Cache\Repository
     */
    protected function createFallbackDriver(string $driver)
    {
        if ($driver === 'array') {
            // For array store, we don't need any connection configuration
            return $this->repository(new ArrayStore);
        }
        
        // For other drivers like 'database', use the standard store creation
        return parent::store($driver);
    }
    
    /**
     * Handle failover to backup store.
     */
    protected function handleFailover(): void
    {
        if ($this->provider->hasFailedOver()) {
            // error_log("Already failed over");
            return;
        }
        
        // error_log("Handling failover...");
        $this->provider->setFailedOver(true);
        
        $fallbackStore = $this->getFallbackStore();
        $currentStore = $this->getDefaultDriver();
        // error_log("Setting failover flag to true. Switching to " . $fallbackStore);
        
        // Update default cache store
        $this->app['config']->set('cache.default', $fallbackStore);
        
        // Log to the Laravel log
        // \Illuminate\Support\Facades\Log::warning('Cache store failed, switching to fallback store', [
        //     'from' => $currentStore,
        //     'to' => $fallbackStore,
        //     'error' => 'Connection refused'
        // ]);
        
        // Update default store
        $this->app['config']->set('cache.default', $fallbackStore);
        
        // Clear cached drivers
        if (isset($this->drivers['redis'])) {
            unset($this->drivers['redis']);
        }
    }
    
    /**
     * Get configured fallback store.
     *
     * @return string
     */
    protected function getFallbackStore(): string
    {
        return $this->app['config']->get(
            Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE,
            Configuration::CACHE_DRIVER_ARRAY
        );
    }
}
