<?php

declare(strict_types=1);

// tag-check's Composer consumer: the package loads through the consumer's autoloader, finds its Lua
// script, and constructs with phpredis (the PHP test image's extension) without a server.

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\Internal\Script;
use GeorgiMorozov\RedisSlidingWindow\Result;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;

require __DIR__ . '/vendor/autoload.php';

foreach ([ErrorContext::class, Result::class, SlidingWindowLimiter::class, UnavailableReport::class, Script::class] as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "tag-check (composer): {$class} did not load\n");
        exit(1);
    }
}
$lua = __DIR__ . '/vendor/georgimorozov/redis-sliding-window/lua/sliding-window.lua';
if (!is_file($lua) || Script::source() !== file_get_contents($lua) || Script::sha() !== sha1_file($lua)) {
    fwrite(STDERR, "tag-check (composer): the script source does not match the installed lua/sliding-window.lua\n");
    exit(1);
}
new SlidingWindowLimiter(new \Redis(), 'tagcheck', 1, 1000, FailMode::Closed);
echo "tag-check (composer): autoload, script and constructor ok\n";
