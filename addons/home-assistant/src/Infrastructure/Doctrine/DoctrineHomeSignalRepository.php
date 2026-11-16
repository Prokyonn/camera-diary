<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\Repository\FilterKeys;
use App\Infrastructure\Doctrine\Repository\OrderBy;
use CameraDiary\HomeAssistant\Domain\Model\HomeSignal;
use CameraDiary\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrineHomeSignalRepository implements HomeSignalRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['changedAtOrAfter', 'changedAtOrBefore'];

    /** @var EntityRepository<HomeSignal> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(HomeSignal::class);
    }

    public function add(HomeSignal $signal): void
    {
        $this->entityManager->persist($signal);
    }

    public function findBy(array $filters = [], array $sortBy = []): array
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);

        $queryBuilder = $this->entityRepository->createQueryBuilder('signal');
        if (isset($filters['changedAtOrAfter'])) {
            $queryBuilder->andWhere('signal.changedAt >= :changedAtOrAfter')->setParameter('changedAtOrAfter', $filters['changedAtOrAfter']);
        }

        if (isset($filters['changedAtOrBefore'])) {
            $queryBuilder->andWhere('signal.changedAt <= :changedAtOrBefore')->setParameter('changedAtOrBefore', $filters['changedAtOrBefore']);
        }

        OrderBy::apply($queryBuilder, 'signal', $sortBy);

        /** @var list<HomeSignal> */
        return $queryBuilder->getQuery()->getResult();
    }
}
