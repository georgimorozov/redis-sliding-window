<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow;

/**
 * What onError receives. Built from the reason alone: it never holds the identifier or anything from the
 * client's error. Not a Throwable, so it carries no stack trace and no frame arguments.
 */
final readonly class UnavailableReport
{
    /** @var 'connection'|'timeout'|'LOADING'|'BUSY'|'MASTERDOWN'|'READONLY' */
    public string $reason;
    public string $message;

    /** @param 'connection'|'timeout'|'LOADING'|'BUSY'|'MASTERDOWN'|'READONLY' $reason */
    public function __construct(string $reason)
    {
        $this->reason = $reason;
        $this->message = 'Redis or Valkey unavailable: ' . $reason;
    }
}
