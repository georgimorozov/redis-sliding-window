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
}
