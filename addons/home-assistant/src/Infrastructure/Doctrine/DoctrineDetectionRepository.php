<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\Repository\FilterKeys;
use App\Infrastructure\Doctrine\Repository\OrderBy;
use CameraDiary\HomeAssistant\Domain\Model\Detection;
use CameraDiary\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @phpstan-import-type DetectionFilters from DetectionRepositoryInterface
 */
final readonly class DoctrineDetectionRepository implements DetectionRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['detectedAtOrAfter', 'detectedAtOrBefore', 'unassigned'];

    /** @var EntityRepository<Detection> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(Detection::class);
    }

    public function add(Detection $detection): void
    {
        $this->entityManager->persist($detection);
    }

    public function findBy(array $filters = [], array $sortBy = []): array
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);

        $queryBuilder = $this->entityRepository->createQueryBuilder('detection');
        if (isset($filters['detectedAtOrAfter'])) {
            $queryBuilder->andWhere('detection.detectedAt >= :detectedAtOrAfter')->setParameter('detectedAtOrAfter', $filters['detectedAtOrAfter']);
        }

        if (isset($filters['detectedAtOrBefore'])) {
            $queryBuilder->andWhere('detection.detectedAt <= :detectedAtOrBefore')->setParameter('detectedAtOrBefore', $filters['detectedAtOrBefore']);
        }

        if (isset($filters['unassigned'])) {
            $queryBuilder->andWhere($filters['unassigned'] ? 'detection.event IS NULL' : 'detection.event IS NOT NULL');
        }

        OrderBy::apply($queryBuilder, 'detection', $sortBy);

        /** @var list<Detection> */
        return $queryBuilder->getQuery()->getResult();
    }
}
