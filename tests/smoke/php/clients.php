<?php

declare(strict_types=1);

// Long-lived clients as the connection advice recommends (docs/php.md): Predis with `persistent` and
// phpredis with pconnect() and a persistent_id, both with connect and read timeouts.

function smokeClient(string $kind, ?int $port = null): Predis\Client|Redis
{
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port ??= (int) (getenv('REDIS_PORT') ?: 6379);
    if ($kind === 'predis') {
        return new Predis\Client([
            'host' => $host,
            'port' => $port,
            'persistent' => true,
            'timeout' => 1.0,
            'read_write_timeout' => 1.0,
        ]);
    }
    $client = new Redis();
    try {
        $client->pconnect($host, $port, 1.0, 'rsw-smoke', 0, 1.0);
    } catch (RedisException) {
        // An outage: the limiter's own call reports it.
    }

    return $client;
}

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAILED: {$message}\n");
        exit(1);
    }
}
