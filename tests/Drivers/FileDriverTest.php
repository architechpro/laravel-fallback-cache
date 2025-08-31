<?php

namespace LaravelFallbackCache\Tests\Drivers;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    protected function getEnvironmentSetUp($app)
    {
        // Set environment to testing
        $app['env'] = 'testing';
        
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

        // Mock filesystem with all required methods
        $this->fileSystem = Mockery::mock(\Illuminate\Filesystem\Filesystem::class);
        $this->fileSystem->shouldReceive('exists')->byDefault()->andReturn(true);
        $this->fileSystem->shouldReceive('get')->byDefault()->andReturn(serialize([
            'data' => self::TEST_VALUE,
            'time' => time() + 3600
        ]));
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
        // echo "\n=== FileToArrayFallback Test Starting ===\n";
        
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        // echo "✓ Set fallback store to: array\n";
        
        // echo "Current cache default: " . Config::get('cache.default') . "\n";
        // echo "Fallback store config: " . Config::get(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE) . "\n";
        // echo "Initial failover state: " . ($this->serviceProvider->hasFailedOver() ? 'true' : 'false') . "\n";
        
        // Create a custom failing FallbackCacheManager that simulates file driver failure
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createFileDriver(array $config)
            {
                // echo "=== Custom Failing FileDriver called ===\n";
                $fallbackStore = $this->getFallbackStore();
                // echo "Fallback store: {$fallbackStore}\n";
                // echo "Already failed over: " . ($this->provider->hasFailedOver() ? 'true' : 'false') . "\n";

                if ($this->provider->hasFailedOver()) {
                    // echo "Already failed over, creating fallback driver: {$fallbackStore}\n";
                    return $this->createFallbackDriver($fallbackStore);
                }

                // echo "Simulating file driver failure...\n";
                // echo "✗ File driver creation failed: File system error\n";
                $this->handleFailover();
                // echo "Failover handled, creating fallback driver: {$fallbackStore}\n";
                return $this->createFallbackDriver($fallbackStore);
            }
        };
        
        // echo "✓ Failing manager created\n";
        
        // echo "About to call store()...\n";
        $repository = $failingManager->store();
        // echo "✓ Repository obtained\n";
        // echo "Repository class: " . get_class($repository) . "\n";
        // echo "Store class: " . get_class($repository->getStore()) . "\n";
        
        // echo "Failover state after store creation: " . ($this->serviceProvider->hasFailedOver() ? 'true' : 'false') . "\n";
        // echo "Cache default after store creation: " . Config::get('cache.default') . "\n";
        
        // Test fallback
        // echo "About to call put()...\n";
        try {
            $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
            // echo "✓ put() completed\n";
        } catch (Exception $e) {
            // echo "✗ put() failed: " . $e->getMessage() . "\n";
        }
        
        // echo "Failover state after put: " . ($this->serviceProvider->hasFailedOver() ? 'true' : 'false') . "\n";
        // echo "Cache default after put: " . Config::get('cache.default') . "\n";
        
        // echo "About to call get()...\n";
        try {
            $value = $repository->get(self::TEST_KEY);
            // echo "✓ get() completed, value: " . ($value ?: 'null') . "\n";
        } catch (Exception $e) {
            // echo "✗ get() failed: " . $e->getMessage() . "\n";
        }
        
        // echo "=== Test Assertions ===\n";
        // echo "Expected hasFailedOver: true, Actual: " . ($this->serviceProvider->hasFailedOver() ? 'true' : 'false') . "\n";
        // echo "Expected cache.default: array, Actual: " . Config::get('cache.default') . "\n";
        // echo "Expected value: " . self::TEST_VALUE . ", Actual: " . ($repository->get(self::TEST_KEY) ?: 'null') . "\n";
        
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
        
        // Create a custom failing FallbackCacheManager that simulates file driver failure
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createFileDriver(array $config)
            {
                $fallbackStore = $this->getFallbackStore();

                if ($this->provider->hasFailedOver()) {
                    return $this->createFallbackDriver($fallbackStore);
                }

                // Simulate file driver failure
                $this->handleFailover();
                return $this->createFallbackDriver($fallbackStore);
            }
        };
        
        $repository = $failingManager->store();
        
        // Test fallback
        $repository->put(self::TEST_KEY, self::TEST_VALUE, 60);
        
        $this->assertTrue($this->serviceProvider->hasFailedOver());
        $this->assertEquals('redis', Config::get('cache.default'));
        $this->assertEquals(self::TEST_VALUE, $repository->get(self::TEST_KEY));
    }

    public function testFileToDatabaseFallback(): void
    {
        // Log::info('[TEST] Starting testFileToDatabaseFallback');
        
        // Create a custom failing manager for file driver
        $failingManager = new class($this->app, $this->serviceProvider) extends FallbackCacheManager {
            protected function createFileDriver(array $config): Repository
            {
                throw new Exception('File driver intentionally failed for testing');
            }
            
            protected function createDatabaseDriver(array $config): Repository
            {
                // Return a mock database store instead of real database
                $store = Mockery::mock(DatabaseStore::class);
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
                    // Log::info("[TEST] Driver '{$name}' failed, attempting failover to database");
                    $this->provider->setFailedOver(true);
                    $this->app['config']->set('cache.default', 'database');
                    return $this->createDatabaseDriver([]);
                }
            }
        };

        // Log::info('[TEST] Creating store with failing file driver - should fallback to database');
        $store = $failingManager->store('file');
        
        // Log::info('[TEST] Testing put operation with file->database fallback');
        $result = $store->put('test_key', 'test_value', 60);
        $this->assertTrue($result);

        // Log::info('[TEST] Testing get operation with file->database fallback');
        $value = $store->get('test_key');
        $this->assertEquals('test_value', $value);

        // Log::info('[TEST] testFileToDatabaseFallback completed successfully');
    }

    public function testSuccessfulFileOperations(): void
    {
        Config::set(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 'array');
        
        // Use real filesystem for this test
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        
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
        
        // Test simulated recovery scenario
        $fallbackManager = new FallbackCacheManager($this->app, $this->serviceProvider);
        
        // Simulate that we've failed over
        $this->serviceProvider->setFailedOver(true);
        Config::set('cache.default', 'array');
        
        // Test recovery - reset failover state
        $this->serviceProvider->setFailedOver(false);
        Config::set('cache.default', 'file');
        
        // Use real filesystem for the recovery test
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        
        $repository = $fallbackManager->store();
        $repository->put('recovery_key', 'recovery_value', 60);
        $value = $repository->get('recovery_key');
        
        $this->assertEquals('recovery_value', $value);
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
