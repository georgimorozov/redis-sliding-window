<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Feature;

use GeorgiMorozov\RedisSlidingWindow\Laravel\RedisSlidingWindowServiceProvider;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\Server;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature level: requests through the real sliding-window middleware while Redis is down (Predis to a port with
 * no listener), with the default file warning store: each route keeps its fail mode's response, and each
 * limiter logs one warning while its marker lives.
 */
final class LaravelWarningTest extends TestCase
{
    private string $cacheDir;

    protected function getPackageProviders($app): array
    {
        return [RedisSlidingWindowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->cacheDir = sys_get_temp_dir() . '/rsw-feature-' . Server::hex(8);
        $app['config']->set('database.redis.client', 'predis');
        $app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => (int) substr($name, strrpos($name, ':') + 1),
            'database' => 0,
            'prefix' => '',
            'timeout' => 0.5,
        ]);
        $app['config']->set('database.redis.options', ['prefix' => '']);
        $app['config']->set('cache.stores.file.path', $this->cacheDir);
        $app['config']->set('redis-sliding-window.limiters', [
            'login' => ['limit' => 2, 'window_ms' => 60000, 'fail_mode' => 'closed'],
            'api' => ['limit' => 100, 'window_ms' => 1000, 'fail_mode' => 'open'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', static fn() => 'ok')->middleware('sliding-window:login');
        $router->get('/api', static fn() => 'ok')->middleware('sliding-window:api');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->cacheDir);
        parent::tearDown();
    }

    #[Test]
    public function an_outage_keeps_each_fail_mode_and_logs_one_warning_per_limiter(): void
    {
        self::assertSame('file', config('redis-sliding-window.warning_store'));
        $log = Log::spy();
        for ($i = 0; $i < 3; $i++) {
            $this->get('/api')->assertOk()->assertContent('ok');
            $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        }
        $message = 'Redis or Valkey unavailable: connection';
        $log->shouldHaveReceived('warning')->with($message, ['limiter' => 'api', 'reason' => 'connection', 'fail_mode' => 'open'])->once();
        $log->shouldHaveReceived('warning')->with($message, ['limiter' => 'login', 'reason' => 'connection', 'fail_mode' => 'closed'])->once();
        $log->shouldHaveReceived('warning')->twice();

        $this->travel(61)->seconds();
        $this->get('/api')->assertOk();
        $log->shouldHaveReceived('warning')->times(3);
    }
}
