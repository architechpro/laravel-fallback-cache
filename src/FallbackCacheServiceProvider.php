<?php

namespace LaravelFallbackCache;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LaravelFallbackCache\Config\Configuration;
use LaravelFallbackCache\Config\FailoverState;
use Throwable;

class FallbackCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_CACHE_DEFAULT = 'cache.default';

    public function setFailedOver(bool $value): void
    {
        FailoverState::setFailedOver($value);
    }
    
    public function hasFailedOver(): bool
    {
        return FailoverState::hasFailedOver();
    }

    protected function getRedisWrapper(object $redis): RedisWrapper
    {
        return new RedisWrapper($redis, $this);
    }

    public function register(): void
    {
        parent::register();

        $this->configureFallbackStore();
        
        // Initialize failover state
        $this->setFailedOver(false);
        
        // Override Cache manager to handle failover
        $this->app->extend('cache', function ($manager, $app) {
            return new FallbackCacheManager($app, $this);
        });

        $this->app->singleton(Configuration::class);
        $this->mergeConfigFrom(__DIR__ . '/Config/fallback-cache.php', Configuration::CONFIG);
    }

    protected function configureFallbackStore(): void 
    {
        $fallbackStore = Config::get(
            Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE,
            Configuration::CACHE_DRIVER_ARRAY
        );

        if (!isset($this->app['config']['cache.stores.' . $fallbackStore])) {
            $config = ['driver' => $fallbackStore];
            
            // Add driver-specific configuration
            if ($fallbackStore === 'database') {
                $config['table'] = 'cache';
                $config['connection'] = null;
                $config['lock_connection'] = null;
            } elseif ($fallbackStore === 'file') {
                $config['path'] = storage_path('framework/cache/data');
            }
            
            $this->app['config']->set('cache.stores.' . $fallbackStore, $config);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/Config/fallback-cache.php' => config_path('fallback-cache.php'),
        ]);

        $this->configureFallbackStore();
    }
}
