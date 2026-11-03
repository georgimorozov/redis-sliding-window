<?php

declare(strict_types=1);

// The gate's live check for the Composer package (scripts/docker-test.sh package), run after
// `composer dump-autoload --no-dev --classmap-authoritative`: the package's classes load from its own
// autoload section alone, as they do for a consumer.

use GeorgiMorozov\RedisSlidingWindow\FailMode;

require __DIR__ . '/../vendor/autoload.php';

if (FailMode::from('closed') !== FailMode::Closed) {
    fwrite(STDERR, "check-autoload: FailMode did not load\n");
    exit(1);
}
echo "check-autoload: the package autoloads without its dev autoloader\n";
