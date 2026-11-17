<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Doctrine;

use App\Domain\Exception\CameraEventNotFoundException;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Infrastructure\Doctrine\Repository\DoctrineCameraEventRepository;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineCameraEventRepository::class)]
final class DoctrineCameraEventRepositoryTest extends KernelTestCase
{
    use ResetsStorage;

    private CameraEventRepositoryInterface $repository;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->repository = self::getContainer()->get(CameraEventRepositoryInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFiltersByCameraTimeAndDetectionType(): void
    {
        $this->store(
            CameraEventFactory::create('2026-11-07 13:55:00', 'garden', 'person'),
            CameraEventFactory::create('2026-11-07 13:58:00', 'garden', 'animal'),
            CameraEventFactory::create('2026-11-07 13:56:00', 'terrace', 'person'),
            CameraEventFactory::create('2026-11-08 08:00:00', 'garden', 'person'),
        );

        $events = $this->repository->findBy([
            'cameraSlug' => 'garden',
            'detectionType' => 'person',
            'startedAtOrAfter' => CameraEventFactory::utc('2026-11-07 00:00:00'),
            'startedBefore' => CameraEventFactory::utc('2026-11-08 00:00:00'),
        ], ['startedAt' => 'desc']);

        self::assertCount(1, $events);
        self::assertEquals(CameraEventFactory::utc('2026-11-07 13:55:00'), $events[0]->getStartedAt());
    }

    public function testFalseAlarmFilterUsesTheEffectiveVerdict(): void
    {
        $web = CameraEventFactory::described('A web thread.', '2026-11-06 17:36:00', verdict: Verdict::FalseAlarm);
        $corrected = CameraEventFactory::described('Nothing visible.', '2026-11-06 17:53:53', verdict: Verdict::FalseAlarm);
        $corrected->relabel('Luna right in front of the lens', Verdict::Real, null);
        $pending = CameraEventFactory::create('2026-11-06 18:00:00');
        $real = CameraEventFactory::described('A grey cat.', '2026-11-06 18:05:00');
        $this->store($web, $corrected, $pending, $real);

        self::assertSame([$web], $this->repository->findBy(['falseAlarm' => true]));
        self::assertSame([$corrected, $pending, $real], $this->repository->findBy(['falseAlarm' => false], ['startedAt' => 'asc']));
    }

    public function testFindsPendingEventsThatAreDue(): void
    {
        $due = CameraEventFactory::create('2026-11-07 13:55:00');
        $later = CameraEventFactory::create('2026-11-07 13:50:00');
        $later->retryAt(CameraEventFactory::utc('2026-11-07 14:30:00'));
        $open = CameraEventFactory::create('2026-11-07 14:09:00', open: true);
        $this->store($due, $later, $open);

        $ready = $this->repository->findBy([
            'status' => EventStatus::Pending,
            'open' => false,
            'dueAt' => CameraEventFactory::utc('2026-11-07 14:10:00'),
        ], ['startedAt' => 'desc'], 20);

        self::assertSame([$due], $ready);
    }

    public function testFindsAnEventBySourceAndExternalId(): void
    {
        $sent = CameraEventFactory::create('2026-11-07 13:55:00', source: 'frigate', externalId: '1699365300.1-abc');
        $this->store($sent, CameraEventFactory::create('2026-11-07 13:56:00', externalId: '1699365300.1-abc'));

        self::assertSame($sent, $this->repository->findOneBy(['source' => 'frigate', 'externalId' => '1699365300.1-abc']));
    }

    public function testGetOneByThrowsWhenNothingMatches(): void
    {
        $this->expectException(CameraEventNotFoundException::class);

        $this->repository->getOneBy(['id' => 404]);
    }

    public function testCountsEvents(): void
    {
        $this->store(CameraEventFactory::create('2026-11-07 13:55:00'), CameraEventFactory::create('2026-11-07 13:59:00', 'garden'));

        self::assertSame(2, $this->repository->countBy());
        self::assertSame(1, $this->repository->countBy(['cameraSlug' => 'garden']));
    }

    public function testRejectsUnknownFilterKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->findBy(['colour' => 'grey']);
    }

    private function store(CameraEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->entityManager->persist($event->getCamera());
            $this->entityManager->persist($event);
        }
        $this->entityManager->flush();
    }
}
