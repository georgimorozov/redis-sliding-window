<?php

declare(strict_types=1);

// Published with: php artisan vendor:publish --tag=redis-sliding-window-config
// Use the limiters with the `sliding-window:<name>` route middleware, or resolve
// GeorgiMorozov\RedisSlidingWindow\Laravel\Limiters and call ->get('<name>')->hit($identifier).

return [
    // A connection from config/database.php "redis". It must set no key prefix: give it 'prefix' => ''
    // (Laravel's default "options.prefix" applies to every connection otherwise). Persistent connections
    // with read timeouts are recommended (docs/php.md).
    'connection' => env('RSW_REDIS_CONNECTION', 'default'),

    // Prepended to every bucket key: empty, or segments that each end in ":", none empty or all digits.
    'prefix' => env('RSW_PREFIX', 'rsw:'),

    // Degraded-result warnings are logged at most once a minute per limiter, using a marker in this Laravel cache
    // store. Use a local store such as "file", never Redis (it is the thing that is down). Best effort: the scope
    // is the store's cache directory or host, so N servers log up to N warnings a minute. null logs every result.
    'warning_store' => env('RSW_WARNING_STORE', 'file'),

    // name => ['limit' => requests per window, 'window_ms' => milliseconds, 'fail_mode' => 'open' or 'closed']
    'limiters' => [
        // 'login' => ['limit' => 5, 'window_ms' => 900_000, 'fail_mode' => 'closed'],
    ],
];
