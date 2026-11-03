<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow;

/** Which limiter produced a degraded result. Never holds the identifier. */
final readonly class ErrorContext
{
    public function __construct(public string $name, public FailMode $failMode) {}
}
