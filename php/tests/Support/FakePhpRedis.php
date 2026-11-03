<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Support;

/**
 * A phpredis client that never connects: rawCommand() plays scripted steps and records what it was sent.
 * A step is a reply value, or a closure that returns [?\Throwable to throw (null: return false), ?string
 * recorded server error].
 */
final class FakePhpRedis extends \Redis
{
    /** @var list<array<mixed>> */
    public array $sent = [];
    private ?string $recorded = null;

    /** @var list<\RedisException|string|null> what getOption(OPT_PREFIX) does next: throw, or return */
    public array $prefixReads = [];

    /** @param list<mixed> $steps */
    public function __construct(private array $steps = [])
    {
        parent::__construct();
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $this->sent[] = [$command, ...$args];
        if ($this->steps === []) {
            throw new \LogicException('FakePhpRedis: no step left for ' . $command);
        }
        $step = array_shift($this->steps);
        if ($step instanceof \Closure) {
            [$throw, $recorded] = $step();
            $this->recorded = $recorded;
            if ($throw instanceof \Throwable) {
                throw $throw;
            }

            return false;
        }

        return $step;
    }

    public function getOption(int $option): mixed
    {
        if ($option === \Redis::OPT_PREFIX && $this->prefixReads !== []) {
            $next = array_shift($this->prefixReads);
            if ($next instanceof \RedisException) {
                throw $next;
            }

            return $next;
        }

        return parent::getOption($option);
    }

    /** As after a failed connect: phpredis throws from getLastError() and clearLastError(). */
    public bool $failed = false;

    public function getLastError(): ?string
    {
        if ($this->failed) {
            throw new \RedisException('Redis server went away');
        }

        return $this->recorded;
    }

    public function clearLastError(): bool
    {
        if ($this->failed) {
            throw new \RedisException('Redis server went away');
        }
        $this->recorded = null;

        return true;
    }

    /** A server error reply as phpredis delivers it: false, with the reply recorded. */
    public static function serverError(string $reply): \Closure
    {
        return static fn(): array => [null, $reply];
    }

    /** An exception, with or without a recorded reply. */
    public static function exception(string $message, ?string $recorded = null): \Closure
    {
        return static fn(): array => [new \RedisException($message), $recorded];
    }
}
