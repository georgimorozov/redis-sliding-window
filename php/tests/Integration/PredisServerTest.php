<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Real-server checks. The server is REDIS_HOST and REDIS_PORT, default 127.0.0.1:6379; these tests need
 * one and fail without it (scripts/docker-test.sh integration starts Valkey). Keys expire after 60 s.
 */
final class PredisServerTest extends TestCase
{
    /** Reads the server's TIME inside a script and then writes, as the limiter's script will. */
    private const TIME_THEN_WRITE = <<<'LUA'
        local t = redis.call('TIME')
        redis.call('SET', KEYS[1], t[1], 'PX', 60000)
        return t
        LUA;

    private Client $client;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');
        $this->client = new Client([
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_string($port) && $port !== '' ? (int) $port : 6379,
            'timeout' => 2.0,
            'read_write_timeout' => 2.0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->client->disconnect();
    }

    #[Test]
    public function it_answers_echo(): void
    {
        self::assertSame('rsw', $this->client->echo('rsw'));
    }

    #[Test]
    public function a_script_can_read_the_server_time_and_then_write(): void
    {
        $key = 'rsw-itest:predis:' . bin2hex(random_bytes(8));
        $reply = $this->client->eval(self::TIME_THEN_WRITE, 1, $key);

        self::assertIsArray($reply);
        self::assertCount(2, $reply);
        [$seconds, $micros] = $reply;
        self::assertGreaterThan(1_700_000_000, (int) $seconds);
        self::assertGreaterThanOrEqual(0, (int) $micros);
        self::assertLessThan(1_000_000, (int) $micros);
        self::assertSame((string) $seconds, $this->client->get($key));
    }
}
