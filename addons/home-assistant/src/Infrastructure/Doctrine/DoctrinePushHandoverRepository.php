<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\Repository\FilterKeys;
use App\Infrastructure\Doctrine\Repository\OrderBy;
use CameraEvents\HomeAssistant\Domain\Model\PushHandover;
use CameraEvents\HomeAssistant\Domain\Repository\PushHandoverRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrinePushHandoverRepository implements PushHandoverRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['camera', 'tag', 'pushedAtOrAfter', 'pushedAtOrBefore'];

    /** @var EntityRepository<PushHandover> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(PushHandover::class);
    }

    public function add(PushHandover $handover): void
    {
        $this->entityManager->persist($handover);
    }

    public function findBy(array $filters = [], array $sortBy = []): array
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);

        $queryBuilder = $this->entityRepository->createQueryBuilder('push');
        if (isset($filters['camera'])) {
            $queryBuilder->andWhere('push.camera = :camera')->setParameter('camera', $filters['camera']);
        }

        if (isset($filters['tag'])) {
            $queryBuilder->andWhere('push.tag = :tag')->setParameter('tag', $filters['tag']);
        }

        if (isset($filters['pushedAtOrAfter'])) {
            $queryBuilder->andWhere('push.pushedAt >= :pushedAtOrAfter')->setParameter('pushedAtOrAfter', $filters['pushedAtOrAfter']);
        }

        if (isset($filters['pushedAtOrBefore'])) {
            $queryBuilder->andWhere('push.pushedAt <= :pushedAtOrBefore')->setParameter('pushedAtOrBefore', $filters['pushedAtOrBefore']);
        }

        OrderBy::apply($queryBuilder, 'push', $sortBy);

        /** @var list<PushHandover> */
        return $queryBuilder->getQuery()->getResult();
    }
}
