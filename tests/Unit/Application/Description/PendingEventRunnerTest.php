<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\EventDescriberInterface;
use App\Application\Description\EventDescription;
use App\Application\Description\EventProcessor;
use App\Application\Description\HintCollector;
use App\Application\Description\PendingEventRunner;
use App\Application\Description\ProcessOutcome;
use App\Application\Description\RepeatedFalseAlarms;
use App\Application\Description\SceneChange;
use App\Application\Frame\FrameSources;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\CallPool;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PendingEventRunner::class)]
final class PendingEventRunnerTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    /** @var ObjectProphecy<AiCallBudgetInterface> */
    private ObjectProphecy $budget;

    /** @var ObjectProphecy<EventDescriberInterface> */
    private ObjectProphecy $describer;

    protected function setUp(): void
    {
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->budget = $this->prophesize(AiCallBudgetInterface::class);
        $this->describer = $this->prophesize(EventDescriberInterface::class);
        $this->describer->describe(Argument::cetera())->willReturn(
            new EventDescription(Verdict::Real, null, Side::OurProperty, 'person', null, 'The postman at the door.', false, Confidence::High, 'test-model'),
        );
    }

    public function testGoesOnWithOtherEventsWhenOnlyAPoolLimitIsReached(): void
    {
        $this->due(
            $this->event(1, '06:20:00', 'animal'),
            $this->event(2, '06:19:00', 'person'),
            $this->event(3, '06:18:00', 'animal'),
        );
        $this->budget->tryConsume(Argument::type(CallPool::class))->willReturn(false)->shouldBeCalledTimes(2);
        $this->budget->tryConsume(null)->willReturn(true)->shouldBeCalledOnce();
        $this->budget->hasCallsLeftToday()->willReturn(true);

        self::assertSame(['over_budget' => 2, 'described' => 1], $this->runner()->run());
    }

    public function testStopsWhenTheDailyLimitIsReached(): void
    {
        $this->due(
            $this->event(1, '06:20:00', 'person'),
            $this->event(2, '06:19:00', 'animal'),
        );
        $this->budget->tryConsume(null)->willReturn(false)->shouldBeCalledOnce();
        $this->budget->tryConsume(Argument::type(CallPool::class))->shouldNotBeCalled();
        $this->budget->hasCallsLeftToday()->willReturn(false);

        self::assertSame(['over_budget' => 1], $this->runner()->run());
    }

    public function testTakesTheBatchSizeFromTheSettings(): void
    {
        $this->events->findBy(Argument::any(), ['startedAt' => 'desc'], 5)->willReturn([])->shouldBeCalledOnce();

        self::assertSame([], $this->runner(['description' => ['batch_size' => 5]])->run());
    }

    public function testDescribesOneEventUnderTheWorkersLock(): void
    {
        $this->budget->tryConsume(null)->willReturn(true);
        $event = $this->event(1, '06:20:00', 'person');
        $locks = new LockFactory(new InMemoryStore());

        self::assertSame(ProcessOutcome::Described, $this->runner(locks: $locks)->describe($event));
        self::assertTrue($locks->createLock(PendingEventRunner::LOCK_KEY)->acquire(), 'The lock is released afterwards.');
    }

    public function testDoesNotDescribeAnEventWhileAnotherRunHoldsTheLock(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $workerLock = $locks->createLock(PendingEventRunner::LOCK_KEY);
        $workerLock->acquire();
        $this->describer->describe(Argument::cetera())->shouldNotBeCalled();

        self::assertNull($this->runner(locks: $locks)->describe($this->event(1, '06:20:00', 'person')));
        self::assertNull($this->runner(locks: $locks)->run());
    }

    private function event(int $id, string $time, string $type): CameraEvent
    {
        $event = CameraEventFactory::create('2026-11-06 '.$time, type: $type, id: $id);
        $event->recordFrames([\sprintf('terrace/2026/11/06/%d-0.jpg', $id)], 640, 360);

        return $event;
    }

    private function due(CameraEvent ...$events): void
    {
        $this->events->findBy(Argument::cetera())->willReturn(array_values($events));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function runner(array $settings = ['ai' => ['call_pools' => ['animals' => ['detection_types' => ['animal'], 'daily_call_limit' => 75]]]], ?LockFactory $locks = null): PendingEventRunner
    {
        $settingsProvider = $this->settingsProvider($settings);
        $clock = new MockClock('2026-11-06 06:30:00', 'UTC');
        $dispatcher = $this->prophesize(EventDispatcherInterface::class);
        $dispatcher->dispatch(Argument::any())->willReturnArgument(0);
        $storage = $this->prophesize(FrameStorageInterface::class)->reveal();
        $recent = $this->prophesize(CameraEventRepositoryInterface::class);
        $recent->findBy(Argument::cetera())->willReturn([]);
        $processor = new EventProcessor(
            new FrameSources([]),
            $storage,
            $this->describer->reveal(),
            $this->budget->reveal(),
            new HintCollector([]),
            new RepeatedFalseAlarms($recent->reveal(), $storage, new SceneChange(), $settingsProvider),
            $this->prophesize(EventIndexerInterface::class)->reveal(),
            $this->prophesize(UnitOfWorkInterface::class)->reveal(),
            $dispatcher->reveal(),
            $settingsProvider,
            $clock,
            new HomeTimeZone('Europe/Vienna'),
            new NullLogger(),
        );

        return new PendingEventRunner($processor, $this->events->reveal(), $locks ?? new LockFactory(new InMemoryStore()), $this->budget->reveal(), $settingsProvider, $clock);
    }
}
