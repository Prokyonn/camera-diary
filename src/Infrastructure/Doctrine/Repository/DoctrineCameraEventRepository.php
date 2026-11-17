<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Exception\CameraEventNotFoundException;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @phpstan-import-type CameraEventFilters from CameraEventRepositoryInterface
 * @phpstan-import-type CameraEventSortBy from CameraEventRepositoryInterface
 */
final readonly class DoctrineCameraEventRepository implements CameraEventRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = [
        'id',
        'ids',
        'camera',
        'cameraSlug',
        'source',
        'externalId',
        'open',
        'detectionType',
        'status',
        'startedAtOrAfter',
        'startedBefore',
        'startedAtOrBefore',
        'endedBefore',
        'endedAtOrAfter',
        'dueAt',
        'falseAlarm',
        'framesRemoved',
    ];

    /** @var EntityRepository<CameraEvent> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(CameraEvent::class);
    }

    public function add(CameraEvent $event): void
    {
        $this->entityManager->persist($event);
    }

    public function remove(CameraEvent $event): void
    {
        $this->entityManager->remove($event);
    }

    public function findBy(array $filters = [], array $sortBy = [], ?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder($filters, $sortBy);
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var list<CameraEvent> */
        return $queryBuilder->getQuery()->getResult();
    }

    public function findOneBy(array $filters, array $sortBy = []): ?CameraEvent
    {
        /** @var CameraEvent|null */
        return $this->createQueryBuilder($filters, $sortBy)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function getOneBy(array $filters): CameraEvent
    {
        return $this->findOneBy($filters) ?? throw new CameraEventNotFoundException($filters);
    }

    public function countBy(array $filters = []): int
    {
        $count = $this->createQueryBuilder($filters, [])->select('COUNT(event.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param CameraEventFilters $filters
     * @param CameraEventSortBy  $sortBy
     */
    private function createQueryBuilder(array $filters, array $sortBy): QueryBuilder
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);
        $queryBuilder = $this->entityRepository->createQueryBuilder('event');

        $comparisons = [
            'id' => 'event.id = :id',
            'ids' => 'event.id IN (:ids)',
            'camera' => 'event.camera = :camera',
            'cameraSlug' => 'IDENTITY(event.camera) = :cameraSlug',
            'source' => 'event.source = :source',
            'externalId' => 'event.externalId = :externalId',
            'open' => 'event.open = :open',
            'status' => 'event.status = :status',
            'startedAtOrAfter' => 'event.startedAt >= :startedAtOrAfter',
            'startedBefore' => 'event.startedAt < :startedBefore',
            'startedAtOrBefore' => 'event.startedAt <= :startedAtOrBefore',
            'endedBefore' => 'event.endedAt < :endedBefore',
            'endedAtOrAfter' => 'event.endedAt >= :endedAtOrAfter',
            'dueAt' => '(event.nextAttemptAt IS NULL OR event.nextAttemptAt <= :dueAt)',
        ];
        foreach ($comparisons as $key => $condition) {
            if (\array_key_exists($key, $filters)) {
                $queryBuilder->andWhere($condition)->setParameter($key, $filters[$key]);
            }
        }

        if (isset($filters['detectionType'])) {
            $queryBuilder->andWhere('event.detectionTypes LIKE :detectionType')
                ->setParameter('detectionType', '%"'.$filters['detectionType'].'"%');
        }

        if (isset($filters['falseAlarm'])) {
            $this->filterFalseAlarm($queryBuilder, $filters['falseAlarm']);
        }

        if (isset($filters['framesRemoved'])) {
            $queryBuilder->andWhere($filters['framesRemoved'] ? 'event.framesRemovedAt IS NOT NULL' : 'event.framesRemovedAt IS NULL');
        }

        OrderBy::apply($queryBuilder, 'event', $sortBy);

        return $queryBuilder;
    }

    /**
     * Spelled out for both cases: with NULL columns, NOT (...) of the false-alarm condition would be NULL, not true.
     */
    private function filterFalseAlarm(QueryBuilder $queryBuilder, bool $falseAlarm): void
    {
        $condition = $falseAlarm
            ? 'event.verdictOverride = :falseAlarmVerdict OR (event.verdictOverride IS NULL AND event.verdict = :falseAlarmVerdict)'
            : '(event.verdictOverride IS NOT NULL AND event.verdictOverride <> :falseAlarmVerdict)'
                .' OR (event.verdictOverride IS NULL AND (event.verdict IS NULL OR event.verdict <> :falseAlarmVerdict))';

        $queryBuilder->andWhere($condition)->setParameter('falseAlarmVerdict', Verdict::FalseAlarm);
    }
}
