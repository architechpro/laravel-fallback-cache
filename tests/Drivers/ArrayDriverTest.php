<?php

namespace LaravelFallbackCache\Tests\Drivers;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use LaravelFallbackCache\Config\Configuration;
use LaravelFallbackCache\Config\FailoverState;
use LaravelFallbackCache\FallbackCacheManager;
use LaravelFallbackCache\FallbackCacheServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;

class ArrayDriverTest extends TestCase
{
    private const TEST_KEY = 'test_key';
    private const TEST_VALUE = 'test_value';
    
    private FallbackCacheServiceProvider $serviceProvider;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
        
        // Set Array as default
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('cache.stores.array', [
            'driver' => 'array'
        ]);

        // Configure all possible fallback stores
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
        ]);

        $this->app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
        ]);

        $this->app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ]);
    }

    public function testArrayToRedisFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'redis');
        
        // Mock Redis store
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->with('laravel_cache:' . self::TEST_KEY)
            ->andReturn(serialize(self::TEST_VALUE));
        $redisConnection->shouldReceive('get')
            ->with(self::TEST_KEY)
            ->andReturn(serialize(self::TEST_VALUE));
        $redisConnection->shouldReceive('set')
            ->andReturn(true);
        $redisConnection->shouldReceive('setex')
            ->andReturn(true);
        $redisConnection->shouldReceive('connect')
            ->andReturn(true);
        $redisConnection->shouldReceive('ping')
            ->andReturn(true);
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')
            ->andReturn($redisConnection);
        
        $this->app->instance('redis', $redisManager);
        
        // Create a custom failing FallbackCacheManager that simulates array driver failure
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createArrayDriver(array $config)
            {
                $fallbackStore = $this->getFallbackStore();

                if ($this->provider->hasFailedOver()) {
                    return $this->createFallbackDriver($fallbackStore);
                }

                // Simulate array driver failure (e.g., out of memory)
                $this->handleFailover();
                return $this->createFallbackDriver($fallbackStore);
            }
        };
        
        $repository = $failingManager->store();
        
        // Test fallback operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testArrayToDatabaseFallback(): void
    {
        FailoverState::reset();
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'database');
        
        // Setup in-memory SQLite database for testing
        Config::set('database.default', 'testdb');
        Config::set('database.connections.testdb', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        
        // Create cache table
        \DB::statement('CREATE TABLE cache (
            key VARCHAR(255) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL,
            expiration INTEGER NOT NULL
        )');
        
        // Create a custom failing FallbackCacheManager that simulates array driver failure
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createArrayDriver(array $config)
            {
                $fallbackStore = $this->getFallbackStore();

                if ($this->provider->hasFailedOver()) {
                    return $this->createFallbackDriver($fallbackStore);
                }

                // Simulate array driver failure (e.g., out of memory)
                $this->handleFailover();
                return $this->createFallbackDriver($fallbackStore);
            }
        };
        
        $repository = $failingManager->store();
        
        // Test fallback operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
        $this->assertEquals('database', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testArrayToFileFallback(): void
    {
        FailoverState::reset();
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'file');
        
        // Create a custom failing FallbackCacheManager that simulates array driver failure
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createArrayDriver(array $config)
            {
                $fallbackStore = $this->getFallbackStore();

                if ($this->provider->hasFailedOver()) {
                    return $this->createFallbackDriver($fallbackStore);
                }

                // Simulate array driver failure (e.g., out of memory)
                $this->handleFailover();
                return $this->createFallbackDriver($fallbackStore);
            }
        };
        
        $repository = $failingManager->store();
        
        // Test fallback operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testSuccessfulArrayOperations(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'redis');
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test array operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        $value = $repository->get(self::TEST_KEY);
        
        $this->assertEquals(self::TEST_VALUE, $value);
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
    }

    public function testArrayRecovery(): void
    {
        FailoverState::reset();
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'redis');
        
        // Setup Redis mocks
        $redisConnection = Mockery::mock(\Predis\Client::class);
        $redisConnection->shouldReceive('set')->andReturn(true);
        $redisConnection->shouldReceive('setex')->andReturn(true);
        $redisConnection->shouldReceive('get')->andReturn(serialize(self::TEST_VALUE));
        $redisConnection->shouldReceive('del')->andReturn(1);
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')->andReturn($redisConnection);
        
        $this->app->instance('redis', $redisManager);
        
        // Create a custom failing FallbackCacheManager that simulates array driver failure
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createArrayDriver(array $config)
            {
                $fallbackStore = $this->getFallbackStore();

                if ($this->provider->hasFailedOver()) {
                    return $this->createFallbackDriver($fallbackStore);
                }

                // Simulate array driver failure (e.g., out of memory)
                $this->handleFailover();
                return $this->createFallbackDriver($fallbackStore);
            }
        };
        
        $repository = $failingManager->store();
        
        // Test failover to Redis
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    protected function getPackageProviders($app): array
    {
        return [FallbackCacheServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
