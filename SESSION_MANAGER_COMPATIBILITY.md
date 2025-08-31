# Session Manager Compatibility

## Issue Description

When using Laravel's session manager with a cache session driver (`session.driver = 'cache'`), you may encounter the following error:

```
Call to undefined method Illuminate\Cache\ArrayStore::setConnection()
```

This error occurs because the Laravel fallback cache manager extends the base cache manager with additional functionality that may conflict with session storage expectations.

## Production Redis Connection Issues

If you're experiencing Redis connection failures in production that result in 500 errors instead of graceful fallback, this could be due to:

1. **Session driver conflicts**: If your session is configured to use Redis (`session.driver = 'redis'`), the cache manager extension is automatically disabled to prevent conflicts.

2. **Service provider registration order**: The fallback cache service provider must be registered before other providers that use cache.

### Troubleshooting Redis Connection Failures

If you see errors like:
```
php_network_getaddresses: getaddrinfo for [hostname] failed: Name or service not known
```

And the fallback isn't working, check:

1. **Session configuration**: 
   ```bash
   php artisan config:cache && php artisan config:clear
   php artisan tinker
   >>> config('session.driver')
   >>> config('session.store')
   ```

2. **Force enable fallback cache** (if session conflicts are detected):
   ```php
   // In a service provider or early in your application
   app(LaravelFallbackCache\FallbackCacheServiceProvider::class)->forceEnableFallbackCache();
   ```

3. **Check service provider registration**:
   ```php
   // config/app.php - Make sure this is BEFORE other providers that use cache
   'providers' => [
       LaravelFallbackCache\FallbackCacheServiceProvider::class,
       // ... other providers
   ],
   ```

4. **Verify configuration**:
   ```php
   // Check if fallback cache is available
   dd(app()->bound('cache.fallback'));
   
   // Check current cache manager type
   dd(get_class(app('cache')));
   ```

## Root Cause

The session manager expects cache stores to implement certain methods like `setConnection()`. When the fallback cache manager is active, it may provide stores that don't implement all the methods expected by the session manager.

## Solution

The Laravel Fallback Cache package automatically detects when your session driver is set to `'cache'` or `'redis'` and disables the cache manager extension to prevent conflicts.

### Automatic Detection (Recommended)

By default, the package will:
1. Check if `session.driver` is set to `'cache'`
2. If yes, register the fallback cache manager as a separate service (`cache.fallback`) without extending the main cache manager
3. If no, extend the main cache manager as usual

No configuration is required for this automatic behavior.

### Manual Configuration

If you need to manually control when the cache manager is extended, you can use the configuration option:

```php
// config/fallback-cache.php
return [
    'fallback_cache_store' => env('FALLBACK_CACHE_STORE', 'array'),
    'extend_cache_manager' => env('FALLBACK_CACHE_EXTEND_MANAGER', true),
];
```

Set `extend_cache_manager` to `false` to always use the fallback cache as a separate service:

```bash
# .env
FALLBACK_CACHE_EXTEND_MANAGER=false
```

## Usage When Cache Manager Extension is Disabled

When the cache manager extension is disabled (either automatically or manually), you can still use the fallback cache functionality by injecting the `cache.fallback` service:

```php
// Using dependency injection
public function __construct(\LaravelFallbackCache\FallbackCacheManager $fallbackCache)
{
    $this->fallbackCache = $fallbackCache;
}

// Using the service container
$fallbackCache = app('cache.fallback');

// Using facade with explicit store
Cache::store('your-fallback-store')->put('key', 'value');
```

## Testing the Configuration

You can verify the behavior with these tests:

```php
// When session driver is 'cache', cache manager should NOT be extended
Config::set('session.driver', 'cache');
$cacheManager = app('cache');
$this->assertNotInstanceOf(FallbackCacheManager::class, $cacheManager);

// But fallback manager should still be available
$fallbackManager = app('cache.fallback');
$this->assertInstanceOf(FallbackCacheManager::class, $fallbackManager);
```

## Recommendations

1. **Use automatic detection**: The package automatically handles the session driver conflict
2. **Monitor logs**: Check for failover events in your application logs
3. **Test thoroughly**: Verify both normal cache operations and session functionality work correctly
4. **Consider alternatives**: If you frequently encounter session conflicts, consider using a dedicated session store (like Redis or database) separate from your cache configuration

## Laravel Octane Compatibility

This solution is fully compatible with Laravel Octane. The session driver detection works correctly in both traditional and Octane environments.
