<?php

namespace LaravelFallbackCache;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\ArrayStore;
use LaravelFallbackCache\Config\Configuration;
use Throwable;

class FallbackCacheManager extends CacheManager
{
    protected FallbackCacheServiceProvider $provider;
    
    public function __construct($app, FallbackCacheServiceProvider $provider)
    {
        parent::__construct($app);
        $this->provider = $provider;
        
        // Bind manager for failover access
        // $app->instance(self::class, $this);
    }

    /**
     * Create Array cache driver with failover support.
     *
     * @param array $config
     * @return \Illuminate\Cache\Repository
     */
    protected function createArrayDriver(array $config)
    {
        $fallbackStore = $this->getFallbackStore();

        if ($this->provider->hasFailedOver()) {
            return $this->createFallbackDriver($fallbackStore);
        }

        try {
            return parent::createArrayDriver($config);
        } catch (Throwable $e) {
            $this->handleFailover();
            return $this->createFallbackDriver($fallbackStore);
        }
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
        
        // Add specific configuration based on driver type
        if ($fallbackStore === 'database') {
            $fallbackConfig['table'] = 'cache';
            $fallbackConfig['connection'] = null;
            $fallbackConfig['lock_connection'] = null;
        } elseif ($fallbackStore === 'file') {
            $fallbackConfig['path'] = storage_path('framework/cache/data');
        }
        
        $this->app['config']->set("cache.stores.{$fallbackStore}", $fallbackConfig);

        if ($this->provider->hasFailedOver()) {
            // error_log("Already failed over, using fallback: " . $fallbackStore);
            return $this->createFallbackDriver($fallbackStore);
        }

        try {
            $store = parent::createRedisDriver($config);
            $redisConfig = $config['default'] ?? [];
            
            // Wrap Redis instance for failover support
            $redis = $store->getRedis();
            $wrapper = new RedisWrapper($redis, $this->provider);
            
            // Skip connection test in test environment
            if ($this->app->environment() !== 'testing') {
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
            }
            
            // Replace Redis instance with our wrapper
            $store->setRedis($wrapper);
            
            return $store;
        } catch (Throwable $e) {
            $this->handleFailover();
            
            return $this->createFallbackDriver($fallbackStore);
        }
    }
    
    /**
     * Create File cache driver with failover support.
     *
     * @param array $config
     * @return \Illuminate\Cache\Repository
     */
    protected function createFileDriver(array $config)
    {
        // echo "=== FallbackCacheManager::createFileDriver() called ===\n";
        $fallbackStore = $this->getFallbackStore();
        // echo "Fallback store: {$fallbackStore}\n";
        // echo "Already failed over: " . ($this->provider->hasFailedOver() ? 'true' : 'false') . "\n";

        if ($this->provider->hasFailedOver()) {
            // echo "Already failed over, creating fallback driver: {$fallbackStore}\n";
            return $this->createFallbackDriver($fallbackStore);
        }

        try {
            // echo "Creating parent file driver...\n";
            $driver = parent::createFileDriver($config);
            // echo "✓ Parent file driver created successfully\n";
            // echo "Driver class: " . get_class($driver) . "\n";
            // echo "Store class: " . get_class($driver->getStore()) . "\n";
            return $driver;
        } catch (Throwable $e) {
            // echo "✗ File driver creation failed: " . $e->getMessage() . "\n";
            // echo "Stack trace: " . $e->getTraceAsString() . "\n";
            $this->handleFailover();
            return $this->createFallbackDriver($fallbackStore);
        }
    }
    
    /**
     * Create Database cache driver with failover support.
     *
     * @param array $config
     * @return \Illuminate\Cache\Repository
     */
    protected function createDatabaseDriver(array $config)
    {
        $fallbackStore = $this->getFallbackStore();

        if ($this->provider->hasFailedOver()) {
            return $this->createFallbackDriver($fallbackStore);
        }

        try {
            return parent::createDatabaseDriver($config);
        } catch (Throwable $e) {
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
        
        // Get the driver configuration
        $config = $this->app['config']["cache.stores.{$driver}"];
        
        if ($driver === 'file') {
            return parent::createFileDriver($config);
        } elseif ($driver === 'database') {
            return parent::createDatabaseDriver($config);
        } elseif ($driver === 'redis') {
            return parent::createRedisDriver($config);
        }
        
        // For other drivers, use the standard store creation
        return parent::store($driver);
    }
    
    /**
     * Handle failover to backup store.
     */
    protected function handleFailover(): void
    {
        if ($this->provider->hasFailedOver()) {
            return;
        }
        
        $this->provider->setFailedOver(true);
        
        $fallbackStore = $this->getFallbackStore();
        $currentStore = $this->getDefaultDriver();
        
        // Update default cache store
        $this->app['config']->set('cache.default', $fallbackStore);
        
        // Clear cached drivers to force recreation
        if (isset($this->drivers['redis'])) {
            unset($this->drivers['redis']);
        }
        if (isset($this->drivers['array'])) {
            unset($this->drivers['array']);
        }
        if (isset($this->drivers['file'])) {
            unset($this->drivers['file']);
        }
        if (isset($this->drivers['database'])) {
            unset($this->drivers['database']);
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
