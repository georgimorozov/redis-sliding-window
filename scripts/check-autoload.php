<?php

declare(strict_types=1);

// The gate's live check for the Composer package (scripts/docker-test.sh package), run after
// `composer dump-autoload --no-dev --classmap-authoritative`: the package's classes load from its own
// autoload section alone, as they do for a consumer, and the script file ships where the package reads it.

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\Internal\Script;
use GeorgiMorozov\RedisSlidingWindow\Result;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;

require __DIR__ . '/../vendor/autoload.php';

$classes = [ErrorContext::class, Result::class, SlidingWindowLimiter::class, UnavailableReport::class, Script::class];
foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "check-autoload: {$class} did not load\n");
        exit(1);
    }
}
if (FailMode::from('closed') !== FailMode::Closed) {
    fwrite(STDERR, "check-autoload: FailMode did not load\n");
    exit(1);
}
if (Script::sha() !== sha1_file(__DIR__ . '/../lua/sliding-window.lua')) {
    fwrite(STDERR, "check-autoload: the script SHA does not match lua/sliding-window.lua\n");
    exit(1);
}
echo "check-autoload: the package autoloads without its dev autoloader\n";
