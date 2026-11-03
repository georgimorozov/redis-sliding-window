<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Unit;

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\Result;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\Clients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** docs/design.md, "Acceptance outcomes", unit: what holds for both clients. */
final class SlidingWindowLimiterTest extends TestCase
{
    private const NONCE = '/^[0-9a-f]{32}$/D';

    /** @var list<array{UnavailableReport, ErrorContext}> */
    private array $reports = [];

    /**
     * @param list<mixed> $steps
     * @return array{SlidingWindowLimiter, \Closure(): list<array<mixed>>}
     */
    private function limiter(string $kind, array $steps, FailMode $mode = FailMode::Closed, int $limit = 3, int $windowMs = 60000, string $prefix = 'rsw:'): array
    {
        [$client, $sent] = Clients::make($kind, $steps);
        $limiter = new SlidingWindowLimiter($client, 'login', $limit, $windowMs, $mode, function (UnavailableReport $report, ErrorContext $context): void {
            $this->reports[] = [$report, $context];
        }, $prefix);

        return [$limiter, $sent];
    }

    /** @return list<array{UnavailableReport, ErrorContext}> */
    private function reported(): array
    {
        return $this->reports;
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        foreach (Clients::KINDS as $kind) {
            yield $kind => [$kind];
        }
    }

    /** @return iterable<string, array{string, FailMode}> */
    public static function kindsAndModes(): iterable
    {
        foreach (Clients::KINDS as $kind) {
            foreach (FailMode::cases() as $mode) {
                yield "{$kind}, fail {$mode->value}" => [$kind, $mode];
            }
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function hit_sends_evalsha_with_the_key_limit_window_and_a_fresh_nonce(string $kind): void
    {
        [$limiter, $sent] = $this->limiter($kind, [['reply', [1, 2, 0, 60000]], ['reply', [1, 1, 0, 60000]]]);
        $result = $limiter->hit('203.0.113.7');
        $limiter->hit('203.0.113.7');

        self::assertTrue($result->allowed);
        self::assertSame(2, $result->remaining);
        self::assertSame(0, $result->retryAfterMs);
        self::assertSame(60000, $result->resetAfterMs);
        self::assertFalse($result->degraded);
        [$first, $second] = $sent();
        self::assertSame(['EVALSHA', sha1((string) file_get_contents(__DIR__ . '/../../../lua/sliding-window.lua')), '1', 'rsw:login:60000:203.0.113.7', '3', '60000'], array_slice($first, 0, 6));
        self::assertMatchesRegularExpression(self::NONCE, (string) $first[6]);
        self::assertCount(7, $first);
        self::assertNotSame($first[6], $second[6]);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function the_identifier_may_contain_colons_and_the_prefix_may_be_empty(string $kind): void
    {
        [$limiter, $sent] = $this->limiter($kind, [['reply', [1, 2, 0, 60000]]], prefix: '');
        $limiter->hit('2001:db8::1');
        self::assertSame('login:60000:2001:db8::1', $sent()[0][3]);
    }

    #[Test]
    public function result_reset_at_is_the_callers_clock_plus_reset_after_ms(): void
    {
        [$limiter] = $this->limiter('predis', [['reply', [1, 2, 0, 60000]]]);
        $before = microtime(true);
        $result = $limiter->hit('x');
        $at = (float) $result->resetAt->format('U.u');
        self::assertGreaterThanOrEqual($before + 60 - 0.001, $at);
        self::assertLessThanOrEqual(microtime(true) + 60 + 0.001, $at);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedReplies(): iterable
    {
        yield 'not an array' => ['OK'];
        yield 'false' => [false];
        yield 'three items' => [[1, 2, 0]];
        yield 'five items' => [[1, 2, 0, 60000, 0]];
        yield 'string numbers' => [['1', '2', '0', '60000']];
        yield 'not a list' => [[1 => 1, 2 => 2, 3 => 0, 4 => 60000]];
        yield 'allowed 2' => [[2, 0, 0, 60000]];
        yield 'allowed with retry' => [[1, 2, 5, 60000]];
        yield 'allowed remaining = limit' => [[1, 3, 0, 60000]];
        yield 'allowed remaining < 0' => [[1, -1, 0, 60000]];
        yield 'allowed reset 0' => [[1, 2, 0, 0]];
        yield 'allowed reset > window' => [[1, 2, 0, 60001]];
        yield 'denied remaining 1' => [[0, 1, 1000, 2000]];
        yield 'denied retry 0' => [[0, 0, 0, 2000]];
        yield 'denied retry > reset' => [[0, 0, 3000, 2000]];
        yield 'denied reset > window' => [[0, 0, 1000, 60001]];
        yield 'a queued MULTI reply' => ['QUEUED'];
    }

    #[Test]
    #[DataProvider('malformedReplies')]
    public function a_malformed_reply_throws_in_both_modes_for_both_clients(mixed $reply): void
    {
        foreach (Clients::KINDS as $kind) {
            foreach (FailMode::cases() as $mode) {
                [$limiter] = $this->limiter($kind, [['reply', $reply]], $mode);
                try {
                    $limiter->hit('x');
                    self::fail("{$kind} {$mode->value}: no exception");
                } catch (\UnexpectedValueException $error) {
                    self::assertSame('redis-sliding-window: unexpected reply from the script', $error->getMessage());
                }
            }
        }
        self::assertSame([], $this->reports);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function replay_and_denied_shaped_replies_are_accepted(string $kind): void
    {
        [$limiter] = $this->limiter($kind, [['reply', [1, 0, 0, 500]], ['reply', [0, 0, 1000, 60000]], ['reply', [1, 2, 0, 1]], ['reply', [0, 0, 60000, 60000]]]);
        self::assertSame(500, $limiter->hit('x')->resetAfterMs);
        $denied = $limiter->hit('x');
        self::assertFalse($denied->allowed);
        self::assertSame(1000, $denied->retryAfterMs);
        self::assertSame(1, $limiter->hit('x')->resetAfterMs);
        self::assertSame(60000, $limiter->hit('x')->retryAfterMs);
    }

    /** @return iterable<string, array{string, string}> */
    public static function degradingCodes(): iterable
    {
        foreach (Clients::KINDS as $kind) {
            foreach (['LOADING Redis is loading the dataset in memory', 'BUSY Redis is busy running a script', 'MASTERDOWN Link with MASTER is down', "READONLY You can't write against a read only replica."] as $reply) {
                yield $kind . ' ' . explode(' ', $reply)[0] => [$kind, $reply];
            }
        }
    }

    #[Test]
    #[DataProvider('degradingCodes')]
    public function the_four_codes_degrade_in_both_modes(string $kind, string $reply): void
    {
        $code = explode(' ', $reply)[0];
        foreach (FailMode::cases() as $mode) {
            $this->reports = [];
            [$limiter] = $this->limiter($kind, [['error', $reply]], $mode);
            $result = $limiter->hit('x');
            self::assertTrue($result->degraded);
            self::assertSame($mode === FailMode::Open, $result->allowed);
            $reports = $this->reported();
            self::assertCount(1, $reports);
            self::assertSame($code, $reports[0][0]->reason);
            self::assertSame('Redis or Valkey unavailable: ' . $code, $reports[0][0]->message);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function throwingCodes(): iterable
    {
        foreach (Clients::KINDS as $kind) {
            foreach (['NOAUTH Authentication required.', 'WRONGPASS invalid username-password pair or user is disabled.', 'NOPERM User has no permissions', 'WRONGTYPE Operation against a key holding the wrong kind of value', "OOM command not allowed when used memory > 'maxmemory'.", 'MISCONF Errors writing to disk', 'ERR Error running script', 'SOMETHINGNEW unknown code'] as $reply) {
                yield $kind . ' ' . explode(' ', $reply)[0] => [$kind, $reply];
            }
        }
    }

    #[Test]
    #[DataProvider('throwingCodes')]
    public function other_codes_throw_the_clients_own_exception_in_both_modes(string $kind, string $reply): void
    {
        $class = $kind === 'predis' ? \Predis\Response\ServerException::class : \RedisException::class;
        foreach (FailMode::cases() as $mode) {
            [$limiter] = $this->limiter($kind, [['error', $reply]], $mode);
            try {
                $limiter->hit('x');
                self::fail('no exception');
            } catch (\Throwable $error) {
                self::assertInstanceOf($class, $error);
                self::assertSame($reply, $error->getMessage());
            }
        }
        self::assertSame([], $this->reports);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function a_client_type_error_throws_in_both_modes(string $kind): void
    {
        $step = $kind === 'predis'
            ? static fn(): \Throwable => new \TypeError('boom')
            : static fn(): array => [new \TypeError('boom'), null];
        foreach (FailMode::cases() as $mode) {
            [$limiter] = $this->limiter($kind, [$step], $mode);
            try {
                $limiter->hit('x');
                self::fail('no exception');
            } catch (\TypeError $error) {
                self::assertSame('boom', $error->getMessage());
            }
        }
        self::assertSame([], $this->reports);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function noscript_falls_back_to_one_eval_with_the_same_nonce(string $kind): void
    {
        [$limiter, $sent] = $this->limiter($kind, [['error', 'NOSCRIPT No matching script.'], ['reply', [1, 2, 0, 60000]]]);
        $result = $limiter->hit('x');

        self::assertFalse($result->degraded);
        [$evalsha, $eval] = $sent();
        self::assertCount(2, $sent());
        self::assertSame('EVAL', $eval[0]);
        self::assertSame(file_get_contents(__DIR__ . '/../../../lua/sliding-window.lua'), $eval[1]);
        self::assertSame(array_slice($evalsha, 2), array_slice($eval, 2));
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_failure_of_the_eval_fallback_is_classified_normally(string $kind, FailMode $mode): void
    {
        [$limiter] = $this->limiter($kind, [['error', 'NOSCRIPT No matching script.'], ['error', 'LOADING loading']], $mode);
        self::assertTrue($limiter->hit('x')->degraded);
        self::assertSame('LOADING', $this->reports[0][0]->reason);

        [$limiter] = $this->limiter($kind, [['error', 'NOSCRIPT No matching script.'], ['error', 'NOSCRIPT again']], $mode);
        $this->expectExceptionMessage('NOSCRIPT again');
        $limiter->hit('x');
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function degraded_results_follow_the_table(string $kind, FailMode $mode): void
    {
        [$limiter] = $this->limiter($kind, [['error', 'BUSY busy']], $mode, 5, 1000);
        $result = $limiter->hit('x');
        $open = $mode === FailMode::Open;
        self::assertSame([$open, $open ? 5 : 0, $open ? 0 : 1000, 1000, true], [$result->allowed, $result->remaining, $result->retryAfterMs, $result->resetAfterMs, $result->degraded]);
        self::assertSame($open ? 0 : 1, $result->retryAfterSeconds());
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function the_callback_runs_once_with_exactly_the_report_and_context(string $kind, FailMode $mode): void
    {
        [$limiter] = $this->limiter($kind, [['error', 'BUSY busy']], $mode);
        $limiter->hit('secret-identifier');

        self::assertCount(1, $this->reports);
        [$report, $context] = $this->reports[0];
        self::assertFalse((new \ReflectionClass(UnavailableReport::class))->implementsInterface(\Throwable::class));
        self::assertSame(['reason' => 'BUSY', 'message' => 'Redis or Valkey unavailable: BUSY'], get_object_vars($report));
        self::assertSame(['name' => 'login', 'failMode' => $mode], get_object_vars($context));
        self::assertStringNotContainsString('secret-identifier', serialize($this->reports));
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_throwing_callback_still_yields_the_degraded_result_and_logs(string $kind, FailMode $mode): void
    {
        [$client] = Clients::make($kind, [['error', 'BUSY busy']]);
        $limiter = new SlidingWindowLimiter($client, 'login', 3, 60000, $mode, static function (): void {
            throw new \RuntimeException('callback broke');
        });
        $log = (string) tempnam(sys_get_temp_dir(), 'rsw');
        $previous = ini_set('error_log', $log);
        try {
            $result = $limiter->hit('x');
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertTrue($result->degraded);
        self::assertSame($mode === FailMode::Open, $result->allowed);
        self::assertStringContainsString('redis-sliding-window: onError callback failed: RuntimeException: callback broke', (string) file_get_contents($log));
        unlink($log);
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function without_a_callback_degraded_results_are_still_returned(string $kind, FailMode $mode): void
    {
        [$client] = Clients::make($kind, [['error', 'BUSY busy']]);
        $limiter = new SlidingWindowLimiter($client, 'login', 3, 60000, $mode);
        self::assertTrue($limiter->hit('x')->degraded);
    }

    #[Test]
    public function hit_marks_the_identifier_sensitive(): void
    {
        $parameter = (new \ReflectionMethod(SlidingWindowLimiter::class, 'hit'))->getParameters()[0];
        self::assertSame('identifier', $parameter->getName());
        self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function load_sends_script_load_with_the_files_bytes(string $kind): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../lua/sliding-window.lua');
        [$limiter, $sent] = $this->limiter($kind, [['reply', sha1($source)]]);
        $limiter->load();
        self::assertSame([['SCRIPT', 'LOAD', $source]], $sent());
    }

    #[Test]
    #[DataProvider('kinds')]
    public function load_throws_on_a_different_sha(string $kind): void
    {
        [$limiter] = $this->limiter($kind, [['reply', str_repeat('0', 40)]]);
        $this->expectException(\UnexpectedValueException::class);
        $limiter->load();
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function load_throws_every_client_error_without_the_callback(string $kind, FailMode $mode): void
    {
        foreach (['LOADING loading', 'WRONGPASS wrong', 'BUSY busy'] as $reply) {
            [$limiter] = $this->limiter($kind, [['error', $reply]], $mode);
            try {
                $limiter->load();
                self::fail('no exception');
            } catch (\Throwable $error) {
                self::assertSame($reply, $error->getMessage());
            }
        }
        self::assertSame([], $this->reports);
    }

    /** @return iterable<string, array{int, bool, int}> */
    public static function retryAfterCases(): iterable
    {
        yield 'allowed' => [0, true, 0];
        yield 'denied 1 ms' => [1, false, 1];
        yield 'denied 1000 ms' => [1000, false, 1];
        yield 'denied 1001 ms' => [1001, false, 2];
        yield 'denied 59999 ms' => [59999, false, 60];
        yield 'denied 0 ms' => [0, false, 1];
    }

    #[Test]
    #[DataProvider('retryAfterCases')]
    public function retry_after_seconds(int $retryAfterMs, bool $allowed, int $expected): void
    {
        $result = new Result($allowed, 0, $retryAfterMs, 60000, new \DateTimeImmutable(), false);
        self::assertSame($expected, $result->retryAfterSeconds());
    }
}
