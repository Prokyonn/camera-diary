<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Model\DayDigest;
use App\Domain\Repository\DayDigestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @phpstan-import-type DayDigestFilters from DayDigestRepositoryInterface
 */
final readonly class DoctrineDayDigestRepository implements DayDigestRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['day'];

    /** @var EntityRepository<DayDigest> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(DayDigest::class);
    }

    public function add(DayDigest $digest): void
    {
        $this->entityManager->persist($digest);
    }

    public function findOneBy(array $filters): ?DayDigest
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);
        $queryBuilder = $this->entityRepository->createQueryBuilder('digest');
        if (isset($filters['day'])) {
            $queryBuilder->andWhere('digest.day = :day')->setParameter('day', $filters['day']);
        }

        /** @var DayDigest|null */
        return $queryBuilder->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
