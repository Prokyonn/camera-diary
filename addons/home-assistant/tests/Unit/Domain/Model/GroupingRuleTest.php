<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Unit\Domain\Model;

use App\Domain\Model\DetectionType;
use App\Tests\Factory\CameraEventFactory;
use CameraDiary\HomeAssistant\Domain\Model\GroupingRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupingRule::class)]
final class GroupingRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function detections(): iterable
    {
        yield 'same second' => ['2026-11-06 16:36:00', true];
        yield '2 minutes after the end' => ['2026-11-06 16:39:00', true];
        yield '2 minutes and 1 second after the end' => ['2026-11-06 16:39:01', false];
        yield 'before the start' => ['2026-11-06 16:35:59', false];
    }

    #[DataProvider('detections')]
    public function testJoinsWithinTheGap(string $detectedAt, bool $joins): void
    {
        $event = CameraEventFactory::create('2026-11-06 16:36:00', open: true);
        $event->addDetection(DetectionType::Animal, CameraEventFactory::utc('2026-11-06 16:37:00'));

        self::assertSame($joins, new GroupingRule()->joins($event, CameraEventFactory::utc($detectedAt)));
    }

    public function testCapsAnEventAtTenMinutes(): void
    {
        $rule = new GroupingRule();
        $event = CameraEventFactory::create('2026-11-06 16:36:00', open: true);
        foreach (['16:38:00', '16:40:00', '16:42:00', '16:44:00', '16:46:00'] as $time) {
            $event->addDetection(DetectionType::Animal, CameraEventFactory::utc('2026-11-06 '.$time));
        }

        self::assertFalse($rule->joins($event, CameraEventFactory::utc('2026-11-06 16:46:01')), 'A storm of detections must split into several events.');
    }

    public function testEventsEndingBeforeTheGapAreSettled(): void
    {
        $rule = new GroupingRule();

        self::assertEquals(CameraEventFactory::utc('2026-11-06 16:36:00'), $rule->closedBefore(CameraEventFactory::utc('2026-11-06 16:38:00')));
    }
}
