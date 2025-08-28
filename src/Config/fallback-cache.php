<?php

use LaravelFallbackCache\Config\Configuration;

return [
    /**
     * `database` is recommended here, but for compatibility reasons it defaults to `array`.
     *
     * Note: `database` driver requires migration.
     * @see https://laravel.com/docs/12.x/cache#prerequisites-database
     */
    Configuration::FALLBACK_CACHE_STORE => env(
        'FALLBACK_CACHE_STORE',
        Configuration::CACHE_DRIVER_ARRAY
    )
];
