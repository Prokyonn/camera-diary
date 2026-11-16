<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Unit\Domain\Model;

use CameraDiary\HomeAssistant\Domain\Model\PushHandover;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushHandover::class)]
final class PushHandoverTest extends TestCase
{
    public function testParsesTheHandoverValue(): void
    {
        $handover = PushHandover::fromHandoverValue('garden|1793945800|1|garden-2026-11-06_07-16-40-person|reolink-garden|motion/garden/2026-11-06_07-16-40_person|🚶 Person in the garden|Person at 07:16:40 | front gate.');

        self::assertNotNull($handover);
        self::assertSame('garden', $handover->getCamera());
        self::assertSame('2026-11-06 06:16:40', $handover->getPushedAt()->format('Y-m-d H:i:s'));
        self::assertTrue($handover->hasMuteActions());
        self::assertSame('garden-2026-11-06_07-16-40-person', $handover->getTag());
        self::assertSame('reolink-garden', $handover->getGroup());
        self::assertSame('motion/garden/2026-11-06_07-16-40_person', $handover->getFileBase());
        self::assertSame('🚶 Person in the garden', $handover->getTitle());
        self::assertSame('Person at 07:16:40 | front gate.', $handover->getMessage(), 'The message may contain the separator.');
        self::assertFalse($handover->isUpdated());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'too few fields' => ['garden|1793945800|1|tag'];
        yield 'invalid camera' => ['Garden Cam|1793945800|1|tag|group|motion/a|Title|Message'];
        yield 'time is no epoch' => ['garden|2026-11-06|1|tag|group|motion/a|Title|Message'];
        yield 'mute is no flag' => ['garden|1793945800|yes|tag|group|motion/a|Title|Message'];
        yield 'empty tag' => ['garden|1793945800|1||group|motion/a|Title|Message'];
        yield 'path leaves the media folder' => ['garden|1793945800|1|tag|group|motion/../../secrets|Title|Message'];
        yield 'absolute path' => ['garden|1793945800|1|tag|group|/etc/passwd|Title|Message'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        self::assertNull(PushHandover::fromHandoverValue($value));
    }
}
