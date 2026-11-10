<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use App\Domain\Model\EventStatus;
use App\Domain\Model\FalseAlarmCause;
use App\Domain\Model\Side;
use App\Domain\Model\Subject;
use App\Domain\Model\Verdict;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CameraEvent::class)]
final class CameraEventTest extends TestCase
{
    public function testNewEventIsPendingAndSpansOneDetection(): void
    {
        $event = CameraEventFactory::create('2026-11-07 09:40:00', type: DetectionType::Person, open: true);

        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertEquals($event->getStartedAt(), $event->getEndedAt());
        self::assertSame([DetectionType::Person], $event->getDetectionTypes());
        self::assertSame(1, $event->getDetectionCount());
        self::assertTrue($event->canTakeDetections());
    }

    public function testAClosedEventTakesNoDetections(): void
    {
        $event = CameraEventFactory::create(open: true);
        $event->close();

        self::assertFalse($event->isOpen());
        self::assertFalse($event->canTakeDetections());

        $this->expectException(LogicException::class);

        $event->addDetection(DetectionType::Animal, CameraEventFactory::utc('2026-11-06 06:17:00'));
    }

    public function testCannotEndBeforeItStarts(): void
    {
        $this->expectException(LogicException::class);

        new CameraEvent(CameraFactory::get(), CameraEventFactory::utc('2026-11-07 09:40:00'), [], 'api', endedAt: CameraEventFactory::utc('2026-11-07 09:39:59'));
    }

    public function testKeepsDistinctTrimmedHints(): void
    {
        $event = CameraEventFactory::create();
        $event->addHint(' Doorbell rang ');
        $event->addHint('Doorbell rang');
        $event->addHint('   ');
        $event->addHint(str_repeat('x', 400));

        self::assertSame(['Doorbell rang', str_repeat('x', CameraEvent::MAX_HINT_LENGTH)], $event->getHints());
    }

    public function testAddDetectionExtendsTheEventAndMergesTypes(): void
    {
        $event = CameraEventFactory::create('2026-11-07 09:40:00', type: DetectionType::Person, open: true);

        $event->addDetection(DetectionType::Animal, CameraEventFactory::utc('2026-11-07 09:41:00'));
        $event->addDetection(DetectionType::Person, CameraEventFactory::utc('2026-11-07 09:40:30'));

        self::assertEquals(CameraEventFactory::utc('2026-11-07 09:41:00'), $event->getEndedAt());
        self::assertSame([DetectionType::Person, DetectionType::Animal], $event->getDetectionTypes());
        self::assertSame(3, $event->getDetectionCount());
    }

    public function testDetectionsCannotJoinAnEventWithFrames(): void
    {
        $event = CameraEventFactory::create(open: true);
        $event->recordFrames(['terrace/2026/11/06/1-0.jpg'], 640, 360);

        $this->expectException(LogicException::class);
        $event->addDetection(DetectionType::Animal, CameraEventFactory::utc('2026-11-06 06:17:00'));
    }

    public function testDescribeStoresTheAisVerdict(): void
    {
        $event = CameraEventFactory::create();
        $describedAt = CameraEventFactory::utc('2026-11-06 06:20:00');

        $event->describe(Verdict::Real, FalseAlarmCause::Insect, Side::OurProperty, Subject::Cat, 'A grey cat drags the doormat.', 'test-model', $describedAt);

        self::assertSame(EventStatus::Described, $event->getStatus());
        self::assertSame(Verdict::Real, $event->getEffectiveVerdict());
        self::assertSame(FalseAlarmCause::None, $event->getFalseAlarmCause(), 'A real event has no false-alarm cause.');
        self::assertSame('A grey cat drags the doormat.', $event->getSummary());
        self::assertSame($describedAt, $event->getDescribedAt());
        self::assertNull($event->getNextAttemptAt());
    }

    public function testManualVerdictOverridesTheAi(): void
    {
        $event = CameraEventFactory::described('Something moves in front of the lens.', verdict: Verdict::Real);

        $event->relabel('  spider web  ', Verdict::FalseAlarm);

        self::assertSame('spider web', $event->getLabel());
        self::assertSame(Verdict::FalseAlarm, $event->getEffectiveVerdict());
        self::assertTrue($event->isFalseAlarm());
    }

    public function testEmptyLabelIsStoredAsNull(): void
    {
        $event = CameraEventFactory::create();

        $event->relabel('   ', null);

        self::assertNull($event->getLabel());
        self::assertNull($event->getEffectiveVerdict());
        self::assertFalse($event->isFalseAlarm());
    }

    public function testRetryCountsAttempts(): void
    {
        $event = CameraEventFactory::create();
        $next = CameraEventFactory::utc('2026-11-06 06:30:00');

        $event->retryAt($next);

        self::assertSame(1, $event->getAttempts());
        self::assertSame($next, $event->getNextAttemptAt());
        self::assertSame(EventStatus::Pending, $event->getStatus());
    }

    public function testRemoveFramesReturnsThePaths(): void
    {
        $event = CameraEventFactory::described('A spider web thread.', verdict: Verdict::FalseAlarm);
        $removedAt = CameraEventFactory::utc('2026-12-10 03:30:00');

        self::assertSame(['terrace/frame-1793945800-0.jpg'], $event->removeFrames($removedAt));
        self::assertSame([], $event->getFramePaths());
        self::assertSame($removedAt, $event->getFramesRemovedAt());
    }

    public function testIdOfAnUnsavedEventThrows(): void
    {
        $this->expectException(LogicException::class);

        CameraEventFactory::create()->getId();
    }
}
