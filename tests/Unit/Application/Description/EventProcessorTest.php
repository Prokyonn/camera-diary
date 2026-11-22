<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\EventDescribed;
use App\Application\Description\EventDescriberInterface;
use App\Application\Description\EventDescription;
use App\Application\Description\EventProcessor;
use App\Application\Description\HintCollector;
use App\Application\Description\HintProviderInterface;
use App\Application\Description\ProcessOutcome;
use App\Application\Description\RepeatedFalseAlarms;
use App\Application\Description\SceneChange;
use App\Application\Frame\BrightnessGrid;
use App\Application\Frame\FootageNotFoundException;
use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameSourceInterface;
use App\Application\Frame\FrameSources;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\CallPool;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\SettingsFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EventProcessor::class)]
final class EventProcessorTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    /** @var ObjectProphecy<FrameSourceInterface> */
    private ObjectProphecy $grabber;

    /** @var ObjectProphecy<HintProviderInterface> */
    private ObjectProphecy $hints;

    /** @var ObjectProphecy<FrameStorageInterface> */
    private ObjectProphecy $storage;

    /** @var ObjectProphecy<EventDescriberInterface> */
    private ObjectProphecy $describer;

    /** @var ObjectProphecy<AiCallBudgetInterface> */
    private ObjectProphecy $budget;

    /** @var ObjectProphecy<EventIndexerInterface> */
    private ObjectProphecy $indexer;

    /** @var ObjectProphecy<UnitOfWorkInterface> */
    private ObjectProphecy $unitOfWork;

    /** @var ObjectProphecy<EventDispatcherInterface> */
    private ObjectProphecy $eventDispatcher;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->events->findBy(Argument::cetera())->willReturn([]);
        $this->grabber = $this->prophesize(FrameSourceInterface::class);
        $this->grabber->supports(Argument::type(CameraEvent::class))->willReturn(true);
        $this->hints = $this->prophesize(HintProviderInterface::class);
        $this->hints->hintsFor(Argument::type(CameraEvent::class))->willReturn([]);
        $this->storage = $this->prophesize(FrameStorageInterface::class);
        foreach (array_keys(SettingsFactory::create()->description->frameOffsets) as $index) {
            $relativePath = \sprintf('terrace/2026/11/06/1-%d.jpg', $index);
            $this->storage->relativePathFor(Argument::type(CameraEvent::class), $index)->willReturn($relativePath);
            $this->storage->prepare($relativePath)->willReturn('/frames/'.$relativePath);
        }

        $this->storage->imageSize(Argument::type('string'))->willReturn([640, 360]);
        $this->storage->remove(Argument::type('array'));
        $this->describer = $this->prophesize(EventDescriberInterface::class);
        $this->budget = $this->prophesize(AiCallBudgetInterface::class);
        $this->budget->tryConsume(null)->willReturn(true);
        $this->indexer = $this->prophesize(EventIndexerInterface::class);
        $this->unitOfWork = $this->prophesize(UnitOfWorkInterface::class);
        $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
        $this->clock = new MockClock('2026-11-06 06:30:00', 'UTC');
    }

    public function testGrabsThreeFramesAndDescribesTheEvent(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1, source: 'home-assistant');
        $event->addHint('Doorbell rang');
        foreach (['06:16:41', '06:16:42', '06:16:43'] as $index => $time) {
            $this->grabber->grab($event, CameraEventFactory::utc('2026-11-06 '.$time), '/frames/terrace/2026/11/06/1-'.$index.'.jpg')->shouldBeCalledOnce();
        }

        $this->hints->hintsFor($event)->willReturn(['07:17:02 Luna came in through the cat flap']);
        $this->describer->describe($event, ['Doorbell rang', '07:17:02 Luna came in through the cat flap'])->willReturn(
            new EventDescription(Verdict::Real, null, Side::OurProperty, 'cat', 'luna', 'A grey cat drags the doormat to the door.', true, Confidence::High, 'test-model'),
        );
        $this->unitOfWork->flush()->shouldBeCalledOnce();
        $this->indexer->index($event)->shouldBeCalledOnce();
        $this->eventDispatcher->dispatch(new EventDescribed($event))->shouldBeCalledOnce()->willReturnArgument(0);

        self::assertSame(ProcessOutcome::Described, $this->processor()->process($event));
        self::assertSame(EventStatus::Described, $event->getStatus());
        self::assertTrue($event->isUnusual());
        self::assertSame('luna', $event->getIndividual());
        self::assertSame(['terrace/2026/11/06/1-0.jpg', 'terrace/2026/11/06/1-1.jpg', 'terrace/2026/11/06/1-2.jpg'], $event->getFramePaths());
        self::assertSame([640, 360], [$event->getFrameWidth(), $event->getFrameHeight()]);
        self::assertSame('test-model', $event->getModel());
    }

    public function testRefusesAnOpenEventWithoutTouchingIt(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1, open: true);
        $this->describer->describe(Argument::cetera())->shouldNotBeCalled();
        $this->unitOfWork->flush()->shouldNotBeCalled();
        $this->indexer->index(Argument::any())->shouldNotBeCalled();

        self::assertSame(ProcessOutcome::Open, $this->processor()->process($event));
        self::assertSame(EventStatus::Pending, $event->getStatus());
    }

    public function testMarksAnEventWithoutFramesAndFrameSourceAsNoFootage(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $this->grabber->supports($event)->willReturn(false);
        $this->budget->tryConsume()->shouldNotBeCalled();
        $this->eventDispatcher->dispatch(Argument::any())->shouldNotBeCalled();

        self::assertSame(ProcessOutcome::NoFootage, $this->processor()->process($event));
        self::assertSame(EventStatus::NoFootage, $event->getStatus());
    }

    public function testWaitsForFootageOfAYoungEvent(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $this->grabber->grab(Argument::cetera())->willThrow(FootageNotFoundException::class);
        $this->storage->remove([])->shouldBeCalled();
        $this->budget->tryConsume()->shouldNotBeCalled();

        self::assertSame(ProcessOutcome::Waiting, $this->processor()->process($event));
        self::assertEquals(CameraEventFactory::utc('2026-11-06 06:40:00'), $event->getNextAttemptAt());
    }

    public function testGivesUpOnFootageAfterAnHour(): void
    {
        $event = CameraEventFactory::create('2026-11-06 05:00:00', id: 1);
        $this->grabber->grab(Argument::cetera())->willThrow(FootageNotFoundException::class);

        self::assertSame(ProcessOutcome::NoFootage, $this->processor()->process($event));
        self::assertSame(EventStatus::NoFootage, $event->getStatus());
    }

    public function testRemovesPartialFramesWhenExtractionFails(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $this->grabber->grab(Argument::any(), CameraEventFactory::utc('2026-11-06 06:16:41'), Argument::any())->shouldBeCalled();
        $this->grabber->grab(Argument::any(), CameraEventFactory::utc('2026-11-06 06:16:42'), Argument::any())->willThrow(new FrameExtractionFailedException('moov atom not found'));
        $this->storage->remove(['terrace/2026/11/06/1-0.jpg'])->shouldBeCalledOnce();

        self::assertSame(ProcessOutcome::Waiting, $this->processor()->process($event));
        self::assertSame([], $event->getFramePaths());
        self::assertSame(1, $event->getAttempts());
    }

    public function testCountsAnUnexpectedErrorAsAFailedAttemptSoTheQueueMovesOn(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $event->recordFrames(['terrace/2026/11/06/1-0.jpg'], 640, 360);
        $this->describer->describe(Argument::cetera())->willThrow(new RuntimeException('Frame file is gone.'));
        $this->unitOfWork->flush()->shouldBeCalledOnce();

        self::assertSame(ProcessOutcome::Waiting, $this->processor()->process($event));
        self::assertSame(1, $event->getAttempts());
        self::assertEquals($this->clock->now()->modify('+600 seconds'), $event->getNextAttemptAt());
    }

    public function testFailsAfterTheLastAttempt(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        for ($attempt = 1; $attempt < SettingsFactory::create()->description->maxAttempts; ++$attempt) {
            $event->retryAt($this->clock->now());
        }
        $event->recordFrames(['terrace/2026/11/06/1-0.jpg'], 640, 360);
        $this->describer->describe(Argument::cetera())->willThrow(new AiRequestFailedException('503'));

        self::assertSame(ProcessOutcome::Failed, $this->processor()->process($event));
        self::assertSame(EventStatus::Failed, $event->getStatus());
    }

    public function testKeepsTheFramesWhenTheBudgetIsUsedUp(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $this->grabber->grab(Argument::cetera())->shouldBeCalledTimes(3);
        $this->budget->tryConsume(null)->willReturn(false);
        $this->describer->describe(Argument::cetera())->shouldNotBeCalled();
        $this->unitOfWork->flush()->shouldBeCalledOnce();
        $this->eventDispatcher->dispatch(Argument::any())->shouldNotBeCalled();

        self::assertSame(ProcessOutcome::OverBudget, $this->processor()->process($event));
        self::assertCount(3, $event->getFramePaths());
        self::assertSame(EventStatus::Pending, $event->getStatus());
    }

    public function testCountsTheCallInThePoolOfTheEventsDetectionTypes(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40', type: 'animal', id: 1);
        $event->recordFrames(['terrace/2026/11/06/1-0.jpg'], 640, 360);
        $this->budget->tryConsume(Argument::that(static fn (?CallPool $pool): bool => 'animals' === $pool?->name))->willReturn(false)->shouldBeCalledOnce();
        $this->budget->hasCallsLeftToday()->willReturn(true);
        $this->describer->describe(Argument::cetera())->shouldNotBeCalled();

        $processor = $this->processor(['ai' => ['call_pools' => [
            'people' => ['detection_types' => ['person', 'vehicle'], 'daily_call_limit' => 75],
            'animals' => ['detection_types' => ['animal'], 'daily_call_limit' => 75],
        ]]]);

        self::assertSame(ProcessOutcome::OverBudget, $processor->process($event));
        self::assertEquals(CameraEventFactory::utc('2026-11-06 23:00:00'), $event->getNextAttemptAt(), 'Waits for midnight in Vienna, so the other pools come first.');
        self::assertSame(0, $event->getAttempts());
    }

    public function testFilesARepeatedFalseAlarmWithoutTheAi(): void
    {
        $spiders = array_map(
            static fn (int $id): CameraEvent => CameraEventFactory::described('A spider web.', \sprintf('2026-11-06 06:%02d:00', $id), verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'spider_web', id: $id),
            [12, 8, 4],
        );
        $this->events->findBy(Argument::cetera())->willReturn($spiders);
        $this->storage->brightnessGrid(Argument::cetera())->willReturn(new BrightnessGrid(2, [100.0, 100.0, 100.0, 100.0]));
        $event = CameraEventFactory::withFrames('2026-11-06 06:16:40', id: 20);
        $this->budget->tryConsume(Argument::any())->shouldNotBeCalled();
        $this->describer->describe(Argument::cetera())->shouldNotBeCalled();
        $this->eventDispatcher->dispatch(new EventDescribed($event))->shouldBeCalledOnce()->willReturnArgument(0);

        self::assertSame(ProcessOutcome::Described, $this->processor()->process($event));
        self::assertSame(12, $event->getRepeatOf());
        self::assertSame('spider_web', $event->getEffectiveFalseAlarmCause());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function processor(array $settings = []): EventProcessor
    {
        $settingsProvider = $this->settingsProvider($settings);

        return new EventProcessor(
            new FrameSources([$this->grabber->reveal()]),
            $this->storage->reveal(),
            $this->describer->reveal(),
            $this->budget->reveal(),
            new HintCollector([$this->hints->reveal()]),
            new RepeatedFalseAlarms($this->events->reveal(), $this->storage->reveal(), new SceneChange(), $settingsProvider),
            $this->indexer->reveal(),
            $this->unitOfWork->reveal(),
            $this->eventDispatcher->reveal(),
            $settingsProvider,
            $this->clock,
            new HomeTimeZone('Europe/Vienna'),
            new NullLogger(),
        );
    }
}
