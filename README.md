# Laravel Fallback Cache

This simple package aims to handle cache store downtimes.

Let's say your default cache driver is redis and you experience
it's downtime. Your whole app would be down because of that.

This package tries retrieving a value from cache store
and if exception is thrown, switches cache driver / store
to a one specified in config.

## Installation

1. in your Laravel projects, you can just write

```
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

Then you can run

```
    composer update architechpro/laravel-fallback-cache
```

This package does not automatically register service
provider, since you might be using cache in other providers. So you
need to register it in your application's `app.php`:

```php
LaravelFallbackCache\FallbackCacheServiceProvider::class
```

**Note:** if you are using cache in other providers, register
this one before them.

2. Publish `fallback-cache.php` config file:

```
    php artisan vendor:publish --tag=fallback-cache
```

That's it!
