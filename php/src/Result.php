<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow;

/** The outcome of one hit() (docs/design.md, "Results"). */
final readonly class Result
{
    /**
     * @param bool $allowed Whether this request may proceed.
     * @param int $remaining Requests still allowed in the current window after this one.
     * @param int $retryAfterMs 0 when allowed; otherwise milliseconds until a request can succeed.
     * @param int $resetAfterMs Milliseconds until the bucket holds no entries.
     * @param \DateTimeImmutable $resetAt The caller's clock plus resetAfterMs.
     * @param bool $degraded True when the server gave no usable answer and the failure policy produced this result.
     */
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfterMs,
        public int $resetAfterMs,
        public \DateTimeImmutable $resetAt,
        public bool $degraded,
    ) {}

    /** Seconds for a 429's Retry-After: ceil(retryAfterMs / 1000), at least 1 when denied, 0 when allowed. */
    public function retryAfterSeconds(): int
    {
        if ($this->allowed) {
            return 0;
        }

        return max(1, intdiv($this->retryAfterMs + 999, 1000));
    }
}
