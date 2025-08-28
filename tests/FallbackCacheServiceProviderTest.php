<?php

namespace LaravelFallbackCache\Tests;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LaravelFallbackCache\Config\Configuration;
use LaravelFallbackCache\FallbackCacheServiceProvider;
use Mockery;
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

        $this->serviceProvider = new FallbackCacheServiceProvider($this->app);
    }

    /**
     * @return void
     */
    public function testDoesNotSwitchCacheStore(): void
    {
        Cache::shouldReceive('get')->once()->andReturnNull();
        Config::shouldReceive('set')->never();

        $this->serviceProvider->boot();
    }

    /**
     * @return void
     */
    public function testSwitchesCacheStore(): void
    {
        /** @var Exception $exception */
        $exception = Mockery::mock(Exception::class)
            ->shouldReceive([
                'getMessage'       => '',
                'getTraceAsString' => ''
            ])->getMock();

        Cache::shouldReceive('get')->once()->andThrow($exception);
        Config::shouldReceive('get')
            ->once()
            ->with(Configuration::CONFIG)
            ->andReturn([
                Configuration::FALLBACK_CACHE_STORE => self::TEST_CACHE_STORE
            ]);
        Config::shouldReceive('set')->once()->with(
            FallbackCacheServiceProvider::CONFIG_CACHE_DEFAULT,
            self::TEST_CACHE_STORE
        );
        Log::shouldReceive('error')->once();

        $this->serviceProvider->boot();
    }

    /**
     * @test
     */
    public function testRedisUnavailableFailover(): void
    {
        $testHandler = new \Monolog\Handler\TestHandler();
        Log::getLogger()->pushHandler($testHandler);
        
        $originalConfig = Config::get('cache.default');
        $fallbackStore = 'array';

        try {
            $this->assertTrue(extension_loaded('redis'), 'Redis extension is not loaded');
            
            // Configure array store as fallback
            Config::set('cache.stores.array', [
                'driver' => 'array'
            ]);
            
            // Configure Redis with invalid connection
            Config::set('cache.default', 'redis');
            Config::set('cache.stores.redis', [
                'driver' => 'redis',
                'client' => 'phpredis',
                'prefix' => 'test_',
                'default' => [
                    'host' => '127.0.0.1',
                    'port' => 63799,
                    'timeout' => 0.1
                ]
            ]);
            
            // Configure fallback
            Config::set('fallback-cache.fallback_cache_store', $fallbackStore);
            
            // Clear instances
            $this->app->forgetInstance('redis');
            $this->app->forgetInstance('cache');
            Cache::forgetDriver('redis');
            Cache::forgetDriver($fallbackStore);
            
            // Register service provider
            $this->serviceProvider->register();
            $this->serviceProvider->boot();

            // Should still be redis initially
            $this->assertEquals('redis', Config::get('cache.default'));

            try {
                // This should fail and trigger fallback
                Cache::store('redis')->put('test_key', 'test_value', 60);
            } catch (\Throwable $e) {
                // Expected Redis error, now try with default store
                Cache::put('test_key', 'test_value', 60);
            }
            
            // Should have switched to array store
            $this->assertEquals($fallbackStore, Config::get('cache.default'));
            
            // Should be able to get the value from array store
            $this->assertEquals('test_value', Cache::get('test_key'));
            
            // Verify logs
            $found = false;
            foreach ($testHandler->getRecords() as $record) {
                if (str_contains($record['message'], 'Redis connection failed')) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected fallback warning log not found');
            
        } finally {
            Config::set('cache.default', $originalConfig);
            Log::getLogger()->popHandler();
        }
    }
}
