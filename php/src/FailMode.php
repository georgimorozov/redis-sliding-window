<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow;

/**
 * What a limiter does when Redis or Valkey cannot answer. The values match the TypeScript package's
 * FailMode. See docs/design.md, "Failure policy".
 */
enum FailMode: string
{
    /** Allow the request and report the outage through the onError callback. */
    case Open = 'open';

    /** Deny the request and report the outage through the onError callback. */
    case Closed = 'closed';
}
