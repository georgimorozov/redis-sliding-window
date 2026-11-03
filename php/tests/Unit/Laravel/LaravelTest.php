<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Unit\Laravel;

use GeorgiMorozov\RedisSlidingWindow\Laravel\LimitRequests;
use GeorgiMorozov\RedisSlidingWindow\Laravel\Limiters;
use GeorgiMorozov\RedisSlidingWindow\Laravel\RedisSlidingWindowServiceProvider;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\FakePredis;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Auth\User;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Resource\Exception\StreamInitException;

/** The Laravel integration over a scripted Predis client (no server). */
final class LaravelTest extends TestCase
{
    private FakePredis $fake;

    protected function getPackageProviders($app): array
    {
        return [RedisSlidingWindowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('redis-sliding-window.limiters', [
            'login' => ['limit' => 2, 'window_ms' => 60000, 'fail_mode' => 'closed'],
            'api' => ['limit' => 100, 'window_ms' => 1000, 'fail_mode' => 'open'],
            'broken' => ['limit' => '2', 'window_ms' => 60000, 'fail_mode' => 'closed'],
            'no-mode' => ['limit' => 2, 'window_ms' => 60000, 'fail_mode' => 'sometimes'],
        ]);
    }

    /**
     * @param list<mixed> $steps
     * @param array<string, mixed> $options
     */
    private function script(array $steps, array $options = []): void
    {
        $this->fake = new FakePredis($steps, $options);
        $connection = new PredisConnection($this->fake->client);
        app()->instance(Factory::class, new class ($connection) implements Factory {
            public function __construct(private readonly Connection $connection) {}

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        });
        app()->forgetInstance(Limiters::class);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', static fn() => 'ok')->middleware('sliding-window:login');
        $router->get('/api', static fn() => 'ok')->middleware('sliding-window:api');
    }

    #[Test]
    public function composer_json_auto_discovers_the_provider(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([RedisSlidingWindowServiceProvider::class], $composer['extra']['laravel']['providers']);
    }

    #[Test]
    public function the_provider_merges_the_config_and_registers_the_alias_and_singleton(): void
    {
        self::assertSame('default', config('redis-sliding-window.connection'));
        self::assertSame('rsw:', config('redis-sliding-window.prefix'));
        self::assertSame(LimitRequests::class, app('router')->getMiddleware()['sliding-window']);
        self::assertSame(app(Limiters::class), app(Limiters::class));
    }

    #[Test]
    public function requests_over_the_limit_get_429_with_retry_after(): void
    {
        $this->script([[1, 1, 0, 60000], [1, 0, 0, 60000], [0, 0, 30500, 60000]]);
        $this->get('/login')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '31');
        self::assertSame('rsw:login:60000:ip:127.0.0.1', $this->fake->sent[0][3]);
    }

    #[Test]
    public function a_signed_in_user_is_limited_by_id(): void
    {
        $this->script([[1, 1, 0, 60000]]);
        $user = new User();
        $user->forceFill(['id' => 42]);
        $this->actingAs($user)->get('/login')->assertOk();
        self::assertSame('rsw:login:60000:user:42', $this->fake->sent[0][3]);
    }

    #[Test]
    public function a_degraded_result_logs_a_warning_without_the_identifier(): void
    {
        $log = Log::spy();
        $this->script([FakePredis::serverError('BUSY busy'), FakePredis::serverError('BUSY busy')]);
        $this->get('/api')->assertOk();
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        $log->shouldHaveReceived('warning')->with('Redis or Valkey unavailable: BUSY', ['limiter' => 'api', 'reason' => 'BUSY', 'fail_mode' => 'open'])->once();
        $log->shouldHaveReceived('warning')->with('Redis or Valkey unavailable: BUSY', ['limiter' => 'login', 'reason' => 'BUSY', 'fail_mode' => 'closed'])->once();
    }

    #[Test]
    public function a_connection_failure_lets_a_fail_open_route_answer_normally(): void
    {
        // docs/design-pitfalls PIT-1: a cache connection failure must not fail a fairness-limited request.
        $this->script([
            static fn(NodeConnectionInterface $c): \Throwable => new StreamInitException('Connection timed out'),
            static fn(NodeConnectionInterface $c): \Throwable => new ConnectionException($c, 'Connection refused [tcp://127.0.0.1:6379]'),
        ]);
        $this->get('/api')->assertOk()->assertContent('ok');
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
    }

    #[Test]
    public function limiters_are_built_once_per_name(): void
    {
        $this->script([]);
        $limiters = app(Limiters::class);
        self::assertSame($limiters->get('login'), $limiters->get('login'));
        self::assertNotSame($limiters->get('login'), $limiters->get('api'));
    }

    #[Test]
    public function an_unknown_or_misconfigured_limiter_throws(): void
    {
        $this->script([]);
        $limiters = app(Limiters::class);
        foreach (['missing' => 'no limiter named "missing"', 'broken' => 'needs an integer limit', 'no-mode' => 'needs an integer limit'] as $name => $message) {
            try {
                $limiters->get($name);
                self::fail('no exception for ' . $name);
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    #[Test]
    public function a_connection_with_a_key_prefix_is_rejected(): void
    {
        $this->script([], ['prefix' => 'laravel-database-']);
        $this->expectExceptionMessage('must not set a key prefix');
        app(Limiters::class)->get('login');
    }

    #[Test]
    public function route_facade_alias_works_in_a_group(): void
    {
        $this->script([[1, 1, 0, 60000]]);
        Route::middleware(RedisSlidingWindowServiceProvider::MIDDLEWARE . ':login')->get('/grouped', static fn() => 'ok');
        $this->get('/grouped')->assertOk();
    }
}
