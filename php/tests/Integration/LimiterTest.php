<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Integration;

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\Internal\Script;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\Tests\Support\Server;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Real-server behavior beyond the vectors, with Predis and phpredis (docs/design.md, "Acceptance outcomes",
 * integration). Some tests change server-wide state (SCRIPT FLUSH, CLIENT PAUSE, REPLICAOF,
 * busy-reply-threshold, ACL users), so run this suite against a server nothing else uses.
 */
final class LimiterTest extends TestCase
{
    private Client $admin;

    /** @var list<array{UnavailableReport, ErrorContext}> */
    private array $reports = [];

    protected function setUp(): void
    {
        $this->admin = Server::predis();
    }

    protected function tearDown(): void
    {
        $this->admin->disconnect();
    }

    /** @return iterable<string, array{'predis'|'phpredis'}> */
    public static function kinds(): iterable
    {
        yield 'predis' => ['predis'];
        yield 'phpredis' => ['phpredis'];
    }

    /** @return iterable<string, array{'predis'|'phpredis', FailMode}> */
    public static function kindsAndModes(): iterable
    {
        foreach (['predis', 'phpredis'] as $kind) {
            foreach (FailMode::cases() as $mode) {
                yield "{$kind}, fail {$mode->value}" => [$kind, $mode];
            }
        }
    }

    private function limiter(Client|\Redis $client, FailMode $mode = FailMode::Closed, ?string $name = null, int $limit = 3, int $windowMs = 60000): SlidingWindowLimiter
    {
        return new SlidingWindowLimiter($client, $name ?? 'it-' . Server::hex(4), $limit, $windowMs, $mode, function (UnavailableReport $report, ErrorContext $context): void {
            $this->reports[] = [$report, $context];
        }, 'rsw-itest:');
    }

    /** @return list<array{UnavailableReport, ErrorContext}> */
    private function reported(): array
    {
        return $this->reports;
    }

    private static function key(string $name, int $windowMs, string $id): string
    {
        return "rsw-itest:{$name}:{$windowMs}:{$id}";
    }

    #[Test]
    public function forty_concurrent_hits_from_separate_processes_allow_exactly_seven(): void
    {
        $name = 'burst-' . Server::hex(4);
        $this->limiter($this->admin, name: $name)->load();
        $start = sprintf('%.6F', microtime(true) + 2.0);
        $processes = [];
        for ($i = 0; $i < 40; $i++) {
            $kind = $i % 2 === 0 ? 'predis' : 'phpredis';
            $process = proc_open([PHP_BINARY, __DIR__ . '/bin/burst-hit.php', $kind, $name, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $outcomes[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
            proc_close($process);
        }
        $counts = array_count_values($outcomes);
        self::assertSame(['allowed' => 7, 'denied' => 33], [
            'allowed' => $counts['allowed'] ?? 0,
            'denied' => $counts['denied'] ?? 0,
        ], implode(', ', array_unique($outcomes)));
        self::assertSame(7, $this->admin->zcard(self::key($name, 60000, 'burst')));
    }

    #[Test]
    public function an_entry_exactly_window_ms_old_is_pruned_and_one_1_ms_younger_counts(): void
    {
        $fixed = str_replace("redis.call('TIME')", '{ARGV[4], ARGV[5]}', Script::source());
        self::assertNotSame(Script::source(), $fixed);
        $key = 'rsw-itest:boundary-' . Server::hex(4) . ':1000:x';
        $now = 1_800_000_000_000_000;
        $this->admin->zadd($key, [(string) 'exactly-window-old' => $now - 1_000_000, 'one-ms-younger' => $now - 999_000]);
        $this->admin->pexpire($key, 60000);
        $reply = $this->admin->eval($fixed, 1, $key, '1', '1000', Server::hex(16), (string) intdiv($now, 1_000_000), '0');
        self::assertSame([0, 0, 1, 1], $reply);
        self::assertSame(['one-ms-younger'], $this->admin->zrange($key, 0, -1));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function a_denied_call_leaves_the_ttl_unchanged(string $kind): void
    {
        $name = 'ttl-' . Server::hex(4);
        $limiter = $this->limiter(Server::client($kind), name: $name, limit: 1);
        self::assertTrue($limiter->hit('x')->allowed);
        $key = self::key($name, 60000, 'x');
        $this->admin->pexpire($key, 30000);
        self::assertFalse($limiter->hit('x')->allowed);
        $ttl = $this->admin->pttl($key);
        self::assertLessThanOrEqual(30000, $ttl);
        self::assertGreaterThan(25000, $ttl);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function the_key_expires_window_ms_after_its_newest_entry(string $kind): void
    {
        $name = 'expiry-' . Server::hex(4);
        $this->limiter(Server::client($kind), name: $name, windowMs: 300)->hit('x');
        $key = self::key($name, 300, 'x');
        $ttl = $this->admin->pttl($key);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(301, $ttl);
        usleep(400_000);
        self::assertSame(0, $this->admin->exists($key));
    }

    #[Test]
    public function a_replay_counts_once_and_after_expiry_is_a_new_request(): void
    {
        $script = Script::source();
        $key = 'rsw-itest:replay-' . Server::hex(4) . ':60000:x';
        $a = Server::hex(16);
        self::assertSame([1, 1, 0, 60000], $this->admin->eval($script, 1, $key, '2', '60000', $a));
        usleep(50_000);
        self::assertSame([1, 0, 0, 60000], $this->admin->eval($script, 1, $key, '2', '60000', Server::hex(16)));
        $before = $this->admin->zrange($key, 0, -1, ['withscores' => true]);
        $this->admin->pexpire($key, 50000);
        $replay = $this->admin->eval($script, 1, $key, '2', '60000', $a);
        self::assertIsArray($replay);
        self::assertSame([1, 0, 0], array_slice($replay, 0, 3));
        self::assertGreaterThan(59000, $replay[3]);
        self::assertSame($before, $this->admin->zrange($key, 0, -1, ['withscores' => true]));
        self::assertLessThanOrEqual(50000, $this->admin->pttl($key));
        // Full bucket: a new nonce is denied.
        self::assertSame(0, ($this->admin->eval($script, 1, $key, '2', '60000', Server::hex(16)))[0]);

        $short = 'rsw-itest:replay-expired-' . Server::hex(4) . ':300:x';
        $this->admin->eval($script, 1, $short, '2', '300', $a);
        usleep(400_000);
        self::assertSame([1, 1, 0, 300], $this->admin->eval($script, 1, $short, '2', '300', $a));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function load_caches_the_sha_and_after_script_flush_hit_uses_eval(string $kind): void
    {
        $limiter = $this->limiter(Server::client($kind));
        $limiter->load();
        self::assertSame([1], $this->admin->script('EXISTS', Script::sha()));
        $this->admin->script('FLUSH');
        self::assertSame([0], $this->admin->script('EXISTS', Script::sha()));
        $result = $limiter->hit('x');
        self::assertTrue($result->allowed);
        self::assertFalse($result->degraded);
        self::assertSame([1], $this->admin->script('EXISTS', Script::sha()));
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function wrongtype_throws_without_the_callback(string $kind, FailMode $mode): void
    {
        $name = 'wrongtype-' . Server::hex(4);
        $this->admin->set(self::key($name, 60000, 'x'), 'not a sorted set', 'PX', 60000);
        try {
            $this->limiter(Server::client($kind), $mode, $name)->hit('x');
            self::fail('no exception');
        } catch (\Throwable $error) {
            self::assertStringStartsWith('WRONGTYPE', $error->getMessage());
        }
        self::assertSame([], $this->reports);
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function busy_degrades(string $kind, FailMode $mode): void
    {
        $original = $this->admin->executeRaw(['CONFIG', 'GET', 'busy-reply-threshold']);
        self::assertIsArray($original);
        $this->admin->executeRaw(['CONFIG', 'SET', 'busy-reply-threshold', '100']);
        $busy = proc_open([PHP_BINARY, '-r', sprintf(
            'require %s; $c = new Predis\Client(["host" => %s, "port" => %d, "read_write_timeout" => 5]); $c->eval(%s, 0);',
            var_export(__DIR__ . '/../../../vendor/autoload.php', true),
            var_export(Server::host(), true),
            Server::port(),
            var_export("local s = redis.call('TIME') repeat local t = redis.call('TIME') until (t[1] - s[1]) * 1000000 + (t[2] - s[2]) > 1000000 return 1", true),
        )], [], $pipes);
        try {
            usleep(400_000);
            $result = $this->limiter(Server::client($kind), $mode)->hit('x');
            self::assertTrue($result->degraded);
            self::assertSame($mode === FailMode::Open, $result->allowed);
            self::assertSame('BUSY', $this->reported()[0][0]->reason);
        } finally {
            self::assertIsResource($busy);
            proc_close($busy);
            $this->admin->executeRaw(['CONFIG', 'SET', 'busy-reply-threshold', is_string($original[1] ?? null) ? $original[1] : '5000']);
        }
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function readonly_degrades(string $kind, FailMode $mode): void
    {
        // A replica of an unreachable primary rejects writes; it never syncs, so no data is replaced.
        $this->admin->executeRaw(['REPLICAOF', '127.0.0.1', '1']);
        try {
            $result = $this->limiter(Server::client($kind), $mode)->hit('x');
            self::assertTrue($result->degraded);
            self::assertSame($mode === FailMode::Open, $result->allowed);
            self::assertSame('READONLY', $this->reported()[0][0]->reason);
        } finally {
            $this->admin->executeRaw(['REPLICAOF', 'NO', 'ONE']);
        }
    }

    private static function unconnected(string $kind, string $host, int $port, float $timeout, float $readTimeout = 1.0): Client|\Redis
    {
        if ($kind === 'predis') {
            return new Client(['host' => $host, 'port' => $port, 'timeout' => $timeout, 'read_write_timeout' => $readTimeout]);
        }
        $client = new \Redis();
        try {
            $client->connect($host, $port, $timeout, null, 0, $readTimeout);
        } catch (\RedisException) {
            // The limiter's first call sees the outage itself.
        }

        return $client;
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_refused_port_degrades_with_the_callback(string $kind, FailMode $mode): void
    {
        $result = $this->limiter(self::unconnected($kind, '127.0.0.1', 1, 1.0), $mode)->hit('x');
        self::assertTrue($result->degraded);
        self::assertSame($mode === FailMode::Open, $result->allowed);
        self::assertCount(1, $this->reports);
        self::assertSame('connection', $this->reported()[0][0]->reason);
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_connect_timeout_degrades_with_the_callback(string $kind, FailMode $mode): void
    {
        // 192.0.2.0/24 is TEST-NET-1 (RFC 5737): nothing answers there.
        $result = $this->limiter(self::unconnected($kind, '192.0.2.1', 6379, 0.3), $mode)->hit('x');
        self::assertTrue($result->degraded);
        self::assertSame($mode === FailMode::Open, $result->allowed);
        self::assertCount(1, $this->reports);
        self::assertContains($this->reported()[0][0]->reason, ['connection', 'timeout']);
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_lost_reply_from_a_paused_server_degrades(string $kind, FailMode $mode): void
    {
        $client = $kind === 'predis'
            ? Server::predis(['read_write_timeout' => 0.3])
            : Server::phpredis(readTimeout: 0.3);
        $client->ping();
        $limiter = $this->limiter($client, $mode);
        $this->admin->executeRaw(['CLIENT', 'PAUSE', '1000', 'ALL']);
        try {
            $result = $limiter->hit('x');
            self::assertTrue($result->degraded);
            self::assertSame($mode === FailMode::Open, $result->allowed);
            self::assertContains($this->reported()[0][0]->reason, ['connection', 'timeout']);
        } finally {
            usleep(1_100_000);
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function two_hundred_hits_on_one_client_open_at_most_one_connection(string $kind): void
    {
        $connections = function (): int {
            preg_match('/total_connections_received:(\d+)/', (string) $this->admin->executeRaw(['INFO', 'stats']), $match);

            return (int) ($match[1] ?? -1);
        };
        $before = $connections();
        $limiter = $this->limiter($kind === 'predis' ? Server::predis() : Server::phpredis(), limit: 1000);
        for ($i = 0; $i < 200; $i++) {
            $limiter->hit('x');
        }
        self::assertLessThanOrEqual(1, $connections() - $before);
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_wrong_password_throws_from_hit_and_load_without_the_callback(string $kind, FailMode $mode): void
    {
        $user = 'rsw-itest-' . Server::hex(4);
        $this->admin->executeRaw(['ACL', 'SETUSER', $user, 'on', '>right-password', '~*', '+@all']);
        try {
            foreach (['hit', 'load'] as $call) {
                if ($kind === 'predis') {
                    $client = Server::predis(['username' => $user, 'password' => 'wrong-password']);
                } else {
                    // phpredis authenticates while connecting; a later reconnect sends the stored credentials,
                    // which the server now refuses.
                    $client = Server::phpredis();
                    $client->auth([$user, 'right-password']);
                    $this->admin->executeRaw(['ACL', 'SETUSER', $user, 'resetpass', '>other-password']);
                    $this->admin->executeRaw(['CLIENT', 'KILL', 'USER', $user]);
                    usleep(100_000);
                }
                $limiter = $this->limiter($client, $mode);
                try {
                    $call === 'hit' ? $limiter->hit('x') : $limiter->load();
                    self::fail("{$call}: no exception");
                } catch (\Throwable $error) {
                    self::assertStringNotContainsString('no exception', $error->getMessage());
                }
                if ($kind === 'phpredis') {
                    $this->admin->executeRaw(['ACL', 'SETUSER', $user, 'resetpass', '>right-password']);
                }
            }
        } finally {
            $this->admin->executeRaw(['ACL', 'DELUSER', $user]);
        }
        self::assertSame([], $this->reports);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function the_right_password_works(string $kind): void
    {
        $user = 'rsw-itest-' . Server::hex(4);
        $this->admin->executeRaw(['ACL', 'SETUSER', $user, 'on', '>right-password', '~*', '+@all']);
        try {
            if ($kind === 'predis') {
                $client = Server::predis(['username' => $user, 'password' => 'right-password']);
            } else {
                $client = Server::phpredis();
                $client->auth([$user, 'right-password']);
            }
            $limiter = $this->limiter($client);
            $limiter->load();
            self::assertFalse($limiter->hit('x')->degraded);
        } finally {
            $this->admin->executeRaw(['ACL', 'DELUSER', $user]);
        }
    }

    /** @return iterable<string, array{string, FailMode, bool}> */
    public static function reconnects(): iterable
    {
        foreach (FailMode::cases() as $mode) {
            yield "AUTH reply lost, fail {$mode->value}" => ['auth-lost', $mode, true];
            yield "SELECT reply lost, fail {$mode->value}" => ['select-lost', $mode, true];
            yield "AUTH refused, fail {$mode->value}" => ['auth-refused', $mode, false];
        }
    }

    #[Test]
    #[DataProvider('reconnects')]
    public function a_phpredis_reconnect(string $scenario, FailMode $mode, bool $degrades): void
    {
        $server = proc_open([PHP_BINARY, __DIR__ . '/bin/fake-server.php', $scenario], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($server);
        try {
            $ready = (string) fgets($pipes[1]);
            self::assertMatchesRegularExpression('/^ready (\d+)\n$/', $ready);
            $port = (int) substr($ready, 6);
            $client = new \Redis();
            $client->connect('127.0.0.1', $port, 1.0, null, 0, 1.0);
            if ($scenario === 'select-lost') {
                $client->select(1);
            } else {
                $client->auth('secret');
            }
            usleep(300_000); // the fake server has closed the first connection
            $limiter = $this->limiter($client, $mode);
            try {
                $result = $limiter->hit('x');
            } catch (\RedisException $error) {
                self::assertFalse($degrades, 'threw: ' . $error->getMessage());
                self::assertSame([], $this->reports);

                return;
            }
            self::assertTrue($degrades, 'degraded instead of throwing');
            self::assertTrue($result->degraded);
            self::assertSame('connection', $this->reported()[0][0]->reason);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    #[Test]
    #[DataProvider('kindsAndModes')]
    public function a_predis_database_out_of_range_throws(string $kind, FailMode $mode): void
    {
        if ($kind !== 'predis') {
            self::assertFalse(Server::phpredis()->select(99));

            return;
        }
        $limiter = $this->limiter(Server::predis(['database' => 99]), $mode);
        try {
            $limiter->hit('x');
            self::fail('no exception');
        } catch (\Predis\Connection\ConnectionException $error) {
            self::assertStringContainsString('Failed: ERR', $error->getMessage());
        }
        self::assertSame([], $this->reports);
    }
}
