<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Feature;

use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\Result;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Feature level: every shared vector (tests/vectors, docs/design.md "Vector format") through the public API
 * on a real server, with Predis and with phpredis.
 */
final class VectorsTest extends TestCase
{
    // One script reads TIME and adds one entry per age (score now - age * 1000, member seed-<index>).
    private const SEED = <<<'LUA'
        local t = redis.call('TIME')
        local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
        for i = 2, #ARGV do
          redis.call('ZADD', KEYS[1], string.format('%d', now - tonumber(ARGV[i]) * 1000), 'seed-' .. (i - 2))
        end
        redis.call('PEXPIRE', KEYS[1], ARGV[1])
        return #ARGV - 1
        LUA;

    /** @return iterable<string, array{'predis'|'phpredis', array<string, mixed>}> */
    public static function vectors(): iterable
    {
        $files = glob(__DIR__ . '/../../../tests/vectors/*.json');
        self::assertNotFalse($files);
        self::assertNotSame([], $files);
        sort($files);
        foreach ($files as $file) {
            $vector = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($vector);
            foreach (['predis', 'phpredis'] as $kind) {
                yield "{$vector['id']} ({$kind})" => [$kind, $vector];
            }
        }
    }

    /** @param array<string, mixed> $vector */
    #[Test]
    #[DataProvider('vectors')]
    public function vector(string $kind, array $vector): void
    {
        /** @var array{limit: int, windowMs: int} $config */
        $config = $vector['config'];
        $client = $kind === 'predis' ? Server::predis() : Server::phpredis();
        $name = 'vec-' . $vector['id'] . '-' . Server::hex(4);
        $prefix = 'rsw-vectors:';
        $limiter = new SlidingWindowLimiter($client, $name, $config['limit'], $config['windowMs'], FailMode::Closed, prefix: $prefix);
        $limiter->load();
        $key = "{$prefix}{$name}:{$config['windowMs']}:vector";
        try {
            /** @var list<array<string, mixed>> $steps */
            $steps = $vector['steps'];
            foreach ($steps as $i => $step) {
                if ($step['op'] === 'hit') {
                    /** @var array<string, mixed> $expect */
                    $expect = $step['expect'];
                    self::check($limiter->hit('vector'), $expect, "step {$i}");
                } elseif ($step['op'] === 'seed') {
                    /** @var list<int> $ages */
                    $ages = $step['agesMs'];
                    $args = [(string) $config['windowMs'], ...array_map('strval', $ages)];
                    if ($client instanceof \Redis) {
                        $client->rawCommand('EVAL', self::SEED, '1', $key, ...$args);
                    } else {
                        $client->eval(self::SEED, 1, $key, ...$args);
                    }
                } else {
                    usleep((int) $step['ms'] * 1000);
                }
            }
        } finally {
            $client->del($key);
        }
    }

    /** @param array<string, mixed> $expected */
    private static function check(Result $result, array $expected, string $where): void
    {
        self::assertFalse($result->degraded, $where);
        if (array_key_exists('allowed', $expected)) {
            self::assertSame($expected['allowed'], $result->allowed, "{$where} allowed");
        }
        foreach (['remaining', 'retryAfterMs', 'resetAfterMs'] as $field) {
            if (!array_key_exists($field, $expected)) {
                continue;
            }
            $want = $expected[$field];
            if (is_int($want)) {
                self::assertSame($want, $result->{$field}, "{$where} {$field}");
            } else {
                self::assertIsArray($want);
                self::assertGreaterThanOrEqual($want['min'], $result->{$field}, "{$where} {$field}");
                self::assertLessThanOrEqual($want['max'], $result->{$field}, "{$where} {$field}");
            }
        }
    }
}
