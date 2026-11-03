<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Support;

/** Scripted clients of both kinds from client-neutral steps: ['reply', value] or ['error', 'CODE message']. */
final class Clients
{
    public const KINDS = ['predis', 'phpredis'];

    /**
     * @param string $kind 'predis' or 'phpredis'
     * @param list<array{0: 'reply'|'error', 1: mixed}|\Closure> $steps closures are passed through unchanged
     * @param array<string, mixed> $predisOptions
     * @return array{\Predis\ClientInterface|\Redis, \Closure(): list<array<mixed>>}
     */
    public static function make(string $kind, array $steps, array $predisOptions = []): array
    {
        $translated = [];
        foreach ($steps as $step) {
            if ($step instanceof \Closure) {
                $translated[] = $step;
            } elseif ($step[0] === 'error') {
                $translated[] = $kind === 'predis'
                    ? FakePredis::serverError((string) $step[1])
                    : FakePhpRedis::serverError((string) $step[1]);
            } else {
                $translated[] = $step[1];
            }
        }
        if ($kind === 'predis') {
            $fake = new FakePredis($translated, $predisOptions);

            return [$fake->client, static fn(): array => $fake->sent];
        }
        $fake = new FakePhpRedis($translated);

        return [$fake, static fn(): array => $fake->sent];
    }
}
