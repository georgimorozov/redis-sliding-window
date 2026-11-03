<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Unit\Laravel;

use GeorgiMorozov\RedisSlidingWindow\Laravel\LimitRequests;
use GeorgiMorozov\RedisSlidingWindow\Laravel\Limiters;
use GeorgiMorozov\RedisSlidingWindow\Laravel\RedisSlidingWindowServiceProvider;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\FakePredis;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\RecordingCacheFactory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
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
        $app['config']->set('redis-sliding-window.warning_store', 'array');
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

    private const BUSY = 'Redis or Valkey unavailable: BUSY';

    private function busy(int $count): void
    {
        $this->script(array_fill(0, $count, FakePredis::serverError('BUSY busy')));
    }

    /** Swaps the cache factory for one that records store() calls and returns $repository (or throws). */
    private function cacheFactory(?CacheRepositoryContract $repository, ?\Throwable $error = null): RecordingCacheFactory
    {
        $factory = new RecordingCacheFactory($repository, $error);
        app()->instance(CacheFactory::class, $factory);
        app()->forgetInstance(Limiters::class);

        return $factory;
    }

    #[Test]
    public function degraded_warnings_are_throttled_to_one_per_minute_per_limiter(): void
    {
        $log = Log::spy();
        $this->busy(5);
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        $log->shouldHaveReceived('warning')->with(self::BUSY, ['limiter' => 'api', 'reason' => 'BUSY', 'fail_mode' => 'open'])->once();
        $log->shouldHaveReceived('warning')->with(self::BUSY, ['limiter' => 'login', 'reason' => 'BUSY', 'fail_mode' => 'closed'])->once();
    }

    #[Test]
    public function the_warning_returns_after_a_minute_and_suppressed_hits_do_not_renew_it(): void
    {
        $log = Log::spy();
        $this->busy(4);
        $this->get('/api')->assertOk();
        $this->travel(59)->seconds();
        $this->get('/api')->assertOk();
        $this->travel(2)->seconds();
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $log->shouldHaveReceived('warning')->twice();
    }

    #[Test]
    public function different_prefixes_do_not_share_a_marker(): void
    {
        $log = Log::spy();
        $this->busy(2);
        $this->get('/api')->assertOk();
        config()->set('redis-sliding-window.prefix', 'other:');
        app()->forgetInstance(Limiters::class);
        $this->get('/api')->assertOk();
        $log->shouldHaveReceived('warning')->twice();
    }

    #[Test]
    public function a_null_warning_store_logs_every_result_without_resolving_a_store(): void
    {
        config()->set('redis-sliding-window.warning_store', null);
        $log = Log::spy();
        $this->busy(3);
        $factory = $this->cacheFactory(null);
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $log->shouldHaveReceived('warning')->times(3);
        self::assertSame([], $factory->stores);
    }

    #[Test]
    public function a_failing_warning_store_still_logs_and_never_fails_the_request(): void
    {
        $log = Log::spy();
        $this->busy(3);
        $this->cacheFactory(null, new \RuntimeException('disk full'));
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        $log->shouldHaveReceived('warning')->times(3);
    }

    #[Test]
    public function a_store_whose_add_always_fails_logs_every_result(): void
    {
        // Laravel's null driver: add() is false and nothing is stored, so the follow-up has() finds no marker.
        $log = Log::spy();
        $this->busy(2);
        $this->cacheFactory(new CacheRepository(new NullStore()));
        $this->get('/api')->assertOk();
        $this->get('/api')->assertOk();
        $log->shouldHaveReceived('warning')->twice();
    }

    #[Test]
    public function healthy_results_never_touch_the_warning_store(): void
    {
        $this->script([[1, 1, 0, 60000], [0, 0, 30500, 60000]]);
        $factory = $this->cacheFactory(new CacheRepository(new ArrayStore()));
        $this->get('/login')->assertOk();
        $this->get('/login')->assertStatus(429);
        self::assertSame([], $factory->stores);
    }

    #[Test]
    public function the_warning_store_defaults_to_file_and_must_be_a_name_or_null(): void
    {
        $config = require __DIR__ . '/../../../config/redis-sliding-window.php';
        self::assertSame('file', $config['warning_store']);
        $this->script([]);
        config()->set('redis-sliding-window.warning_store', 42);
        $this->expectExceptionMessage('warning_store must be a cache store name or null');
        app(Limiters::class)->get('login');
    }

    #[Test]
    public function a_logger_failure_after_the_marker_keeps_the_degraded_result(): void
    {
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('logger down'));
        $this->busy(1);
        $log = (string) tempnam(sys_get_temp_dir(), 'rsw');
        $previous = ini_set('error_log', $log);
        try {
            $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertSame(1, substr_count((string) file_get_contents($log), 'onError callback failed: RuntimeException: logger down'));
        unlink($log);
    }

    #[Test]
    public function a_connection_failure_lets_a_fail_open_route_answer_normally(): void
    {
        // docs/design-pitfalls PIT-1: a cache connection failure must not fail a fairness-limited request.
        $this->script([
            static fn(NodeConnectionInterface $c): \Throwable => new StreamInitException('Connection timed out'),
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
