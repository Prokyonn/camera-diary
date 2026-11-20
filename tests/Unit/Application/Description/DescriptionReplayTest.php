<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Description\DescriptionReplay;
use App\Application\Description\EventDescription;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use App\Tests\Factory\CameraEventFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DescriptionReplay::class)]
final class DescriptionReplayTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<UnitOfWorkInterface> */
    private ObjectProphecy $unitOfWork;

    /** @var ObjectProphecy<EventIndexerInterface> */
    private ObjectProphecy $indexer;

    protected function setUp(): void
    {
        $this->unitOfWork = $this->prophesize(UnitOfWorkInterface::class);
        $this->indexer = $this->prophesize(EventIndexerInterface::class);
    }

    public function testStoresTheRecordedDescriptionAndIndexesTheEvent(): void
    {
        $event = $this->pending();
        $this->unitOfWork->flush()->shouldBeCalledOnce();
        $this->indexer->index($event)->shouldBeCalledOnce();

        self::assertTrue($this->replay()->replay($event, $this->description()));
        self::assertSame(EventStatus::Described, $event->getStatus());
        self::assertSame('Luna comes in.', $event->getSummary());
        self::assertSame('luna', $event->getIndividual());
        self::assertSame('gemini (recorded)', $event->getModel());
        self::assertEquals(new DateTimeImmutable('2026-11-08 12:00:00', new DateTimeZone('UTC')), $event->getDescribedAt());
    }

    public function testLeavesAnAlreadyDescribedEventAlone(): void
    {
        $event = CameraEventFactory::described('The postman.');
        $this->unitOfWork->flush()->shouldNotBeCalled();
        $this->indexer->index(Argument::any())->shouldNotBeCalled();

        self::assertFalse($this->replay()->replay($event, $this->description()));
        self::assertSame('The postman.', $event->getSummary());
    }

    public function testLeavesAnOpenEventAlone(): void
    {
        $event = CameraEventFactory::create(open: true);
        $this->unitOfWork->flush()->shouldNotBeCalled();

        self::assertFalse($this->replay()->replay($event, $this->description()));
        self::assertSame(EventStatus::Pending, $event->getStatus());
    }

    public function testFilesAnEventAsARepeatOfACheckedFalseAlarm(): void
    {
        $checked = CameraEventFactory::described('A web thread.', verdict: Verdict::FalseAlarm, falseAlarmCause: 'spider_web', id: 5);
        $event = $this->pending();
        $this->unitOfWork->flush()->shouldBeCalledOnce();
        $this->indexer->index($event)->shouldBeCalledOnce();

        self::assertTrue($this->replay()->replayRepeat($event, $checked));
        self::assertSame(5, $event->getRepeatOf());
        self::assertSame('spider_web', $event->getEffectiveFalseAlarmCause());
    }

    public function testDoesNotFileADescribedEventAsARepeat(): void
    {
        $checked = CameraEventFactory::described('A web thread.', verdict: Verdict::FalseAlarm, falseAlarmCause: 'spider_web', id: 5);
        $event = CameraEventFactory::described('The postman.');
        $this->unitOfWork->flush()->shouldNotBeCalled();

        self::assertFalse($this->replay()->replayRepeat($event, $checked));
        self::assertNull($event->getRepeatOf());
    }

    private function pending(): CameraEvent
    {
        return CameraEventFactory::withFrames();
    }

    private function description(): EventDescription
    {
        return new EventDescription(Verdict::Real, null, Side::OurProperty, 'cat', 'luna', 'Luna comes in.', false, Confidence::High, 'gemini (recorded)');
    }

    private function replay(): DescriptionReplay
    {
        return new DescriptionReplay($this->unitOfWork->reveal(), $this->indexer->reveal(), new MockClock('2026-11-08 12:00:00', 'UTC'));
    }
}
