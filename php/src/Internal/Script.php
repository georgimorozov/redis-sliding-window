<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Internal;

/**
 * The exact bytes of lua/sliding-window.lua (shipped in the package) and their SHA-1, the script's EVALSHA id
 * (docs/design.md, "The Lua script").
 *
 * @internal
 */
final class Script
{
    private static ?string $source = null;

    public static function source(): string
    {
        if (self::$source === null) {
            $path = dirname(__DIR__, 3) . '/lua/sliding-window.lua';
            $source = @file_get_contents($path);
            if (!is_string($source)) {
                throw new \LogicException('redis-sliding-window: cannot read ' . $path);
            }
            self::$source = $source;
        }

        return self::$source;
    }

    public static function sha(): string
    {
        return sha1(self::source());
    }
}
