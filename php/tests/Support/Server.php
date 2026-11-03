<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Support;

use Predis\Client;

/**
 * The server for feature and integration tests: REDIS_HOST and REDIS_PORT, default 127.0.0.1:6379. These
 * tests need one and fail without it (scripts/docker-test.sh integration starts the servers).
 */
final class Server
{
    public static function host(): string
    {
        $host = getenv('REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    public static function port(): int
    {
        $port = getenv('REDIS_PORT');

        return is_string($port) && $port !== '' ? (int) $port : 6379;
    }

    /** @param array<string, mixed> $parameters */
    public static function predis(array $parameters = []): Client
    {
        return new Client($parameters + [
            'host' => self::host(),
            'port' => self::port(),
            'timeout' => 2.0,
            'read_write_timeout' => 2.0,
        ]);
    }

    /** A connected phpredis client. */
    public static function phpredis(?string $host = null, ?int $port = null, float $timeout = 2.0, float $readTimeout = 2.0): \Redis
    {
        $client = new \Redis();
        $client->connect($host ?? self::host(), $port ?? self::port(), $timeout, null, 0, $readTimeout);

        return $client;
    }

    /** @param string $kind 'predis' or 'phpredis' */
    public static function client(string $kind): Client|\Redis
    {
        return $kind === 'predis' ? self::predis() : self::phpredis();
    }

    /** @param positive-int $bytes */
    public static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
