<?php

namespace LaravelFallbackCache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;
use LaravelFallbackCache\Config\Configuration;
use Throwable;

class FallbackCacheServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public const CONFIG_CACHE_DEFAULT = 'cache.default';

    /**
     * @return void
     */
    public function register(): void
    {
        if (!$this->isCacheStoreHealthy()) {
            $this->switchToFallbackCacheStore();
        }
    }

    public function boot(): void
    {
        $this->publishConfig();
    }

    /**
     * @return void
     */
    private function switchToFallbackCacheStore(): void
    {
        Config::set(
            self::CONFIG_CACHE_DEFAULT,
            Configuration::getFallbackCacheStore()
        );
    }

    /**
     * @return bool
     */
    private function isCacheStoreHealthy(): bool
    {
        try {
            $store = Config::get(self::CONFIG_CACHE_DEFAULT);
            
            if ($store === 'redis') {
                $redis = Cache::store('redis')->getRedis();
                
                // First check if Redis is responding
                $redis->ping();
                
                // Then try to perform a read operation
                $redis->get('health_check_key');
            } else {
                // For non-Redis stores, try a simple get operation
                Cache::store($store)->get('health_check_key');
            }
            
            return true;
        } catch (Throwable $exception) {
            Log::error(
                'Cache store is unhealthy',
                [
                    'exception' => get_class($exception),
                    'message'   => $exception->getMessage(),
                    'trace'     => $exception->getTraceAsString(),
                    'store' => $store ?? Config::get(self::CONFIG_CACHE_DEFAULT)
                ]
            );

            return false;
        }
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
