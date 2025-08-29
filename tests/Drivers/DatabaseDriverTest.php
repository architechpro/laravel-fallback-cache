<?php

namespace LaravelFallbackCache\Tests\Drivers;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Connection;
use LaravelFallbackCache\Config\Configuration;
use LaravelFallbackCache\FallbackCacheManager;
use LaravelFallbackCache\FallbackCacheServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use PDOException;

class DatabaseDriverTest extends TestCase
{
    private const TEST_KEY = 'test_key';
    private const TEST_VALUE = 'test_value';
    
    private FallbackCacheServiceProvider $serviceProvider;
    private $databaseConnection;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock database connection and query builder
        $queryBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $queryBuilder->shouldReceive('where')->andReturnSelf();
        $queryBuilder->shouldReceive('first')->andReturn((object)[
            'key' => self::TEST_KEY,
            'value' => serialize(self::TEST_VALUE),
            'expiration' => time() + 3600
        ]);
        $queryBuilder->shouldReceive('insert')->andReturn(true);
        $queryBuilder->shouldReceive('update')->andReturn(true);
        
        $this->databaseConnection = Mockery::mock(Connection::class);
        $this->databaseConnection->shouldReceive('table')->andReturn($queryBuilder);
        
        $this->app->bind('db', function() {
            $db = Mockery::mock(\Illuminate\Database\DatabaseManager::class);
            $db->shouldReceive('connection')->andReturn($this->databaseConnection);
            return $db;
        });
        
        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
        
        // Set Database as default
        $this->app['config']->set('cache.default', 'database');
        $this->app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
        ]);

        // Configure all possible fallback stores
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
        ]);

        $this->app['config']->set('cache.stores.array', [
            'driver' => 'array'
        ]);

        $this->app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => '/tmp/cache/data',
        ]);
    }

    public function testDatabaseToArrayFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Make database fail
        $this->databaseConnection->shouldReceive('table')
            ->andThrow(new PDOException('Connection failed'));
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testDatabaseToRedisFallback(): void
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
        
        // Make database fail
        $this->databaseConnection->shouldReceive('table')
            ->andThrow(new PDOException('Connection failed'));
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testDatabaseToFileFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'file');
        
        // Mock file store
        $fileSystem = Mockery::mock(\Illuminate\Filesystem\Filesystem::class);
        $fileSystem->shouldReceive('get')->andReturn(serialize(['value' => self::TEST_VALUE, 'expiration' => time() + 3600]));
        $fileSystem->shouldReceive('put')->andReturn(true);
        $fileSystem->shouldReceive('exists')->andReturn(true);
        $fileSystem->shouldReceive('makeDirectory')->andReturn(true);
        $fileSystem->shouldReceive('delete')->andReturn(true);
        
        $this->app->instance('files', $fileSystem);
        
        // Make database fail
        $this->databaseConnection->shouldReceive('table')
            ->andThrow(new PDOException('Connection failed'));
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testSuccessfulDatabaseOperations(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test database operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        $value = $repository->get(self::TEST_KEY);
        
        $this->assertEquals(self::TEST_VALUE, $value);
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
    }

    public function testDatabaseRecovery(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Setup database to fail then recover
        $queryBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $queryBuilder->shouldReceive('where')->andReturnSelf();
        $queryBuilder->shouldReceive('first')->times(2)->andReturnUsing(function() {
            static $calls = 0;
            $calls++;
            if ($calls === 1) {
                throw new PDOException('Connection failed');
            }
            return (object)[
                'key' => self::TEST_KEY,
                'value' => serialize(self::TEST_VALUE),
                'expiration' => time() + 3600
            ];
        });
        $queryBuilder->shouldReceive('insert')->andReturn(true);
        $queryBuilder->shouldReceive('update')->andReturn(true);
        
        $this->databaseConnection->shouldReceive('table')->andReturn($queryBuilder);
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Test initial failure
        $repository = $fallbackManager->store();
        try {
            $repository->get(self::TEST_KEY);
            $this->fail('Database should have failed');
        } catch (Exception $e) {
            $this->assertTrue($this->serviceProvider->hasFailedOver());
            $this->assertEquals('array', Config::get('cache.default'));
        }
        
        // Test recovery
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'database');
        
        $repository = $fallbackManager->store();
        $repository->put('new_key', 'new_value', 60);
        
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
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
