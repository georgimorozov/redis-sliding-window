<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow;

use GeorgiMorozov\RedisSlidingWindow\Internal\Adapter;
use GeorgiMorozov\RedisSlidingWindow\Internal\PhpRedisAdapter;
use GeorgiMorozov\RedisSlidingWindow\Internal\PredisAdapter;
use GeorgiMorozov\RedisSlidingWindow\Internal\Script;

/** A sliding-window rate limiter for Redis and Valkey. docs/design.md is the contract. */
final class SlidingWindowLimiter
{
    public const MAX_LIMIT = 9_007_199_254_740_991;
    public const MAX_WINDOW_MS = 31_536_000_000;
    private const NAME_PATTERN = '/^[^:]*[^:0-9][^:]*$/D';
    private const PREFIX_PATTERN = '/^([^:]*[^:0-9][^:]*:)*$/D';

    private readonly Adapter $adapter;

    /**
     * @param \Predis\ClientInterface|\Redis $client A long-lived client to one node, without a key prefix.
     * @param string $name Non-empty, no ":", not all digits.
     * @param int $limit Allowed requests per window: 1 to 2^53 - 1.
     * @param int $windowMs The window in milliseconds: 1 to 31536000000 (365 days).
     * @param FailMode $failMode No default: choose (docs/design.md, "Choosing a mode").
     * @param null|\Closure(UnavailableReport, ErrorContext): void $onError Runs once per degraded result.
     * @param string $prefix Empty, or segments that each end in ":", none empty or all digits.
     */
    public function __construct(
        \Predis\ClientInterface|\Redis $client,
        private readonly string $name,
        private readonly int $limit,
        private readonly int $windowMs,
        private readonly FailMode $failMode,
        private readonly ?\Closure $onError = null,
        private readonly string $prefix = 'rsw:',
    ) {
        $this->adapter = $client instanceof \Redis ? new PhpRedisAdapter($client) : new PredisAdapter($client);
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(
                'redis-sliding-window: name must be non-empty, without ":", and not all digits',
            );
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('redis-sliding-window: limit must be an integer from 1 to 2^53 - 1');
        }
        if ($windowMs < 1 || $windowMs > self::MAX_WINDOW_MS) {
            throw new \InvalidArgumentException(
                'redis-sliding-window: windowMs must be an integer from 1 to 31536000000',
            );
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new \InvalidArgumentException(
                'redis-sliding-window: prefix must be empty or segments ending in ":", none empty or all digits',
            );
        }
    }

    /** Sends SCRIPT LOAD; call it once at startup. Every error throws: the failure policy does not apply. */
    public function load(): void
    {
        $sha = $this->adapter->call(['SCRIPT', 'LOAD', Script::source()]);
        if ($sha !== Script::sha()) {
            throw new \UnexpectedValueException('redis-sliding-window: SCRIPT LOAD returned an unexpected SHA');
        }
    }

    /** Records one request for $identifier if the window has room. */
    public function hit(#[\SensitiveParameter] string $identifier): Result
    {
        if ($identifier === '') {
            throw new \InvalidArgumentException('redis-sliding-window: identifier must be a non-empty string');
        }
        $key = $this->prefix . $this->name . ':' . $this->windowMs . ':' . $identifier;
        $args = ['1', $key, (string) $this->limit, (string) $this->windowMs, bin2hex(random_bytes(16))];
        try {
            $reply = $this->adapter->call(['EVALSHA', Script::sha(), ...$args]);
        } catch (\Throwable $error) {
            $first = $this->adapter->classify($error);
            if ($first === null) {
                throw $error;
            }
            if ($first !== Adapter::NOSCRIPT) {
                return $this->degrade($first);
            }
            try {
                $reply = $this->adapter->call(['EVAL', Script::source(), ...$args]);
            } catch (\Throwable $fallbackError) {
                $second = $this->adapter->classify($fallbackError);
                if ($second === null || $second === Adapter::NOSCRIPT) {
                    throw $fallbackError;
                }

                return $this->degrade($second);
            }
        }
        [$allowed, $remaining, $retryAfterMs, $resetAfterMs] = $this->parseReply($reply);

        return new Result(
            $allowed === 1,
            $remaining,
            $retryAfterMs,
            $resetAfterMs,
            self::after($resetAfterMs),
            false,
        );
    }

    /** @return array{int, int, int, int} */
    private function parseReply(mixed $reply): array
    {
        if (
            !is_array($reply)
            || !array_is_list($reply)
            || count($reply) !== 4
            || !is_int($reply[0]) || !is_int($reply[1]) || !is_int($reply[2]) || !is_int($reply[3])
        ) {
            throw new \UnexpectedValueException('redis-sliding-window: unexpected reply from the script');
        }
        [$allowed, $remaining, $retryAfterMs, $resetAfterMs] = $reply;
        $valid = $allowed === 1
            ? $retryAfterMs === 0
                && $remaining >= 0 && $remaining <= $this->limit - 1
                && $resetAfterMs >= 1 && $resetAfterMs <= $this->windowMs
            : $allowed === 0
                && $remaining === 0
                && $retryAfterMs >= 1
                && $retryAfterMs <= $resetAfterMs
                && $resetAfterMs <= $this->windowMs;
        if (!$valid) {
            throw new \UnexpectedValueException('redis-sliding-window: unexpected reply from the script');
        }

        return [$allowed, $remaining, $retryAfterMs, $resetAfterMs];
    }

    /** @param 'connection'|'timeout'|'LOADING'|'BUSY'|'MASTERDOWN'|'READONLY' $reason */
    private function degrade(string $reason): Result
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)(new UnavailableReport($reason), new ErrorContext($this->name, $this->failMode));
            } catch (\Throwable $error) {
                error_log(sprintf(
                    'redis-sliding-window: onError callback failed: %s: %s',
                    $error::class,
                    $error->getMessage(),
                ));
            }
        }
        $open = $this->failMode === FailMode::Open;

        return new Result(
            $open,
            $open ? $this->limit : 0,
            $open ? 0 : $this->windowMs,
            $this->windowMs,
            self::after($this->windowMs),
            true,
        );
    }

    private static function after(int $ms): \DateTimeImmutable
    {
        $micros = (int) floor(microtime(true) * 1_000_000) + $ms * 1000;
        $at = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000),
        );
        if ($at === false) {
            throw new \LogicException('redis-sliding-window: cannot build resetAt');
        }

        return $at;
    }
}
