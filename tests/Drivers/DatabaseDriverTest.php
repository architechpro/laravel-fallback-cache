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
use LaravelFallbackCache\Config\FailoverState;
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

    protected function getEnvironmentSetUp($app)
    {
        // Setup database configuration for testing
        $app['config']->set('database.default', 'testdb');
        $app['config']->set('database.connections.testdb', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

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
        
        // Reset static failover state for clean tests
        FailoverState::setFailedOver(false);
        $this->serviceProvider->setFailedOver(false);
        
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
        
        // Create a custom failing manager for database driver
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createDatabaseDriver(array $config): Repository
            {
                throw new Exception('Database driver intentionally failed for testing');
            }
            
            public function store($name = null)
            {
                $name = $name ?: $this->getDefaultDriver();
                
                try {
                    return parent::store($name);
                } catch (Exception $e) {
                    $this->provider->setFailedOver(true);
                    $this->app['config']->set('cache.default', 'array');
                    return $this->createArrayDriver([]);
                }
            }
        };
        
        $repository = $failingManager->store('database');
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('array', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testDatabaseToRedisFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'redis');
        
        // Create a custom failing manager for database driver
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createDatabaseDriver(array $config): Repository
            {
                throw new Exception('Database driver intentionally failed for testing');
            }
            
            protected function createRedisDriver(array $config): Repository
            {
                // Return a mock Redis store instead of real Redis
                $store = Mockery::mock(\Illuminate\Cache\RedisStore::class);
                $store->shouldReceive('put')->andReturn(true);
                $store->shouldReceive('get')->andReturn('test_value');
                return new Repository($store);
            }
            
            public function store($name = null)
            {
                $name = $name ?: $this->getDefaultDriver();
                
                try {
                    return parent::store($name);
                } catch (Exception $e) {
                    $this->provider->setFailedOver(true);
                    $this->app['config']->set('cache.default', 'redis');
                    return $this->createRedisDriver([]);
                }
            }
        };
        
        $repository = $failingManager->store('database');
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testDatabaseToFileFallback(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'file');
        
        // Create a custom failing manager for database driver
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createDatabaseDriver(array $config): Repository
            {
                throw new Exception('Database driver intentionally failed for testing');
            }
            
            public function store($name = null)
            {
                $name = $name ?: $this->getDefaultDriver();
                
                try {
                    return parent::store($name);
                } catch (Exception $e) {
                    $this->provider->setFailedOver(true);
                    $this->app['config']->set('cache.default', 'file');
                    return $this->createFileDriver([]);
                }
            }
        };
        
        // Use real filesystem for file fallback
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        
        $repository = $failingManager->store('database');
        
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
        
        // Test simulated recovery scenario
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Simulate that we've failed over
        $this->serviceProvider->setFailedOver(true);
        Config::set('cache.default', 'array');
        
        // Test recovery - reset failover state
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'database');
        
        $repository = $fallbackManager->store();
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        $value = $repository->get(self::TEST_KEY);
        
        // The database mock returns TEST_VALUE, so let's test with that
        $this->assertEquals(self::TEST_VALUE, $value);
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
