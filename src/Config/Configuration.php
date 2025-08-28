<?php

namespace LaravelFallbackCache\Config;

use Illuminate\Support\Facades\Config;

class Configuration
{
    public const CACHE_DRIVER_ARRAY = 'array';
    public const CONFIG = 'fallback-cache';
    public const FALLBACK_CACHE_STORE = 'fallback_store';

    /**
     * @return string
     */
    public static function getFallbackCacheStore(): string
    {
        return Config::get(self::CONFIG)[self::FALLBACK_CACHE_STORE] ?? self::CACHE_DRIVER_ARRAY;
    }
    
    /**
     * Reset the failover state so that the original cache store can be used again.
     * 
     * @return void
     */
    public static function resetFailedOver(): void
    {
        /** @var \LaravelFallbackCache\FallbackCacheServiceProvider $provider */
        $provider = app()->getProvider(\LaravelFallbackCache\FallbackCacheServiceProvider::class);
        $provider->hasFailedOver = false;
        
        // Reset cache driver instances
        Cache::forgetDriver(Config::get('cache.default'));
        app()->forgetInstance('cache.store');
        app()->forgetInstance('cache');
    }
}
