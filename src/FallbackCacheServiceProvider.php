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
            $this->switchToFallbackCacheStore();
        }
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
            
            // Only test connection for Redis store
            if ($store === 'redis') {
                $redis = Cache::store('redis')->getRedis();
                
                // This command has minimal overhead and will fail fast if Redis is down
                $redis->ping();
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
