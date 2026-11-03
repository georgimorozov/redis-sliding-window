<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Unit;

use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\FakePhpRedis;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\FakePredis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Command\Processor\KeyPrefixProcessor;

/** Validation errors are programming errors: InvalidArgumentException, and the client is never called. */
final class ValidationTest extends TestCase
{
    private static function make(mixed ...$overrides): SlidingWindowLimiter
    {
        $args = $overrides + [
            'client' => (new FakePredis())->client,
            'name' => 'login',
            'limit' => 3,
            'windowMs' => 60000,
            'failMode' => FailMode::Closed,
            'onError' => null,
            'prefix' => 'rsw:',
        ];

        return new SlidingWindowLimiter(...$args);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'empty name' => [['name' => '']];
        yield 'name with a colon' => [['name' => 'a:b']];
        yield 'all-digit name' => [['name' => '5']];
        yield 'limit 0' => [['limit' => 0]];
        yield 'limit 2^53' => [['limit' => 9_007_199_254_740_992]];
        yield 'window 0' => [['windowMs' => 0]];
        yield 'window over 365 days' => [['windowMs' => 31_536_000_001]];
        yield 'prefix without a trailing colon' => [['prefix' => 'rsw']];
        yield 'prefix a::' => [['prefix' => 'a::']];
        yield 'prefix :' => [['prefix' => ':']];
        yield 'all-digit prefix segment' => [['prefix' => 'a:5:']];
        yield 'prefix with a trailing newline' => [['prefix' => "a:\n"]];
    }

    /** @param array<string, mixed> $overrides */
    #[Test]
    #[DataProvider('invalid')]
    public function invalid_options_throw(array $overrides): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::make(...$overrides);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function valid(): iterable
    {
        yield 'defaults' => [[]];
        yield 'empty prefix' => [['prefix' => '']];
        yield 'two prefix segments' => [['prefix' => 'app:rsw:']];
        yield 'digits inside names' => [['name' => 'v2', 'prefix' => 'a1:']];
        yield 'limit 1' => [['limit' => 1]];
        yield 'limit 2^53 - 1' => [['limit' => 9_007_199_254_740_991]];
        yield 'window 1' => [['windowMs' => 1]];
        yield 'window 365 days' => [['windowMs' => 31_536_000_000]];
    }

    /** @param array<string, mixed> $overrides */
    #[Test]
    #[DataProvider('valid')]
    public function valid_options_construct(array $overrides): void
    {
        self::assertInstanceOf(SlidingWindowLimiter::class, self::make(...$overrides));
    }

    #[Test]
    public function prefix_a_with_name_5_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::make(prefix: 'a:', name: '5');
    }

    #[Test]
    public function an_empty_identifier_throws_without_calling_the_client(): void
    {
        $fake = new FakePredis();
        $limiter = self::make(client: $fake->client, failMode: FailMode::Open);
        try {
            $limiter->hit('');
            self::fail('no exception');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $fake->sent);
    }

    #[Test]
    public function a_predis_key_prefix_is_rejected(): void
    {
        $this->expectExceptionMessage('the Predis client must not set a key prefix');
        self::make(client: (new FakePredis([], ['prefix' => 'app:']))->client);
    }

    #[Test]
    public function a_predis_custom_prefix_processor_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::make(client: (new FakePredis([], ['prefix' => new KeyPrefixProcessor('x')]))->client);
    }

    #[Test]
    public function an_empty_predis_prefix_is_accepted(): void
    {
        self::assertInstanceOf(SlidingWindowLimiter::class, self::make(client: (new FakePredis([], ['prefix' => '']))->client));
    }

    #[Test]
    public function a_predis_cluster_or_replication_client_is_rejected(): void
    {
        foreach ([['cluster' => 'redis'], ['cluster' => 'predis'], ['replication' => 'predis']] as $options) {
            $client = new Client(['tcp://127.0.0.1:7000', 'tcp://127.0.0.1:7001'], $options);
            try {
                self::make(client: $client);
                self::fail('no exception for ' . json_encode($options));
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('one node', $error->getMessage());
            }
        }
    }

    #[Test]
    public function a_phpredis_opt_prefix_is_rejected(): void
    {
        $client = new FakePhpRedis();
        $client->setOption(\Redis::OPT_PREFIX, 'app:');
        $this->expectExceptionMessage('the phpredis client must not set OPT_PREFIX');
        self::make(client: $client);
    }

    #[Test]
    public function a_phpredis_client_without_a_prefix_is_accepted(): void
    {
        self::assertInstanceOf(SlidingWindowLimiter::class, self::make(client: new FakePhpRedis()));
    }

    #[Test]
    public function a_phpredis_client_that_cannot_read_options_is_checked_on_the_first_call(): void
    {
        // phpredis throws from getOption() after a failed connect; the limiter still constructs.
        $client = new FakePhpRedis([[1, 2, 0, 60000]]);
        $client->prefixReads = [new \RedisException('Redis server went away'), 'app:'];
        $limiter = self::make(client: $client);
        try {
            $limiter->hit('x');
            self::fail('no exception');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('OPT_PREFIX', $error->getMessage());
        }
        self::assertSame([], $client->sent);
    }

    #[Test]
    public function a_phpredis_option_read_that_fails_again_on_the_call_is_an_outage(): void
    {
        $client = new FakePhpRedis([[1, 2, 0, 60000]]);
        $client->prefixReads = [new \RedisException('Redis server went away'), new \RedisException('Redis server went away'), null];
        $limiter = self::make(client: $client, failMode: FailMode::Open);
        $first = $limiter->hit('x');
        self::assertTrue($first->degraded);
        self::assertTrue($first->allowed);
        self::assertFalse($limiter->hit('x')->degraded);
    }
}
