<?php

declare(strict_types=1);

// Smoke: with the server unreachable, fail open lets the request through and fail closed denies it, with
// Predis and with phpredis; both report through onError without the identifier.

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/clients.php';

foreach (['predis', 'phpredis'] as $kind) {
    foreach (FailMode::cases() as $mode) {
        $reports = [];
        // Port 1 refuses connections: an outage as the consumer sees it.
        $limiter = new SlidingWindowLimiter(smokeClient($kind, 1), 'smoke-outage', 5, 1000, $mode, static function (UnavailableReport $report, ErrorContext $context) use (&$reports): void {
            $reports[] = ['report' => get_object_vars($report), 'context' => ['name' => $context->name, 'failMode' => $context->failMode->value]];
        });
        $result = $limiter->hit('user-42');
        check($result->degraded, "{$kind} {$mode->value}: not degraded");
        check($result->allowed === ($mode === FailMode::Open), "{$kind} {$mode->value}: allowed " . var_export($result->allowed, true));
        check($reports === [[
            'report' => ['reason' => 'connection', 'message' => 'Redis or Valkey unavailable: connection'],
            'context' => ['name' => 'smoke-outage', 'failMode' => $mode->value],
        ]], "{$kind} {$mode->value}: reports " . json_encode($reports));
        check(!str_contains((string) json_encode($reports), 'user-42'), "{$kind} {$mode->value}: identifier leaked");
        echo "outage ({$kind}): fail {$mode->value} -> allowed " . var_export($result->allowed, true) . ", reason connection\n";
    }
}
