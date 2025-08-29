<?php

namespace LaravelFallbackCache\Tests\Drivers;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use LaravelFallbackCache\Config\Configuration;
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
            ->with(self::TEST_KEY)
            ->andReturn(serialize(self::TEST_VALUE));
        $redisConnection->shouldReceive('set')
            ->andReturn(true);
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')
            ->andReturn($redisConnection);
        
        $this->app->instance('redis', $redisManager);
        
        // Simulate array store failure (memory exhaustion)
        $arrayStore = Mockery::mock(ArrayStore::class);
        $arrayStore->shouldReceive('get')
            ->andThrow(new Exception('Out of memory'));
        $arrayStore->shouldReceive('put')
            ->andThrow(new Exception('Out of memory'));
            
        $this->app->instance('cache.store.array', $arrayStore);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testArrayToDatabaseFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'database');
        
        // Mock database store
        $databaseConnection = Mockery::mock(\Illuminate\Database\Connection::class);
        $databaseConnection->shouldReceive('table')
            ->andReturnSelf();
        $databaseConnection->shouldReceive('where')
            ->andReturnSelf();
        $databaseConnection->shouldReceive('first')
            ->andReturn((object)['value' => serialize(self::TEST_VALUE)]);
        $databaseConnection->shouldReceive('insert')
            ->andReturn(true);
            
        $this->app->instance('db.connection', $databaseConnection);
        
        // Simulate array store failure
        $arrayStore = Mockery::mock(ArrayStore::class);
        $arrayStore->shouldReceive('get')
            ->andThrow(new Exception('Out of memory'));
        $arrayStore->shouldReceive('put')
            ->andThrow(new Exception('Out of memory'));
            
        $this->app->instance('cache.store.array', $arrayStore);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testArrayToFileFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'file');
        
        // Mock file store
        $fileSystem = Mockery::mock(\Illuminate\Filesystem\Filesystem::class);
        $fileSystem->shouldReceive('get')
            ->andReturn(serialize(['value' => self::TEST_VALUE, 'expiration' => time() + 3600]));
        $fileSystem->shouldReceive('put')
            ->andReturn(true);
        $fileSystem->shouldReceive('exists')
            ->andReturn(true);
            
        $this->app->instance('files', $fileSystem);
        
        // Simulate array store failure
        $arrayStore = Mockery::mock(ArrayStore::class);
        $arrayStore->shouldReceive('get')
            ->andThrow(new Exception('Out of memory'));
        $arrayStore->shouldReceive('put')
            ->andThrow(new Exception('Out of memory'));
            
        $this->app->instance('cache.store.array', $arrayStore);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
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
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'redis');
        
        // Setup array store to fail then recover
        $arrayStore = Mockery::mock(ArrayStore::class);
        $arrayStore->shouldReceive('get')
            ->times(2)
            ->andReturnUsing(function() {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw new Exception('Out of memory');
                }
                return self::TEST_VALUE;
            });
        
        $arrayStore->shouldReceive('put')
            ->andReturn(true);
            
        $this->app->instance('cache.store.array', $arrayStore);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Test initial failure
        $repository = $fallbackManager->store();
        try {
            $repository->get(self::TEST_KEY);
            $this->fail('Array store should have failed');
        } catch (Exception $e) {
            $this->assertTrue($this->serviceProvider->hasFailedOver());
            $this->assertEquals('redis', Config::get('cache.default'));
        }
        
        // Test recovery
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'array');
        
        $repository = $fallbackManager->store();
        $repository->put('new_key', 'new_value', 60);
        
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
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
