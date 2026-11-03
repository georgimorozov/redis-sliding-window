<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Internal;

/**
 * One client library: sends raw commands and classifies their errors (docs/design.md, "Failure policy").
 *
 * @internal
 */
interface Adapter
{
    public const NOSCRIPT = 'NOSCRIPT';

    /**
     * Sends one command. A server error reply the client returns as a value is thrown as the client's own
     * exception, with the reply as its message.
     *
     * @param non-empty-list<string> $command
     */
    public function call(array $command): mixed;

    /**
     * Null: throw the error unchanged. Adapter::NOSCRIPT: retry with EVAL. Otherwise the degrade reason.
     *
     * @return null|'NOSCRIPT'|'connection'|'timeout'|'LOADING'|'BUSY'|'MASTERDOWN'|'READONLY'
     */
    public function classify(\Throwable $error): ?string;
}
