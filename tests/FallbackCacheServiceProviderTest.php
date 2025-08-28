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
        // Configure Redis as the default cache store
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
            'host' => '127.0.0.1',
            'password' => null,
            'port' => 63799 // Using intentionally wrong port
        ]);

        // Configure fallback cache store
        Config::set('fallback-cache.fallback_cache_store', 'file');
        
        // Initialize the service provider
        $this->serviceProvider->boot();

        // Verify that the cache store has been switched to file
        $this->assertEquals('file', Config::get('cache.default'));

        // Try to use cache to verify it works
        Cache::put('test_key', 'test_value', 60);
        $this->assertEquals('test_value', Cache::get('test_key'));
    }
}
