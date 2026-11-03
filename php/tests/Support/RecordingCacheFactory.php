<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Support;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;

/** A cache factory that records every store() call and returns one repository, or throws. */
final class RecordingCacheFactory implements Factory
{
    /** @var list<mixed> */
    public array $stores = [];

    public function __construct(private readonly ?Repository $repository, private readonly ?\Throwable $error = null) {}

    public function store($name = null): Repository
    {
        $this->stores[] = $name;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->repository ?? throw new \LogicException('no store');
    }
}
