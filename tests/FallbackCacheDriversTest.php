<?php

namespace LaravelFallbackCache\Tests;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\RedisManager;
use LaravelFallbackCache\Config\Configuration;
use LaravelFallbackCache\FallbackCacheManager;
use LaravelFallbackCache\FallbackCacheServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class FallbackCacheDriversTest extends TestCase
{
    private const TEST_KEY = 'test_key';
    private const TEST_VALUE = 'test_value';
    
    private FallbackCacheServiceProvider $serviceProvider;
    private MockObject $redisManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Redis manager mock
        $this->redisManager = $this->createMock(RedisManager::class);
        $this->app->instance('redis', $this->redisManager);
        
        // Create service provider
        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
        
        // Set up base configuration
        $this->app['config']->set('cache.default', 'redis');
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
        ]);

        // Configure fallback store options
        $this->app['config']->set('cache.stores.array', [
            'driver' => 'array'
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

    /**
     * Test Redis to array cache fallback
     */
    public function testRedisToArrayFallback(): void
    {
        // Configure array as fallback store
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Setup Redis manager to fail
        $this->redisManager->method('connection')
            ->willThrowException(new Exception('Connection refused'));
        
        // Create fallback manager
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Attempt Redis operation - should trigger fallback
        $repository = $fallbackManager->store();
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        // Verify fallback occurred and array store is being used
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    /**
     * Test Redis to database fallback
     */
    public function testRedisToDatabaseFallback(): void
    {
        // Configure database as fallback store
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'database');
        
        // Mock database store
        $databaseStore = Mockery::mock(DatabaseStore::class);
        $databaseStore->shouldReceive('get')
            ->with(self::TEST_KEY)
            ->andReturn(self::TEST_VALUE);
        $databaseStore->shouldReceive('put')
            ->with(self::TEST_KEY, self::TEST_VALUE, 60)
            ->andReturn(true);
        
        // Register database store
        $this->app->instance('cache.store.database', $databaseStore);
        
        // Setup Redis manager to fail
        $this->redisManager->method('connection')
            ->willThrowException(new Exception('Connection refused'));
        
        // Create fallback manager
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Attempt Redis operation - should trigger fallback
        $repository = $fallbackManager->store();
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        // Verify fallback occurred and database store is being used
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    /**
     * Test Redis to file fallback
     */
    public function testRedisToFileFallback(): void
    {
        // Configure file as fallback store
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'file');
        
        // Mock file store
        $fileStore = Mockery::mock(FileStore::class);
        $fileStore->shouldReceive('get')
            ->with(self::TEST_KEY)
            ->andReturn(self::TEST_VALUE);
        $fileStore->shouldReceive('put')
            ->with(self::TEST_KEY, self::TEST_VALUE, 60)
            ->andReturn(true);
        
        // Register file store
        $this->app->instance('cache.store.file', $fileStore);
        
        // Setup Redis manager to fail
        $this->redisManager->method('connection')
            ->willThrowException(new Exception('Connection refused'));
        
        // Create fallback manager
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Attempt Redis operation - should trigger fallback
        $repository = $fallbackManager->store();
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        // Verify fallback occurred and file store is being used
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    /**
     * Test successful Redis operations
     */
    public function testSuccessfulRedisOperations(): void
    {
        // Configure array as fallback store (shouldn't be used)
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Setup Redis manager for success
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->with(self::TEST_KEY)
            ->andReturn(serialize(self::TEST_VALUE));
        $redisConnection->shouldReceive('set')
            ->andReturn(true);
        
        $this->redisManager->method('connection')
            ->willReturn($redisConnection);
        
        // Create fallback manager
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Test Redis operations
        $repository = $fallbackManager->store();
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        $value = $repository->get(self::TEST_KEY);
        
        // Verify Redis is being used successfully
        $this->assertEquals(self::TEST_VALUE, $value);
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
    }

    /**
     * Test Redis recovery after failure
     */
    public function testRedisRecovery(): void
    {
        // Configure array as fallback store
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Setup Redis manager to fail then succeed
        $this->redisManager->expects($this->exactly(2))
            ->method('connection')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new Exception('Connection refused')),
                $this->createMock(\Illuminate\Redis\Connections\Connection::class)
            );
        
        // Create fallback manager
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // First attempt - should fail to array
        $repository = $fallbackManager->store();
        try {
            $repository->get(self::TEST_KEY);
            $this->fail('Redis should have failed');
        } catch (Exception $e) {
            $this->assertTrue($this->serviceProvider->hasFailedOver());
            $this->assertEquals('array', Config::get('cache.default'));
        }
        
        // Reset failover state
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'redis');
        
        // Second attempt - should succeed with Redis
        $repository = $fallbackManager->store();
        $repository->put('new_key', 'new_value', 60);
        
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
    }

    /**
     * Get package providers
     */
    protected function getPackageProviders($app): array
    {
        return [
            FallbackCacheServiceProvider::class,
        ];
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
