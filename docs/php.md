# PHP and Laravel

The Composer package `georgimorozov/redis-sliding-window` (PHP 8.3+, 64-bit). The contract is `docs/design.md`;
this page is how to use it.

## Plain PHP

```php
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;
use GeorgiMorozov\RedisSlidingWindow\ErrorContext;

$redis = new Predis\Client([
    'host' => '127.0.0.1',
    'persistent' => true,          // one connection per PHP-FPM worker, reused across requests
    'timeout' => 1.0,              // connect timeout, seconds
    'read_write_timeout' => 1.0,   // per-command timeout, seconds
]);
// or phpredis: $redis = new Redis(); $redis->pconnect('127.0.0.1', 6379, 1.0, 'rate-limit', 0, 1.0);

$limiter = new SlidingWindowLimiter($redis, 'login', 5, 900_000, FailMode::Closed,
    onError: static function (UnavailableReport $report, ErrorContext $context): void {
        error_log("{$context->name}: {$report->message}");
    });

$result = $limiter->hit($_SERVER['REMOTE_ADDR']);
if (!$result->allowed) {
    http_response_code(429);
    header('Retry-After: ' . $result->retryAfterSeconds());
    exit;
}
```

- The client must talk to one node and set no key prefix (Predis `prefix`, phpredis `OPT_PREFIX`); the
  constructor throws `InvalidArgumentException` otherwise.
- `load()` sends `SCRIPT LOAD` and throws on any error; call it in a deploy or health step if you want a wrong
  password or a missing server to fail loudly. `hit()` works without it (it falls back to `EVAL` once).
- With fail open, `hit()` never throws on an outage (connect, timeout, `LOADING`, `BUSY`, `MASTERDOWN`,
  `READONLY`): it returns an allowed, degraded result and calls `onError`. A server reply that rejects the
  credentials or the configuration throws in both modes.

## Connections

A fresh connection per request, especially a fresh TLS connection, is a known failure mode: every request pays
the handshake, and under load the handshakes exhaust the server's or proxy's connections. Use persistent
connections and short timeouts, as above. phpredis `pconnect()` keys persistent connections by host, port and
timeout, or by `persistent_id`.

## Laravel

The service provider is auto-discovered. Publish the config and define limiters:

```bash
php artisan vendor:publish --tag=redis-sliding-window-config
```

```php
// config/redis-sliding-window.php
'connection' => 'rate-limit',
'limiters' => [
    'login' => ['limit' => 5, 'window_ms' => 900_000, 'fail_mode' => 'closed'],
    'api' => ['limit' => 600, 'window_ms' => 60_000, 'fail_mode' => 'open'],
],
```

Laravel's default `redis.options.prefix` applies to every connection, so give the limiter a connection with an
empty prefix (`config/database.php`):

```php
'rate-limit' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => '0',
    'prefix' => '',
    'persistent' => true,           // per connection: Laravel ignores options.persistent here
    'persistent_id' => 'rate-limit', // phpredis: its own persistent socket
    'conn_uid' => 'rate-limit',     // Predis: its own persistent socket (connections must not share one)
    'timeout' => 1.0,
    'read_timeout' => 1.0,          // phpredis
    'read_write_timeout' => 1.0,    // Predis
],
```

Then on routes:

```php
Route::post('/login', LoginController::class)->middleware('sliding-window:login');
```

The middleware keys requests by the signed-in user's id, else the client IP (behind a proxy, configure Laravel's
trusted proxies so `$request->ip()` is the client's). A denied request becomes a 429 with `Retry-After`. A
degraded result is logged as a warning through Laravel's logger (limiter name, reason and fail mode, never the
identifier), so it reaches whatever your log channel reports to. During an outage that is at most one warning a
minute per limiter: the first degraded result takes a marker in the cache store named by `warning_store` (env
`RSW_WARNING_STORE`, default `file`), and later ones skip the log until it expires. Use a local store such as
`file`, never Redis (the Redis that is down would decide whether to log). It is best effort: the marker is per
cache directory or host, so N servers log up to N warnings a minute, and a store that fails or cannot confirm the
marker logs anyway. `'warning_store' => null` logs every degraded result. For other keys, resolve
`GeorgiMorozov\RedisSlidingWindow\Laravel\Limiters` and call `->get('login')->hit($identifier)`.
