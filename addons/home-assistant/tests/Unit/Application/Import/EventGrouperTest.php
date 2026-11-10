<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Unit\Application\Import;

use App\Application\Camera\CameraRegistry;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use CameraEvents\HomeAssistant\Application\Import\EventGrouper;
use CameraEvents\HomeAssistant\Domain\Model\Detection;
use CameraEvents\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use CameraEvents\HomeAssistant\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

#[CoversClass(EventGrouper::class)]
final class EventGrouperTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DetectionRepositoryInterface> */
    private ObjectProphecy $detections;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    /** @var ObjectProphecy<CameraRepositoryInterface> */
    private ObjectProphecy $cameras;

    /** @var ObjectProphecy<UnitOfWorkInterface> */
    private ObjectProphecy $unitOfWork;

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->detections = $this->prophesize(DetectionRepositoryInterface::class);
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->cameras = $this->prophesize(CameraRepositoryInterface::class);
        $this->unitOfWork = $this->prophesize(UnitOfWorkInterface::class);
        $this->events->add(Argument::type(CameraEvent::class));
        foreach (['garden', 'terrace'] as $slug) {
            $this->cameras->findOneBy(['slug' => $slug])->willReturn(CameraFactory::get($slug));
        }
    }

    public function testGroupsDetectionsLessThanTwoMinutesApartIntoOpenEvents(): void
    {
        $detections = [
            new Detection('garden', DetectionType::Person, CameraEventFactory::utc('2026-11-07 13:55:00')),
            new Detection('terrace', DetectionType::Person, CameraEventFactory::utc('2026-11-07 13:55:10')),
            new Detection('garden', DetectionType::Animal, CameraEventFactory::utc('2026-11-07 13:56:30')),
            new Detection('garden', DetectionType::Person, CameraEventFactory::utc('2026-11-07 14:05:00')),
        ];
        $this->detections->findBy(['unassigned' => true], ['detectedAt' => 'asc'])->willReturn($detections);
        $this->events->findOneBy(Argument::cetera())->willReturn(null);
        $this->events->add(Argument::type(CameraEvent::class))->shouldBeCalledTimes(3);
        $this->unitOfWork->flush()->shouldBeCalledOnce();

        self::assertSame(3, $this->grouper()->groupUnassigned(SettingsFactory::create()));

        $first = $detections[0]->getEvent();
        self::assertNotNull($first);
        self::assertSame($first, $detections[2]->getEvent());
        self::assertSame(2, $first->getDetectionCount());
        self::assertSame([DetectionType::Person, DetectionType::Animal], $first->getDetectionTypes());
        self::assertSame(EventGrouper::SOURCE, $first->getSource());
        self::assertTrue($first->isOpen());
        self::assertSame('terrace', $detections[1]->getEvent()?->getCamera()->getSlug());
        self::assertNotSame($first, $detections[3]->getEvent(), 'Eight minutes later starts a new event.');
    }

    public function testJoinsAnOpenEventFromAnEarlierImport(): void
    {
        $open = CameraEventFactory::create('2026-11-07 13:55:00', 'garden', DetectionType::Person, 7, EventGrouper::SOURCE, open: true);
        $detection = new Detection('garden', DetectionType::Person, CameraEventFactory::utc('2026-11-07 13:56:00'));
        $this->detections->findBy(Argument::cetera())->willReturn([$detection]);
        $this->events->findOneBy(['cameraSlug' => 'garden', 'source' => EventGrouper::SOURCE, 'startedAtOrBefore' => $detection->getDetectedAt()], ['startedAt' => 'desc'])->willReturn($open);

        self::assertSame(0, $this->grouper()->groupUnassigned(SettingsFactory::create()));
        self::assertSame($open, $detection->getEvent());
        self::assertSame(2, $open->getDetectionCount());
    }

    public function testStartsANewEventNextToAClosedEvent(): void
    {
        $closed = CameraEventFactory::create('2026-11-07 09:40:00', 'terrace', DetectionType::Person, 3, EventGrouper::SOURCE);
        $detection = new Detection('terrace', DetectionType::Person, CameraEventFactory::utc('2026-11-07 09:40:30'));
        $this->detections->findBy(Argument::cetera())->willReturn([$detection]);
        $this->events->findOneBy(Argument::cetera())->willReturn($closed);

        self::assertSame(1, $this->grouper()->groupUnassigned(SettingsFactory::create()));
        self::assertNotSame($closed, $detection->getEvent());
    }

    public function testClosesEventsNoDetectionCanJoinAnymore(): void
    {
        $settled = CameraEventFactory::create('2026-11-07 09:40:00', 'garden', DetectionType::Person, 3, EventGrouper::SOURCE, open: true);
        $importedUntil = CameraEventFactory::utc('2026-11-07 09:43:00');
        $this->events->findBy(['source' => EventGrouper::SOURCE, 'open' => true, 'endedBefore' => CameraEventFactory::utc('2026-11-07 09:41:00')])->willReturn([$settled]);
        $this->unitOfWork->flush()->shouldBeCalledOnce();

        self::assertSame(1, $this->grouper()->closeSettled($importedUntil));
        self::assertFalse($settled->isOpen());
    }

    private function grouper(): EventGrouper
    {
        return new EventGrouper(
            $this->detections->reveal(),
            $this->events->reveal(),
            new CameraRegistry($this->cameras->reveal(), $this->unitOfWork->reveal()),
            $this->unitOfWork->reveal(),
        );
    }
}
