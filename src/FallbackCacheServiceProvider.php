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
    /** @var bool */
    public function setFailedOver(bool $value): void
    {
        // error_log("Setting failover state to: " . ($value ? "true" : "false"));
        FailoverState::setFailedOver($value);
    }
    
    public function hasFailedOver(): bool
    {
        $result = FailoverState::hasFailedOver();
        // error_log("Checking failover state: " . ($result ? "true" : "false"));
        return $result;
    }

    protected function getRedisWrapper($redis)
    {
        return new RedisWrapper($redis, $this);
    }

    public function register(): void
    {
        parent::register();

        // Make sure the fallback store is properly configured
        $fallbackStore = $this->app['config']->get(
            Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, 
            Configuration::CACHE_DRIVER_ARRAY
        );
        
        $this->app['config']->set('cache.stores.' . $fallbackStore, [
            'driver' => $fallbackStore
        ]);
        
        // Initialize failover state
        $this->setFailedOver(false);

        FailoverState::setFailedOver(false);
        
        $provider = $this;

        // Override Cache manager to handle failover
        $this->app->extend('cache', function ($manager, $app) use ($provider) {
            return new FallbackCacheManager($app, $provider);
        });

        $this->app->singleton(Configuration::class);
        $this->mergeConfigFrom(__DIR__ . '/Config/fallback-cache.php', Configuration::CONFIG);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/Config/fallback-cache.php' => config_path('fallback-cache.php'),
        ]);

        // Make sure the fallback store is properly configured
        $fallbackStore = Config::get(Configuration::CONFIG . '.' . Configuration::FALLBACK_CACHE_STORE, Configuration::CACHE_DRIVER_ARRAY);
        if (!isset($this->app['config']['cache.stores.' . $fallbackStore])) {
            $this->app['config']['cache.stores.' . $fallbackStore] = [
                'driver' => $fallbackStore
            ];
        }
    }
}
