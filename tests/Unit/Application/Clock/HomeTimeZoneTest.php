<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Clock;

use App\Application\Clock\HomeTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HomeTimeZone::class)]
final class HomeTimeZoneTest extends TestCase
{
    public function testReadsATimeWithoutOffsetAsLocalAndReturnsUtc(): void
    {
        $parsed = new HomeTimeZone('Europe/Vienna')->parseLocal('2026-11-08 12:00:00');

        self::assertSame('2026-11-08T11:00:00+00:00', $parsed?->format(\DATE_ATOM));
    }

    public function testKeepsAnExplicitOffset(): void
    {
        $parsed = new HomeTimeZone('Europe/Vienna')->parseLocal('2026-11-08T12:00:00+05:00');

        self::assertSame('2026-11-08T07:00:00+00:00', $parsed?->format(\DATE_ATOM));
    }

    #[DataProvider('unreadableTimes')]
    public function testReturnsNullForBlankOrInvalidTimes(string $value): void
    {
        self::assertNull(new HomeTimeZone('Europe/Vienna')->parseLocal($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTimes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'invalid' => ['not a time'];
    }
}
