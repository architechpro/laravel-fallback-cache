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

class FileDriverTest extends TestCase
{
    private const TEST_KEY = 'test_key';
    private const TEST_VALUE = 'test_value';
    
    private FallbackCacheServiceProvider $serviceProvider;
    private $fileSystem;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock filesystem with all required methods
        $this->fileSystem = Mockery::mock(\Illuminate\Filesystem\Filesystem::class);
        $this->fileSystem->shouldReceive('exists')->byDefault()->andReturn(true);
        $this->fileSystem->shouldReceive('get')->byDefault()->andReturn(serialize(['value' => self::TEST_VALUE, 'expiration' => time() + 3600]));
        $this->fileSystem->shouldReceive('put')->byDefault()->andReturn(true);
        $this->fileSystem->shouldReceive('makeDirectory')->byDefault()->andReturn(true);
        $this->fileSystem->shouldReceive('delete')->byDefault()->andReturn(true);
        
        $this->app->instance('files', $this->fileSystem);
        
        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
        
        // Set File as default with proper path
        $this->app['config']->set('cache.default', 'file');
        $this->app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => '/tmp/cache/data',
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

        $this->app['config']->set('cache.stores.array', [
            'driver' => 'array',
        ]);
    }

    public function testFileToArrayFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Make file operations fail
        $this->fileSystem->shouldReceive('get')
            ->andThrow(new Exception('File system error'));
        $this->fileSystem->shouldReceive('put')
            ->andThrow(new Exception('File system error'));
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testFileToRedisFallback(): void
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
        
        // Make file operations fail
        $this->fileSystem->shouldReceive('get')
            ->andThrow(new Exception('File system error'));
        $this->fileSystem->shouldReceive('put')
            ->andThrow(new Exception('File system error'));
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testFileToDatabaseFallback(): void
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
        
        // Make file operations fail
        $this->fileSystem->shouldReceive('get')
            ->andThrow(new Exception('File system error'));
        $this->fileSystem->shouldReceive('put')
            ->andThrow(new Exception('File system error'));
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('database', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testSuccessfulFileOperations(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        $repository = $fallbackManager->store();
        
        // Test file operations
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        $value = $repository->get(self::TEST_KEY);
        
        $this->assertEquals(self::TEST_VALUE, $value);
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
    }

    public function testFileRecovery(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Setup file system to fail then recover
        $this->fileSystem->shouldReceive('get')
            ->times(2)
            ->andReturnUsing(function() {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw new Exception('File system error');
                }
                return serialize(['value' => self::TEST_VALUE, 'expiration' => time() + 3600]);
            });
        
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Test initial failure
        $repository = $fallbackManager->store();
        try {
            $repository->get(self::TEST_KEY);
            $this->fail('File system should have failed');
        } catch (Exception $e) {
            $this->assertTrue($this->serviceProvider->hasFailedOver());
            $this->assertEquals('array', Config::get('cache.default'));
        }
        
        // Test recovery
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'file');
        
        $repository = $fallbackManager->store();
        $repository->put('new_key', 'new_value', 60);
        
        $this->assertFalse($this->serviceProvider->hasFailedOver());
        $this->assertEquals('file', Config::get('cache.default'));
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
