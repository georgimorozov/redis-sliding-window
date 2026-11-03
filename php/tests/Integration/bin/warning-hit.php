<?php

declare(strict_types=1);

// One process of the warning-throttle test: a separate PHP process (like one PHP-FPM request) that builds the
// Laravel Limiters registry on a Redis connection nobody listens on and a file cache store in <dir>, waits
// until <start> (microtime), sends one hit() and appends each logged warning to <log>.

use GeorgiMorozov\RedisSlidingWindow\Laravel\Limiters;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PredisConnection;
use Psr\Log\AbstractLogger;

require __DIR__ . '/../../../../vendor/autoload.php';

[, $dir, $log, $port, $start] = $argv;
$redis = new class ((int) $port) implements RedisFactory {
    public function __construct(private readonly int $port) {}

    public function connection($name = null): Connection
    {
        return new PredisConnection(new \Predis\Client(['host' => '127.0.0.1', 'port' => $this->port, 'timeout' => 1.0]));
    }
};
$cache = new class ($dir) implements CacheFactory {
    public function __construct(private readonly string $dir) {}

    public function store($name = null): CacheRepositoryContract
    {
        return new CacheRepository(new FileStore(new Filesystem(), $this->dir));
    }
};
$logger = new class ($log) extends AbstractLogger {
    public function __construct(private readonly string $file) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        file_put_contents($this->file, $level . ' ' . $message . ' ' . json_encode($context) . "\n", FILE_APPEND | LOCK_EX);
    }
};
$config = new Config(['redis-sliding-window' => [
    'connection' => 'default',
    'prefix' => 'rsw-itest:',
    'warning_store' => 'file',
    'limiters' => ['api' => ['limit' => 5, 'window_ms' => 1000, 'fail_mode' => 'open']],
]]);
$limiter = (new Limiters($redis, $config, $logger, $cache))->get('api');
$wait = (float) $start - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$result = $limiter->hit('burst');
echo $result->degraded ? 'degraded' : 'served';
