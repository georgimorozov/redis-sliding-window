<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Laravel;

use GeorgiMorozov\RedisSlidingWindow\ErrorContext;
use GeorgiMorozov\RedisSlidingWindow\FailMode;
use GeorgiMorozov\RedisSlidingWindow\SlidingWindowLimiter;
use GeorgiMorozov\RedisSlidingWindow\UnavailableReport;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Psr\Log\LoggerInterface;

/**
 * The limiters configured in config/redis-sliding-window.php, built once per name on the configured
 * Redis connection's client. A degraded result is logged as a warning (reason, limiter name, fail mode;
 * never the identifier), at most once a minute per limiter when `warning_store` names a cache store: the
 * marker is taken with an atomic add() on that store, resolved only on the degraded path. A store that fails
 * or cannot confirm the marker logs anyway (visibility over strictness); it never throws.
 */
final class Limiters
{
    /** @var array<string, SlidingWindowLimiter> */
    private array $limiters = [];

    public function __construct(
        private readonly Factory $redis,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly CacheFactory $cache,
    ) {}

    public const WARNING_TTL_SECONDS = 60;

    public function get(string $name): SlidingWindowLimiter
    {
        return $this->limiters[$name] ??= $this->build($name);
    }

    private function build(string $name): SlidingWindowLimiter
    {
        $settings = $this->config->get('redis-sliding-window.limiters.' . $name);
        if (!is_array($settings)) {
            throw new \InvalidArgumentException(
                "redis-sliding-window: no limiter named \"{$name}\" in config/redis-sliding-window.php",
            );
        }
        $limit = $settings['limit'] ?? null;
        $windowMs = $settings['window_ms'] ?? null;
        $failMode = is_string($settings['fail_mode'] ?? null) ? FailMode::tryFrom($settings['fail_mode']) : null;
        if (!is_int($limit) || !is_int($windowMs) || $failMode === null) {
            throw new \InvalidArgumentException(
                "redis-sliding-window: limiter \"{$name}\" needs an integer limit and window_ms and a fail_mode of open or closed",
            );
        }
        $connection = $this->config->get('redis-sliding-window.connection');
        $prefix = $this->config->get('redis-sliding-window.prefix', 'rsw:');
        if (!is_string($connection) || !is_string($prefix)) {
            throw new \InvalidArgumentException('redis-sliding-window: connection and prefix must be strings');
        }
        $client = $this->redis->connection($connection)->client();
        if (!$client instanceof \Predis\ClientInterface && !$client instanceof \Redis) {
            throw new \InvalidArgumentException(
                "redis-sliding-window: Redis connection \"{$connection}\" must use the predis or phpredis client on one node",
            );
        }
        $logger = $this->logger;
        $warningStore = $this->warningStore();
        $cache = $this->cache;
        $marker = 'rsw-warned:' . sha1($prefix . "\0" . $name);

        return new SlidingWindowLimiter(
            $client,
            $name,
            $limit,
            $windowMs,
            $failMode,
            static function (UnavailableReport $report, ErrorContext $context) use ($logger, $cache, $warningStore, $marker): void {
                if ($warningStore !== null && !self::claimWarning($cache, $warningStore, $marker)) {
                    return;
                }
                $logger->warning($report->message, [
                    'limiter' => $context->name,
                    'reason' => $report->reason,
                    'fail_mode' => $context->failMode->value,
                ]);
            },
            $prefix,
        );
    }

    private function warningStore(): ?string
    {
        if (!$this->config->has('redis-sliding-window.warning_store')) {
            return 'file';
        }
        $store = $this->config->get('redis-sliding-window.warning_store');
        if ($store !== null && (!is_string($store) || $store === '')) {
            throw new \InvalidArgumentException('redis-sliding-window: warning_store must be a cache store name or null');
        }

        return $store;
    }

    /** True when this caller should log: it took the marker, or the store could not show that another did. */
    private static function claimWarning(CacheFactory $cache, string $store, string $marker): bool
    {
        try {
            $repository = $cache->store($store);
            if ($repository->add($marker, 1, self::WARNING_TTL_SECONDS)) {
                return true;
            }

            return !$repository->has($marker);
        } catch (\Throwable) {
            // The warning store failed: log the degraded result anyway (its error is not ours to report and may
            // carry paths or hosts, see UnavailableReport), and never let it fail the request.
            return true;
        }
    }
}
