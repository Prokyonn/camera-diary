<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Side;
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
        $event = CameraEventFactory::create('2026-11-07 09:40:00', type: 'person', open: true);

        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertEquals($event->getStartedAt(), $event->getEndedAt());
        self::assertSame(['person'], $event->getDetectionTypes());
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

        $event->addDetection('animal', CameraEventFactory::utc('2026-11-06 06:17:00'));
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
        $event = CameraEventFactory::create('2026-11-07 09:40:00', type: 'person', open: true);

        $event->addDetection('animal', CameraEventFactory::utc('2026-11-07 09:41:00'));
        $event->addDetection('person', CameraEventFactory::utc('2026-11-07 09:40:30'));

        self::assertEquals(CameraEventFactory::utc('2026-11-07 09:41:00'), $event->getEndedAt());
        self::assertSame(['person', 'animal'], $event->getDetectionTypes());
        self::assertSame(3, $event->getDetectionCount());
    }

    public function testDetectionsCannotJoinAnEventWithFrames(): void
    {
        $event = CameraEventFactory::create(open: true);
        $event->recordFrames(['terrace/2026/11/06/1-0.jpg'], 640, 360);

        $this->expectException(LogicException::class);
        $event->addDetection('animal', CameraEventFactory::utc('2026-11-06 06:17:00'));
    }

    public function testDescribeStoresTheAisVerdict(): void
    {
        $event = CameraEventFactory::create();
        $describedAt = CameraEventFactory::utc('2026-11-06 06:20:00');

        $event->describe(Verdict::Real, 'insect', Side::OurProperty, 'cat', 'luna', 'A grey cat drags the doormat.', true, Confidence::Low, 'test-model', $describedAt);

        self::assertSame(EventStatus::Described, $event->getStatus());
        self::assertSame(Verdict::Real, $event->getEffectiveVerdict());
        self::assertNull($event->getFalseAlarmCause(), 'A real event has no false-alarm cause.');
        self::assertSame('luna', $event->getIndividual());
        self::assertSame('A grey cat drags the doormat.', $event->getSummary());
        self::assertTrue($event->isUnusual());
        self::assertSame($describedAt, $event->getDescribedAt());
        self::assertNull($event->getNextAttemptAt());
        self::assertSame([], $event->getAlsoSeen());
    }

    public function testListsTheOtherKnownIndividualsOnce(): void
    {
        $event = CameraEventFactory::create();

        $event->describe(Verdict::Real, null, Side::OurProperty, 'person', 'anna', 'Anna strokes Luna.', false, Confidence::High, 'test-model', CameraEventFactory::utc('2026-11-06 06:20:00'), ['luna', 'anna', 'luna']);

        self::assertSame(['luna'], $event->getAlsoSeen(), 'Without the subject\'s own individual and duplicates.');
        self::assertSame(['anna', 'luna'], $event->getIndividualsSeen());
        $event->relabel(null, null, CameraEvent::NO_KNOWN_INDIVIDUAL, null);
        self::assertSame(['luna'], $event->getIndividualsSeen(), 'A correction of the subject keeps the others.');
    }

    public function testManualVerdictOverridesTheAi(): void
    {
        $event = CameraEventFactory::described('Something moves in front of the lens.', verdict: Verdict::Real);

        $event->relabel('  spider web  ', Verdict::FalseAlarm, null, null);

        self::assertSame('spider web', $event->getLabel());
        self::assertSame(Verdict::FalseAlarm, $event->getEffectiveVerdict());
        self::assertTrue($event->isFalseAlarm());
    }

    public function testEmptyLabelIsStoredAsNull(): void
    {
        $event = CameraEventFactory::create();

        $event->relabel('   ', null, null, null);

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

    public function testAHandSetIndividualWinsOverTheAis(): void
    {
        $event = CameraEventFactory::described('A cat.', individual: 'luna');
        self::assertSame('luna', $event->getEffectiveIndividual());

        $event->relabel(null, null, 'tiger', null);
        self::assertSame('tiger', $event->getEffectiveIndividual());
        self::assertSame('luna', $event->getIndividual(), 'The AI\'s answer stays.');

        $event->relabel(null, null, CameraEvent::NO_KNOWN_INDIVIDUAL, null);
        self::assertNull($event->getEffectiveIndividual());

        $event->relabel(null, null, null, null);
        self::assertSame('luna', $event->getEffectiveIndividual());
    }

    public function testAnUnsureAnswerNeedsReviewUntilTheOwnerLooked(): void
    {
        self::assertFalse(CameraEventFactory::described('A cat.')->needsReview(), 'A sure answer needs no review.');
        self::assertFalse(CameraEventFactory::create()->needsReview(), 'A pending event has nothing to review.');

        $event = CameraEventFactory::described('Something dark at the hedge.', confidence: Confidence::Low);
        self::assertTrue($event->needsReview());

        $event->markReviewed(CameraEventFactory::utc('2026-11-06 07:00:00'));
        self::assertFalse($event->needsReview());
        self::assertFalse($event->isCorrected(), 'Confirming changes nothing.');

        $unusual = CameraEventFactory::create();
        $unusual->recordFrames(['terrace/1.jpg'], 8, 4);
        $unusual->describe(Verdict::Real, null, Side::OurProperty, 'person', null, 'A person at the shed at night.', true, Confidence::High, 'test-model', CameraEventFactory::utc('2026-11-06 07:00:00'));
        self::assertTrue($unusual->needsReview(), 'An unusual event is worth a look even when the AI is sure.');
    }

    public function testACorrectionChangesTheAisAnswer(): void
    {
        $event = CameraEventFactory::described('A cat.', individual: 'luna');

        $event->relabel(null, Verdict::Real, 'luna', null);
        self::assertFalse($event->isCorrected(), 'The same verdict and individual confirm the answer.');

        $event->relabel(null, Verdict::FalseAlarm, null, null);
        self::assertTrue($event->isCorrected());

        $event->relabel(null, null, CameraEvent::NO_KNOWN_INDIVIDUAL, null);
        self::assertTrue($event->isCorrected());

        $event->relabel('the neighbour\'s cat', null, null, null);
        self::assertTrue($event->isCorrected());
    }

    public function testAHandSetCauseCountsOnlyForAFalseAlarm(): void
    {
        $event = CameraEventFactory::described('Something bright at the lens.', verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'insect');

        $event->relabel(null, null, null, 'spider_web');
        self::assertSame('spider_web', $event->getEffectiveFalseAlarmCause());
        self::assertSame('insect', $event->getFalseAlarmCause(), 'The AI\'s answer stays.');
        self::assertTrue($event->isCorrected());

        $event->relabel(null, Verdict::Real, null, 'spider_web');
        self::assertNull($event->getEffectiveFalseAlarmCause(), 'A real event has no false-alarm cause.');
    }

    public function testARepeatTakesOverTheCheckedFalseAlarmWithoutNeedingReview(): void
    {
        $checked = CameraEventFactory::described('A spider web in front of the lens.', verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'insect', id: 7, confidence: Confidence::Low);
        $checked->relabel(null, null, null, 'spider_web');
        $repeat = CameraEventFactory::withFrames('2026-11-06 06:30:00');

        $repeat->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-06 06:31:00'));

        self::assertSame(EventStatus::Described, $repeat->getStatus());
        self::assertSame(7, $repeat->getRepeatOf());
        self::assertTrue($repeat->isRepeat());
        self::assertTrue($repeat->isFalseAlarm());
        self::assertSame('spider_web', $repeat->getEffectiveFalseAlarmCause(), 'The owner\'s cause, not the AI\'s.');
        self::assertSame('A spider web in front of the lens.', $repeat->getSummary());
        self::assertSame('test-model', $repeat->getModel());
        self::assertTrue($checked->needsReview());
        self::assertFalse($repeat->needsReview(), 'Only the checked one waits for a look.');
    }

    public function testOnlyACheckedFalseAlarmCanBeRepeated(): void
    {
        $real = CameraEventFactory::described('A cat.', id: 1);

        $this->expectException(LogicException::class);
        CameraEventFactory::withFrames()->describeAsRepeatOf($real, CameraEventFactory::utc('2026-11-06 06:31:00'));
    }

    public function testARepeatCannotBeRepeated(): void
    {
        $checked = CameraEventFactory::described('A spider web.', verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'spider_web', id: 1);
        $repeat = CameraEventFactory::withFrames('2026-11-06 06:30:00', id: 2);
        $repeat->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-06 06:31:00'));

        $this->expectException(LogicException::class);
        CameraEventFactory::withFrames('2026-11-06 06:40:00')->describeAsRepeatOf($repeat, CameraEventFactory::utc('2026-11-06 06:41:00'));
    }
}
