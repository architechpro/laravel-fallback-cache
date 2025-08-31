# Laravel Fallback Cache

This package provides automatic fallback functionality for Laravel cache drivers. If your primary cache store (like Redis) becomes unavailable, the package will automatically switch to a fallback store (like file or array cache) to keep your application running.

## Features

- Automatic detection of cache store failures
- Seamless switching to fallback store
- Configurable fallback cache store
- No code changes required in your application
- Detailed logging of failover events

## Installation

1. Require the package via composer:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/architechpro/laravel-fallback-cache"
        }
    ],
    "require": {
        "architechpro/laravel-fallback-cache": "dev-main"
    }
}
```

Then run:

```bash
composer update architechpro/laravel-fallback-cache
```

2. Register the service provider in your `config/app.php`:

```php
LaravelFallbackCache\FallbackCacheServiceProvider::class
```

**Important:** Register this service provider before any other providers that use cache to ensure proper fallback functionality.

3. Publish the configuration file:

```bash
php artisan vendor:publish --provider="LaravelFallbackCache\FallbackCacheServiceProvider"
```

## Configuration

The package configuration file `fallback-cache.php` allows you to specify the fallback cache store:

```php
return [
    // The cache store to use when the default store fails
    'fallback_cache_store' => env('FALLBACK_CACHE_STORE', 'array'),
    
    // Whether to extend the cache manager (auto-detected for session compatibility)
    'extend_cache_manager' => env('FALLBACK_CACHE_EXTEND_MANAGER', true),
];
```

By default, it will use the array driver as fallback, but you can configure any cache store supported by Laravel.

### Session Manager Compatibility

**Important:** If you're using Laravel's session manager with `session.driver = 'cache'`, the package automatically detects this and disables cache manager extension to prevent conflicts with `ArrayStore::setConnection()` errors.

For detailed information about session compatibility, see [SESSION_MANAGER_COMPATIBILITY.md](SESSION_MANAGER_COMPATIBILITY.md).

## Usage

Once installed and configured, the package works automatically. No code changes are required in your application.

### Example

```php
// Using cache normally
Cache::put('key', 'value', 60);

// If Redis is down, this will automatically use the fallback store
$value = Cache::get('key');
```

### Reset Failover State

If you need to reset the failover state and try using the original cache store again:

```php
\LaravelFallbackCache\Config\Configuration::resetFailedOver();
```

## How it Works

The package:

1. Monitors cache operations for failures
2. If a failure occurs (e.g., Redis connection refused):
   - Switches to the configured fallback store
   - Logs the failure event
   - Maintains application operation

## Logging

When a failover occurs, the package logs a warning message with details about the failure:

```
Redis connection failed. Cache store switched to fallback.
```

## License

This package is open-sourced software licensed under the MIT license.
