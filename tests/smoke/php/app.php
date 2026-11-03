<?php

declare(strict_types=1);

// The consumer's HTTP front controller for scenario-http-429.php (php -S): limit 2 per minute per client IP.

use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/clients.php';

$limiter = new SlidingWindowLimiter(
    smokeClient((string) getenv('RSW_SMOKE_CLIENT')),
    (string) getenv('RSW_SMOKE_NAME'),
    2,
    60_000,
    FailMode::Closed,
    prefix: 'rsw-smoke:',
);
$result = $limiter->hit((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (!$result->allowed) {
    http_response_code(429);
    header('Retry-After: ' . $result->retryAfterSeconds());
    echo 'Too Many Requests';

    return;
}
header('RateLimit-Remaining: ' . $result->remaining);
echo 'ok';
