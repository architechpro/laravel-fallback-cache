<?php

namespace LaravelFallbackCache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LaravelFallbackCache\Config\Configuration;
use Throwable;

class FallbackCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_CACHE_DEFAULT = 'cache.default';

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishConfig();
        
        if (!$this->isCacheStoreHealthy()) {
            $fallbackStore = Configuration::getFallbackCacheStore();
            
            if ($fallbackStore === Config::get(self::CONFIG_CACHE_DEFAULT)) {
                Log::warning('Cannot fallback to the same cache store that failed');
                return;
            }
            
            $this->switchToFallbackCacheStore();
        }
    }

    /**
     * @return void
     */
    private function switchToFallbackCacheStore(): void
    {
        $fallbackStore = Configuration::getFallbackCacheStore();
        
        Log::info(
            'Switching to fallback cache store',
            [
                'from' => Config::get(self::CONFIG_CACHE_DEFAULT),
                'to' => $fallbackStore
            ]
        );
        
        Config::set(
            self::CONFIG_CACHE_DEFAULT,
            $fallbackStore
        );
    }

    /**
     * @return bool
     */
    private function isCacheStoreHealthy(): bool
    {
        try {
            // First check if the store is properly configured
            $defaultStore = Config::get('cache.default');
            if (empty($defaultStore)) {
                Log::error('Default cache store is not configured');
                return false;
            }

            $storeConfig = Config::get("cache.stores.{$defaultStore}");
            if (empty($storeConfig)) {
                Log::error(
                    'Cache store configuration is missing',
                    ['store' => $defaultStore]
                );
                return false;
            }

            Cache::get('health_check_key');
            
        } catch (Throwable $exception) {
            Log::error(
                'Cache store is unhealthy',
                [
                    'store' => Config::get(self::CONFIG_CACHE_DEFAULT),
                    'driver' => Config::get("cache.stores.{$defaultStore}.driver"),
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ]
            );

            return false;
        }

        return true;
    }

    /**
     * @return void
     */
    private function publishConfig(): void
    {
        $this->publishes(
            [
                __DIR__ . '/Config/fallback-cache.php' => config_path('fallback-cache.php'),
            ],
            'fallback-cache'
        );
    }
}