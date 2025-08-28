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
            Cache::get('');
        } catch (Throwable $exception) {
            Log::error(
                'Cache store is unhealthy',
                [
                    'exception' => get_class($exception),
                    'message'   => $exception->getMessage(),
                    'trace'     => $exception->getTraceAsString()
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