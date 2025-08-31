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
        
        // Register fallback cache manager as a separate service
        $this->app->singleton('cache.fallback', function ($app) {
            return new FallbackCacheManager($app, $this);
        });
        
        // Only extend cache if session driver is not cache or if explicitly enabled
        if ($this->shouldExtendCacheManager()) {
            $this->app->extend('cache', function ($manager, $app) {
                return $app->make('cache.fallback');
            });
        } else {
            // Log when cache manager extension is skipped for debugging
            $sessionDriver = $this->app['config']->get('session.driver');
            $sessionStore = $this->app['config']->get('session.store');
            Log::info('FallbackCache: Cache manager extension skipped', [
                'session_driver' => $sessionDriver,
                'session_store' => $sessionStore,
                'reason' => 'Session compatibility'
            ]);
        }

        $this->app->singleton(Configuration::class);
        $this->mergeConfigFrom(__DIR__ . '/Config/fallback-cache.php', Configuration::CONFIG);
    }
    
    /**
     * Determine if we should extend the cache manager.
     * 
     * @return bool
     */
    protected function shouldExtendCacheManager(): bool
    {
        // Check if explicitly disabled
        if ($this->app['config']->get(Configuration::CONFIG . '.extend_cache_manager', true) === false) {
            return false;
        }
        
        // Don't extend if session driver uses cache or redis to avoid conflicts
        $sessionDriver = $this->app['config']->get('session.driver');
        
        if (in_array($sessionDriver, ['cache', 'redis'])) {
            return false;
        }
        
        // Also check if session.store is set to redis
        $sessionStore = $this->app['config']->get('session.store');
        if ($sessionStore === 'redis') {
            return false;
        }
        
        return true;
    }

    /**
     * Force enable the fallback cache manager (use with caution)
     * This bypasses session compatibility checks
     * 
     * @return void
     */
    public function forceEnableFallbackCache(): void
    {
        if (!$this->app->bound('cache.fallback')) {
            return;
        }
        
        $this->app->extend('cache', function ($manager, $app) {
            return $app->make('cache.fallback');
        });
        
        Log::info('FallbackCache: Cache manager extension force enabled');
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