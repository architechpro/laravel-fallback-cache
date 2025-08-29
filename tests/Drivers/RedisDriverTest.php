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

class RedisDriverTest extends TestCase
{
    private const TEST_KEY = 'test_key';
    private const TEST_VALUE = 'test_value';
    
    private FallbackCacheServiceProvider $serviceProvider;
    private $redisManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Redis connection
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->byDefault()
            ->andReturn(serialize(self::TEST_VALUE));
        $redisConnection->shouldReceive('set')
            ->byDefault()
            ->andReturn(true);
            
        $this->redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $this->redisManager->shouldReceive('connection')
            ->byDefault()
            ->andReturn($redisConnection);
            
        $this->app->instance('redis', $this->redisManager);
        
        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
        
        // Set Redis as default
        $this->app['config']->set('cache.default', 'redis');
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
        ]);

        // Configure all possible fallback stores
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

    public function testRedisToArrayFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Mock Redis connection to fail
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->andThrow(new Exception('Redis is not available'));
        $redisConnection->shouldReceive('set')
            ->andThrow(new Exception('Redis is not available'));
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')
            ->andReturn($redisConnection);
            
        $this->app->instance('redis', $redisManager);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testRedisToDatabaseFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'database');
        
        // Mock database store
        $queryBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $queryBuilder->shouldReceive('where')->andReturnSelf();
        $queryBuilder->shouldReceive('first')->andReturn((object)[
            'key' => self::TEST_KEY,
            'value' => serialize(self::TEST_VALUE),
            'expiration' => time() + 3600
        ]);
        $queryBuilder->shouldReceive('insert')->andReturn(true);
        $queryBuilder->shouldReceive('update')->andReturn(true);
        
        $databaseConnection = Mockery::mock(\Illuminate\Database\Connection::class);
        $databaseConnection->shouldReceive('table')->andReturn($queryBuilder);
        
        $this->app->bind('db', function() use ($databaseConnection) {
            $db = Mockery::mock(\Illuminate\Database\DatabaseManager::class);
            $db->shouldReceive('connection')->andReturn($databaseConnection);
            return $db;
        });
        
        // Mock Redis connection to fail
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->andThrow(new Exception('Redis is not available'));
        $redisConnection->shouldReceive('set')
            ->andThrow(new Exception('Redis is not available'));
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')
            ->andReturn($redisConnection);
            
        $this->app->instance('redis', $redisManager);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testRedisToFileFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'file');
        
        // Mock filesystem
        $fileSystem = Mockery::mock(\Illuminate\Filesystem\Filesystem::class);
        $fileSystem->shouldReceive('get')
            ->andReturn(serialize(['value' => self::TEST_VALUE, 'expiration' => time() + 3600]));
        $fileSystem->shouldReceive('put')->andReturn(true);
        $fileSystem->shouldReceive('exists')->andReturn(true);
        $fileSystem->shouldReceive('makeDirectory')->andReturn(true);
        $fileSystem->shouldReceive('delete')->andReturn(true);
        
        $this->app->instance('files', $fileSystem);
        
        // Mock Redis connection to fail
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->andThrow(new Exception('Redis is not available'));
        $redisConnection->shouldReceive('set')
            ->andThrow(new Exception('Redis is not available'));
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')
            ->andReturn($redisConnection);
            
        $this->app->instance('redis', $redisManager);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testSuccessfulRedisOperations(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test Redis operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        $value = $repository->get(self::TEST_KEY);
        
        $this->assertEquals(self::TEST_VALUE, $value);
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
    }

    public function testRedisRecovery(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Setup Redis to fail then recover
        $redisConnection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $redisConnection->shouldReceive('get')
            ->andReturnUsing(function() {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw new Exception('Redis is not available');
                }
                return serialize(self::TEST_VALUE);
            });
            
        $redisConnection->shouldReceive('set')
            ->andReturn(true);
        
        $redisManager = Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redisManager->shouldReceive('connection')
            ->andReturn($redisConnection);
            
        $this->app->instance('redis', $redisManager);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Test initial failure
        $repository = $fallbackManager->store();
        try {
            $repository->get(self::TEST_KEY);
            $this->fail('Redis should have failed');
        } catch (Exception $e) {
            $this->assertTrue($this->serviceProvider->hasFailedOver());
            $this->assertEquals('array', Config::get('cache.default'));
        }
        
        // Test recovery
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'redis');
        
        $repository = $fallbackManager->store();
        $repository->put('new_key', 'new_value', 60);
        
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
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
