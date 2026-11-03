<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Internal;

/**
 * Step 2 of the failure policy: a server error reply by its code, the first word of its message.
 *
 * @internal
 */
final class ReplyCode
{
    private const DEGRADING = ['LOADING', 'BUSY', 'MASTERDOWN', 'READONLY'];

    /** @return null|'NOSCRIPT'|'LOADING'|'BUSY'|'MASTERDOWN'|'READONLY' */
    public static function classify(string $reply, bool $allowNoscript = true): ?string
    {
        $code = explode(' ', $reply, 2)[0];
        if ($code === Adapter::NOSCRIPT) {
            return $allowNoscript ? Adapter::NOSCRIPT : null;
        }
        foreach (self::DEGRADING as $degrading) {
            if ($code === $degrading) {
                return $degrading;
            }
        }

        return null;
    }
}
