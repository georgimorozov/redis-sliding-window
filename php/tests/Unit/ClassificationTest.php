<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Unit;

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\FakePhpRedis;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\FakePredis;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Protocol\ProtocolException;
use Predis\TimeoutException;

/** Client-specific transport failures and setup replies (docs/design.md, the failure-policy table). */
final class ClassificationTest extends TestCase
{
    /** @return iterable<string, array{\Closure(NodeConnectionInterface): \Throwable, ?string}> */
    public static function predisFailures(): iterable
    {
        yield 'TimeoutException' => [static fn(NodeConnectionInterface $c) => new TimeoutException($c), 'timeout'];
        yield 'ConnectionException' => [static fn(NodeConnectionInterface $c) => new ConnectionException($c, 'Connection refused [tcp://127.0.0.1:1]'), 'connection'];
        yield 'read error' => [static fn(NodeConnectionInterface $c) => new ConnectionException($c, 'Error while reading line from the server. [tcp://127.0.0.1:6379]'), 'connection'];
        yield 'StreamInitException' => [static fn() => new StreamInitException('Failed to create stream'), 'connection'];
        yield 'SSL context StreamInitException' => [static fn() => new StreamInitException('Error while setting SSL context options'), null];
        yield 'Failed: WRONGPASS' => [static fn(NodeConnectionInterface $c) => new ConnectionException($c, 'Failed: WRONGPASS invalid username-password pair [tcp://127.0.0.1:6379]'), null];
        yield 'Failed: ERR DB index' => [static fn(NodeConnectionInterface $c) => new ConnectionException($c, 'Failed: ERR DB index is out of range [tcp://127.0.0.1:6379]'), null];
        yield 'Failed: NOSCRIPT' => [static fn(NodeConnectionInterface $c) => new ConnectionException($c, 'Failed: NOSCRIPT odd'), null];
        yield 'Failed: LOADING' => [static fn(NodeConnectionInterface $c) => new ConnectionException($c, 'Failed: LOADING Redis is loading [tcp://127.0.0.1:6379]'), 'LOADING'];
        yield 'ProtocolException' => [static fn(NodeConnectionInterface $c) => new ProtocolException($c, 'Unknown response prefix'), null];
        yield 'RuntimeException' => [static fn() => new \RuntimeException('other'), null];
    }

    #[Test]
    #[DataProvider('predisFailures')]
    public function predis_failures_in_both_modes(\Closure $failure, ?string $reason): void
    {
        foreach (FailMode::cases() as $mode) {
            $reports = [];
            $fake = new FakePredis([$failure]);
            $limiter = self::limiter($fake->client, $mode, $reports);
            self::assertOutcome($limiter, $reason, $mode, $reports);
        }
    }

    #[Test]
    public function predis_with_exceptions_off_classifies_returned_errors(): void
    {
        foreach (['BUSY busy' => 'BUSY', 'WRONGTYPE wrong' => null] as $reply => $reason) {
            $reports = [];
            $fake = new FakePredis([FakePredis::serverError($reply)], ['exceptions' => false]);
            self::assertOutcome(self::limiter($fake->client, FailMode::Closed, $reports), $reason, FailMode::Closed, $reports, \Predis\Response\ServerException::class);
        }
        $fake = new FakePredis([FakePredis::serverError('NOSCRIPT No matching script.'), [1, 2, 0, 60000]], ['exceptions' => false]);
        $reports = [];
        self::assertFalse(self::limiter($fake->client, FailMode::Closed, $reports)->hit('x')->degraded);
        self::assertSame('EVAL', $fake->sent[1][0]);
    }

    /** @return iterable<string, array{\Closure, ?string}> */
    public static function phpredisFailures(): iterable
    {
        yield 'connection lost' => [FakePhpRedis::exception('Connection lost'), 'connection'];
        yield 'read error' => [FakePhpRedis::exception('read error on connection to 127.0.0.1:6379'), 'connection'];
        yield 'went away' => [FakePhpRedis::exception('Redis server went away'), 'connection'];
        yield 'protocol error' => [FakePhpRedis::exception('protocol error, got \'x\' as reply type byte'), null];
        yield 'AUTH reconnect, socket lost' => [FakePhpRedis::exception('AUTH failed while reconnecting'), 'connection'];
        yield 'SELECT reconnect, socket lost' => [FakePhpRedis::exception('SELECT failed while reconnecting'), 'connection'];
        yield 'AUTH reconnect, WRONGPASS recorded' => [FakePhpRedis::exception('AUTH failed while reconnecting', 'WRONGPASS invalid username-password pair'), null];
        yield 'SELECT reconnect, WRONGPASS recorded' => [FakePhpRedis::exception('SELECT failed while reconnecting', 'WRONGPASS invalid username-password pair'), null];
        yield 'AUTH reconnect, LOADING recorded' => [FakePhpRedis::exception('AUTH failed while reconnecting', 'LOADING loading'), 'LOADING'];
        yield 'RuntimeException' => [static fn(): array => [new \RuntimeException('other'), null], null];
    }

    #[Test]
    #[DataProvider('phpredisFailures')]
    public function phpredis_failures_in_both_modes(\Closure $failure, ?string $reason): void
    {
        foreach (FailMode::cases() as $mode) {
            $reports = [];
            $limiter = self::limiter(new FakePhpRedis([$failure]), $mode, $reports);
            self::assertOutcome($limiter, $reason, $mode, $reports);
        }
    }

    #[Test]
    public function phpredis_clears_the_last_error_before_each_call(): void
    {
        $client = new FakePhpRedis([FakePhpRedis::serverError('BUSY busy'), FakePhpRedis::exception('Connection lost')]);
        $reports = [];
        $limiter = self::limiter($client, FailMode::Closed, $reports);
        $limiter->hit('x');
        // The second call's exception has no recorded reply: a stale BUSY must not be reused.
        $limiter->hit('x');
        self::assertSame(['BUSY', 'connection'], array_map(static fn(array $r): string => $r[0]->reason, $reports));
    }

    #[Test]
    public function a_phpredis_client_whose_connect_failed_degrades_in_both_modes(): void
    {
        foreach (FailMode::cases() as $mode) {
            $reports = [];
            $client = new FakePhpRedis();
            $client->failed = true;
            self::assertOutcome(self::limiter($client, $mode, $reports), 'connection', $mode, $reports);
            self::assertSame([], $client->sent);
        }
    }

    /**
     * @param list<array{UnavailableReport, ErrorContext}> $reports
     */
    private static function limiter(\Predis\ClientInterface|\Redis $client, FailMode $mode, array &$reports): SlidingWindowLimiter
    {
        return new SlidingWindowLimiter($client, 'login', 3, 60000, $mode, static function (UnavailableReport $report, ErrorContext $context) use (&$reports): void {
            $reports[] = [$report, $context];
        });
    }

    /**
     * @param list<array{UnavailableReport, ErrorContext}> $reports
     * @param null|class-string<\Throwable> $class
     */
    private static function assertOutcome(SlidingWindowLimiter $limiter, ?string $reason, FailMode $mode, array &$reports, ?string $class = null): void
    {
        try {
            $result = $limiter->hit('x');
        } catch (\Throwable $error) {
            self::assertNull($reason, 'threw ' . $error::class . ': ' . $error->getMessage());
            if ($class !== null) {
                self::assertInstanceOf($class, $error);
            }
            self::assertSame([], $reports);

            return;
        }
        self::assertNotNull($reason, 'degraded instead of throwing');
        self::assertTrue($result->degraded);
        self::assertSame($mode === FailMode::Open, $result->allowed);
        self::assertCount(1, $reports);
        self::assertSame($reason, $reports[0][0]->reason);
    }
}
