<?php

declare(strict_types=1);

// One process of the concurrent-burst test: waits until <start> (microtime), sends one hit() through its own
// <predis|phpredis> connection and prints "allowed" or "denied" (or "degraded").

use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\Server;

require __DIR__ . '/../../../../vendor/autoload.php';

[, $kind, $name, $start] = $argv;
$client = Server::client($kind === 'predis' ? 'predis' : 'phpredis');
if ($client instanceof \Redis) {
    $client->ping();
} else {
    $client->connect();
}
$limiter = new SlidingWindowLimiter($client, $name, 7, 60000, FailMode::Closed, prefix: 'rsw-itest:');
$wait = (float) $start - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$result = $limiter->hit('burst');
echo $result->degraded ? 'degraded' : ($result->allowed ? 'allowed' : 'denied');
