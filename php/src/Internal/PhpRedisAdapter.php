<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Internal;

/**
 * phpredis 6 (docs/design.md, the failure-policy table's phpredis row). Commands go through rawCommand(),
 * which applies no key prefix, serializer or compression.
 *
 * @internal
 */
final class PhpRedisAdapter implements Adapter
{
    private bool $prefixChecked = false;

    public function __construct(private readonly \Redis $client)
    {
        // phpredis cannot read options from a client whose connection failed. That is deferred, not dropped:
        // the check stays pending and call() repeats it, so a read that still fails reaches hit() as an
        // outage (classified and reported through onError) and a prefix found then still throws.
        try {
            $this->checkPrefix();
        } catch (\RedisException) {
            $this->prefixChecked = false;
        }
    }

    public function call(array $command): mixed
    {
        $this->client->clearLastError();
        if (!$this->prefixChecked) {
            $this->checkPrefix();
        }
        $reply = $this->client->rawCommand(...$command);
        $lastError = $this->lastError();
        if ($reply === false && is_string($lastError)) {
            throw new \RedisException($lastError);
        }

        return $reply;
    }

    public function classify(\Throwable $error): ?string
    {
        if (!$error instanceof \RedisException) {
            return null;
        }
        $lastError = $this->lastError();
        if (is_string($lastError)) {
            return ReplyCode::classify($lastError);
        }

        return str_starts_with($error->getMessage(), 'protocol error') ? null : 'connection';
    }

    /** The recorded server reply, if any. phpredis throws instead when the client's connection has failed. */
    private function lastError(): ?string
    {
        try {
            return $this->client->getLastError();
        } catch (\RedisException) {
            return null;
        }
    }

    private function checkPrefix(): void
    {
        $prefix = $this->client->getOption(\Redis::OPT_PREFIX);
        $this->prefixChecked = true;
        if ($prefix !== null && $prefix !== false && $prefix !== '') {
            throw new \InvalidArgumentException('redis-sliding-window: the phpredis client must not set OPT_PREFIX');
        }
    }
}
