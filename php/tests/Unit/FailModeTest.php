<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Tests\Unit;

use GeorgiMorozov\RedisSlidingWindow\FailMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailModeTest extends TestCase
{
    #[Test]
    public function its_values_are_the_shared_contract_strings(): void
    {
        self::assertSame(
            ['open', 'closed'],
            array_map(static fn(FailMode $mode): string => $mode->value, FailMode::cases()),
        );
        self::assertSame(FailMode::Open, FailMode::from('open'));
        self::assertSame(FailMode::Closed, FailMode::from('closed'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherValues(): iterable
    {
        yield 'unknown' => ['maybe'];
        yield 'case name' => ['Open'];
        yield 'upper case' => ['OPEN'];
    }

    #[Test]
    #[DataProvider('otherValues')]
    public function it_rejects_any_other_value(string $value): void
    {
        self::assertNull(FailMode::tryFrom($value));
        $this->expectException(\ValueError::class);
        FailMode::from($value);
    }
}
