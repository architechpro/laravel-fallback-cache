<?php

namespace LaravelFallbackCache;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LaravelFallbackCache\Config\Configuration;
use Throwable;

class FallbackCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_CACHE_DEFAULT = 'cache.default';
    private bool $hasFailedOver = false;

    /**
     * @return void
     */
    public function register(): void
    {
        parent::register();

        // Intercept Redis connector creation
        $this->app->extend('redis', function ($redis, $app) {
            return new class($redis, $app) extends \Illuminate\Redis\RedisManager {
                protected $app;
                private $hasFailedOver = false;

                public function __construct($redis, $app)
                {
                    parent::__construct($app, $redis->driver, $redis->config);
                    $this->app = $app;
                }

                public function connection($name = null)
                {
                    try {
                        return parent::connection($name);
                    } catch (Throwable $e) {
                        if ($this->hasFailedOver) {
                            throw $e;
                        }

                        $currentStore = Config::get('cache.default');
                        $fallbackStore = Configuration::getFallbackCacheStore();

                        if ($currentStore === $fallbackStore) {
                            throw $e;
                        }

                        Log::warning(
                            'Redis connection failed. Switching cache to fallback store.',
                            [
                                'exception' => get_class($e),
                                'message' => $e->getMessage(),
                                'from_store' => $currentStore,
                                'to_store' => $fallbackStore
                            ]
                        );

                        Config::set('cache.default', $fallbackStore);
                        $this->hasFailedOver = true;

                        // Create a new cache instance with the fallback store
                        Cache::forgetDriver($currentStore);
                        Cache::forgetDriver($fallbackStore);

                        throw $e; // Re-throw to let Cache handle the fallback
                    }
                }
            };
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishConfig();
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