<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Integration;

use GeorgiMorozov\RedisSlidingWindow\Laravel\Limiters;
use GeorgiMorozov\RedisSlidingWindow\Laravel\RedisSlidingWindowServiceProvider;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\Server;
use Illuminate\Foundation\Auth\User;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** The Laravel provider and route middleware on a real server, through Laravel's own Redis connection. */
final class LaravelTest extends TestCase
{
    private string $prefix;

    protected function getPackageProviders($app): array
    {
        return [RedisSlidingWindowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->prefix = 't' . Server::hex(6) . ':';
        $app['config']->set('database.redis.default', [
            'host' => Server::host(),
            'port' => Server::port(),
            'database' => 0,
            'prefix' => '',
            'timeout' => 2.0,
            'read_timeout' => 2.0,
        ]);
        $app['config']->set('database.redis.options', ['prefix' => '']);
        $app['config']->set('redis-sliding-window.prefix', $this->prefix);
        $app['config']->set('redis-sliding-window.limiters', [
            'login' => ['limit' => 2, 'window_ms' => 60000, 'fail_mode' => 'closed'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', static fn() => 'ok')->middleware('sliding-window:login');
    }

    /** @return iterable<string, array{string}> */
    public static function clients(): iterable
    {
        yield 'predis' => ['predis'];
        yield 'phpredis' => ['phpredis'];
    }

    private function useClient(string $kind): void
    {
        config()->set('database.redis.client', $kind);
        app()->forgetInstance('redis');
        app()->forgetInstance(Limiters::class);
    }

    #[Test]
    #[DataProvider('clients')]
    public function the_route_answers_200_200_then_429_with_retry_after_keyed_by_ip(string $kind): void
    {
        $this->useClient($kind);
        $this->get('/login')->assertOk()->assertContent('ok');
        $this->get('/login')->assertOk();
        $denied = $this->get('/login')->assertStatus(429);
        $retryAfter = (int) $denied->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(59, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
        self::assertSame(2, Server::predis()->zcard($this->prefix . 'login:60000:ip:127.0.0.1'));
    }

    #[Test]
    #[DataProvider('clients')]
    public function a_signed_in_user_is_counted_under_their_id(string $kind): void
    {
        $this->useClient($kind);
        $user = new User();
        $user->forceFill(['id' => 42]);
        $this->actingAs($user)->get('/login')->assertOk();
        self::assertSame(1, Server::predis()->zcard($this->prefix . 'login:60000:user:42'));
        self::assertSame(0, Server::predis()->exists($this->prefix . 'login:60000:ip:127.0.0.1'));
    }

    /** A port with no listener: every hit is a refused connection, so every result is degraded. */
    private static function deadPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        return $port;
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/rsw-warn-' . Server::hex(8);
        mkdir($dir);

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
    }

    #[Test]
    public function the_file_store_throttles_warnings_across_fresh_app_instances(): void
    {
        $dir = self::tempDir();
        $port = self::deadPort();
        try {
            $warnings = 0;
            for ($request = 0; $request < 3; $request++) {
                // A fresh application per request, as under PHP-FPM; only the cache directory carries over.
                $this->refreshApplication();
                config()->set('database.redis.client', 'predis');
                config()->set('database.redis.default.port', $port);
                config()->set('cache.stores.file.path', $dir);
                config()->set('redis-sliding-window.prefix', 'rsw-itest:');
                config()->set('redis-sliding-window.limiters', ['api' => ['limit' => 5, 'window_ms' => 1000, 'fail_mode' => 'open']]);
                \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(static function () use (&$warnings): void {
                    $warnings++;
                });
                $result = app(Limiters::class)->get('api')->hit('x');
                self::assertTrue($result->degraded);
                self::assertTrue($result->allowed);
            }
            self::assertSame(1, $warnings);
        } finally {
            self::removeDir($dir);
        }
    }

    #[Test]
    public function concurrent_processes_sharing_a_file_store_log_one_warning(): void
    {
        $dir = self::tempDir();
        $log = $dir . '/warnings.log';
        $port = self::deadPort();
        $start = sprintf('%.6F', microtime(true) + 2.0);
        try {
            $processes = [];
            for ($i = 0; $i < 12; $i++) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/bin/warning-hit.php', $dir . '/cache', $log, (string) $port, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $outcomes = [];
            foreach ($processes as [$process, $pipes]) {
                $outcomes[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
                proc_close($process);
            }
            self::assertSame(array_fill(0, 12, 'degraded'), $outcomes, implode(', ', array_unique($outcomes)));
            $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
            self::assertCount(1, $lines, implode("\n", $lines));
            self::assertStringStartsWith('warning Redis or Valkey unavailable: connection {"limiter":"api","reason":"connection","fail_mode":"open"}', $lines[0]);
        } finally {
            self::removeDir($dir);
        }
    }
}
