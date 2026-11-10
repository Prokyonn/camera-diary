<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ingest;

use App\Application\Camera\CameraRegistry;
use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameExtractorInterface;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Ingest\EventIngester;
use App\Application\Ingest\IngestRequest;
use App\Application\Ingest\InvalidIngestException;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionProperty;

#[CoversClass(EventIngester::class)]
final class EventIngesterTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    /** @var ObjectProphecy<CameraRepositoryInterface> */
    private ObjectProphecy $cameras;

    /** @var ObjectProphecy<FrameExtractorInterface> */
    private ObjectProphecy $extractor;

    /** @var ObjectProphecy<FrameStorageInterface> */
    private ObjectProphecy $storage;

    /** @var ObjectProphecy<EventIndexerInterface> */
    private ObjectProphecy $indexer;

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->events->findOneBy(Argument::cetera())->willReturn(null);
        $this->events->add(Argument::type(CameraEvent::class))->will(static function (array $arguments): void {
            $event = $arguments[0] ?? null;
            if (!$event instanceof CameraEvent) {
                throw new LogicException('add() expects an event.');
            }

            new ReflectionProperty(CameraEvent::class, 'id')->setValue($event, 42);
        });
        $this->cameras = $this->prophesize(CameraRepositoryInterface::class);
        $this->cameras->findOneBy(['slug' => 'garden'])->willReturn(CameraFactory::get('garden'));
        $this->extractor = $this->prophesize(FrameExtractorInterface::class);
        $this->storage = $this->prophesize(FrameStorageInterface::class);
        foreach ([0, 1, 2] as $index) {
            $relativePath = \sprintf('garden/2026/11/10/42-%d.jpg', $index);
            $this->storage->relativePathFor(Argument::type(CameraEvent::class), $index)->willReturn($relativePath);
            $this->storage->prepare($relativePath)->willReturn('/frames/'.$relativePath);
        }
        $this->storage->imageSize('garden/2026/11/10/42-0.jpg')->willReturn([1280, 720]);
        $this->indexer = $this->prophesize(EventIndexerInterface::class);
    }

    public function testCutsThreeFramesSpreadOverTheVideo(): void
    {
        $this->extractor->duration('/tmp/clip.mp4')->willReturn(8.0);
        foreach ([2.0, 4.0, 6.0] as $index => $at) {
            $this->extractor->extract('/tmp/clip.mp4', $at, \sprintf('/frames/garden/2026/11/10/42-%d.jpg', $index))->shouldBeCalledOnce();
        }

        $this->indexer->index(Argument::type(CameraEvent::class))->shouldBeCalledOnce();

        $result = $this->ingester()->ingest(new IngestRequest(
            'garden',
            CameraEventFactory::utc('2026-11-10 07:16:20'),
            videoPath: '/tmp/clip.mp4',
            detectionTypes: [DetectionType::Person],
            hints: ['Doorbell rang'],
            externalId: 'abc',
        ));

        self::assertTrue($result->created);
        $event = $result->event;
        self::assertSame(['garden/2026/11/10/42-0.jpg', 'garden/2026/11/10/42-1.jpg', 'garden/2026/11/10/42-2.jpg'], $event->getFramePaths());
        self::assertSame([1280, 720], [$event->getFrameWidth(), $event->getFrameHeight()]);
        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertFalse($event->isOpen(), 'The worker may describe it now.');
        self::assertSame(['Doorbell rang'], $event->getHints());
        self::assertSame('api', $event->getSource());
        self::assertSame('abc', $event->getExternalId());
    }

    public function testTakesImagesAsTheyAre(): void
    {
        $this->extractor->extract('/tmp/a.png', null, '/frames/garden/2026/11/10/42-0.jpg')->shouldBeCalledOnce();
        $this->extractor->extract('/tmp/b.jpg', null, '/frames/garden/2026/11/10/42-1.jpg')->shouldBeCalledOnce();

        $result = $this->ingester()->ingest(new IngestRequest('garden', CameraEventFactory::utc('2026-11-10 07:16:20'), imagePaths: ['/tmp/a.png', '/tmp/b.jpg']));

        self::assertCount(2, $result->event->getFramePaths());
    }

    public function testRegistersAnUnknownCamera(): void
    {
        $this->cameras->findOneBy(['slug' => 'porch'])->willReturn(null);
        $this->cameras->add(Argument::that(static fn (Camera $camera): bool => 'porch' === $camera->getSlug() && 'Front porch' === $camera->getName()))->shouldBeCalledOnce();
        $this->storage->imageSize(Argument::type('string'))->willReturn([640, 480]);

        $result = $this->ingester()->ingest(new IngestRequest('porch', CameraEventFactory::utc('2026-11-10 07:16:20'), imagePaths: ['/tmp/a.jpg'], cameraName: 'Front porch'));

        self::assertSame('porch', $result->event->getCamera()->getSlug());
    }

    public function testReturnsTheStoredEventForAKnownExternalId(): void
    {
        $stored = CameraEventFactory::create(camera: 'garden', id: 7, externalId: 'abc');
        $this->events->findOneBy(['source' => 'api', 'externalId' => 'abc'])->willReturn($stored);
        $this->extractor->extract(Argument::cetera())->shouldNotBeCalled();

        $result = $this->ingester()->ingest(new IngestRequest('garden', CameraEventFactory::utc('2026-11-10 07:16:20'), imagePaths: ['/tmp/a.jpg'], externalId: 'abc'));

        self::assertFalse($result->created);
        self::assertSame($stored, $result->event);
    }

    public function testRemovesTheEventWhenTheMediaCannotBeRead(): void
    {
        $this->extractor->extract(Argument::cetera())->willThrow(new FrameExtractionFailedException('Invalid data found when processing input'));
        $this->events->remove(Argument::type(CameraEvent::class))->shouldBeCalledOnce();
        $this->storage->remove(['garden/2026/11/10/42-0.jpg'])->shouldBeCalledOnce();
        $this->indexer->index(Argument::any())->shouldNotBeCalled();

        $this->expectException(InvalidIngestException::class);

        $this->ingester()->ingest(new IngestRequest('garden', CameraEventFactory::utc('2026-11-10 07:16:20'), imagePaths: ['/tmp/notes.txt']));
    }

    public function testRejectsAnUnreadableVideo(): void
    {
        $this->extractor->duration('/tmp/clip.mp4')->willReturn(null);
        $this->events->add(Argument::any())->shouldNotBeCalled();

        $this->expectException(InvalidIngestException::class);

        $this->ingester()->ingest(new IngestRequest('garden', CameraEventFactory::utc('2026-11-10 07:16:20'), videoPath: '/tmp/clip.mp4'));
    }

    /**
     * @return iterable<string, array{IngestRequest}>
     */
    public static function invalidRequests(): iterable
    {
        $at = CameraEventFactory::utc('2026-11-10 07:16:20');

        yield 'no media' => [new IngestRequest('garden', $at)];
        yield 'video and images' => [new IngestRequest('garden', $at, videoPath: '/tmp/clip.mp4', imagePaths: ['/tmp/a.jpg'])];
        yield 'four images' => [new IngestRequest('garden', $at, imagePaths: ['/tmp/a.jpg', '/tmp/b.jpg', '/tmp/c.jpg', '/tmp/d.jpg'])];
        yield 'invalid camera' => [new IngestRequest('Front Door', $at, imagePaths: ['/tmp/a.jpg'])];
        yield 'ends before it starts' => [new IngestRequest('garden', $at, imagePaths: ['/tmp/a.jpg'], endedAt: $at->modify('-1 second'))];
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequests(IngestRequest $request): void
    {
        $this->events->add(Argument::any())->shouldNotBeCalled();

        $this->expectException(InvalidIngestException::class);

        $this->ingester()->ingest($request);
    }

    private function ingester(): EventIngester
    {
        $unitOfWork = $this->prophesize(UnitOfWorkInterface::class)->reveal();

        return new EventIngester(
            new CameraRegistry($this->cameras->reveal(), $unitOfWork),
            $this->events->reveal(),
            $this->extractor->reveal(),
            $this->storage->reveal(),
            $this->indexer->reveal(),
            $unitOfWork,
        );
    }
}
