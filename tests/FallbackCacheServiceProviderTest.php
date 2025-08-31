<?php

namespace LaravelFallbackCache\Tests;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use LaravelFallbackCache\Config\Configuration;
use LaravelFallbackCache\FallbackCacheManager;
use LaravelFallbackCache\FallbackCacheServiceProvider;
use Orchestra\Testbench\TestCase;

class FallbackCacheServiceProviderTest extends TestCase
{
    public const TEST_CACHE_STORE = 'test';

    /** @var FallbackCacheServiceProvider */
    private FallbackCacheServiceProvider $serviceProvider;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Setup logging for tests
        $this->app['config']->set('logging.default', 'stderr');
        $this->app['config']->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'level' => 'debug',
        ]);

        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
    }

    /**
     * @return void
     */
    public function testDoesNotSwitchCacheStoreOnSuccess(): void
    {
        $originalStore = 'file';
        $fallbackStore = 'array';

        Config::set('cache.default', $originalStore);
        Config::set('cache.stores.file', [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ]);
        Config::set('cache.stores.array', [
            'driver' => 'array',
        ]);
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, $fallbackStore);
        
        $this->serviceProvider->register();
        $this->serviceProvider->boot();
        
        Cache::put('test_key', 'test_value', 60);
        $this->assertEquals('test_value', Cache::get('test_key'));
        $this->assertEquals($originalStore, Config::get('cache.default'));
    }

    /**
     * @return void
     */
    public function testSwitchesCacheStoreOnFailure(): void
    {
        $originalStore = 'redis';
        $fallbackStore = 'array';

        // Initial configuration
        Config::set('cache.default', $originalStore);
        Config::set('cache.stores.redis', [
            'driver' => 'redis',
            'client' => 'phpredis',
            'default' => [
                'host' => 'non.existent.redis.host',
                'port' => 63799,
                'timeout' => 0.1,
                'retry_interval' => 0
            ]
        ]);
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, $fallbackStore);
        
        // Set up fresh instances
        foreach ([$originalStore, $fallbackStore] as $driver) {
            Cache::forgetDriver($driver);
            $this->app->forgetInstance("cache.store.{$driver}");
        }
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance('redis');
        
        // Register and boot provider
        $this->serviceProvider->register();
        $this->serviceProvider->boot();
        
        // Get store instance to trigger Redis connection attempt
        $manager = $this->app->make('cache');
        $store = $manager->driver($originalStore);
        
        // Give Redis connection time to fail and switch to fallback
        usleep(500000); // 500ms delay
        
        // Verify failover occurred
        $this->assertTrue($this->serviceProvider->hasFailedOver(), 'Failover flag should be set');
        $this->assertEquals($fallbackStore, $this->app['config']->get('cache.default'), 'Should be using fallback store');
        
        // Get a new store instance after failover
        $store = Cache::store();
        
        // Should be able to use cache after failover
        $store->put('test_key', 'fallback_value', 60);
        $this->assertEquals('fallback_value', Cache::get('test_key'));
    }

    /**
     * @test
     */
    public function testRedisUnavailableFailover(): void
    {
        $originalConfig = Config::get('cache.default');
        $fallbackStore = 'array';

        // Configure Redis with invalid connection
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis', [
            'driver' => 'redis',
            'client' => 'phpredis',
            'default' => [
                'host' => 'non.existent.redis.host',
                'port' => 63799,
                'timeout' => 0.1,
                'retry_interval' => 0
            ]
        ]);
        
        // Configure fallback
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, $fallbackStore);
        
        // Clean up instances
        foreach (['redis', $fallbackStore] as $driver) {
            Cache::forgetDriver($driver);
            $this->app->forgetInstance("cache.store.{$driver}");
        }
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance('redis');
        
        // Register and boot
        $this->serviceProvider->register();
        $this->serviceProvider->boot();

        try {
            $manager = $this->app->make('cache');
            $manager->driver('redis')->get('test_key');
        } catch (\Exception $e) {
            // Expected exception, now try getting a value using default store
            try {
                $manager->get('test_key');
            } catch (\Exception $e2) {
                // Expected secondary exception
            }
        }
        
        // Check if failover occurred
        $this->assertTrue($this->serviceProvider->hasFailedOver(), 'Failover flag should be set');
        
        // Get current store instance
        $store = Cache::getStore();

        $this->assertInstanceOf("Illuminate\\Cache\\{$fallbackStore}Store", $store, 'Should be using fallback store');
        
        // Should work with fallback
        Cache::put('test_key', 'test_value', 60);
        $this->assertEquals('test_value', Cache::get('test_key'));
        
        Config::set('cache.default', $originalConfig);
    }

    /**
     * Test that cache manager extension is disabled when session driver is cache
     */
    public function testDoesNotExtendCacheManagerWhenSessionDriverIsCache(): void
    {
        // Set session driver to cache
        Config::set('session.driver', 'cache');
        
        // Create a fresh service provider instance
        $serviceProvider = new FallbackCacheServiceProvider($this->app);
        $serviceProvider->register();
        
        // The cache manager should NOT be extended when session driver is cache
        $cacheManager = $this->app->make('cache');
        
        // The cache manager should be the original Laravel cache manager, not our fallback one
        $this->assertNotInstanceOf(FallbackCacheManager::class, $cacheManager);
        
        // But the fallback manager should still be available as a separate service
        $fallbackManager = $this->app->make('cache.fallback');
        $this->assertInstanceOf(FallbackCacheManager::class, $fallbackManager);
    }

    /**
     * Test that cache manager extension is enabled when session driver is not cache
     */
    public function testExtendsCacheManagerWhenSessionDriverIsNotCache(): void
    {
        // Set session driver to file (not cache)
        Config::set('session.driver', 'file');
        
        // Create a fresh service provider instance
        $serviceProvider = new FallbackCacheServiceProvider($this->app);
        $serviceProvider->register();
        
        // The cache manager SHOULD be extended when session driver is not cache
        $cacheManager = $this->app->make('cache');
        
        // The cache manager should be our fallback one
        $this->assertInstanceOf(FallbackCacheManager::class, $cacheManager);
        
        // And the fallback manager should be the same instance
        $fallbackManager = $this->app->make('cache.fallback');
        $this->assertSame($cacheManager, $fallbackManager);
    }
}
