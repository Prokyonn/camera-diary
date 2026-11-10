<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Exception\CameraNotFoundException;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @phpstan-import-type CameraFilters from CameraRepositoryInterface
 * @phpstan-import-type CameraSortBy from CameraRepositoryInterface
 */
final readonly class DoctrineCameraRepository implements CameraRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['slug'];

    /** @var EntityRepository<Camera> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(Camera::class);
    }

    public function add(Camera $camera): void
    {
        $this->entityManager->persist($camera);
    }

    public function findBy(array $filters = [], array $sortBy = []): array
    {
        /** @var list<Camera> */
        return $this->createQueryBuilder($filters, $sortBy)->getQuery()->getResult();
    }

    public function findOneBy(array $filters): ?Camera
    {
        /** @var Camera|null */
        return $this->createQueryBuilder($filters, [])->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function getOneBy(array $filters): Camera
    {
        return $this->findOneBy($filters) ?? throw new CameraNotFoundException($filters);
    }

    /**
     * @param CameraFilters $filters
     * @param CameraSortBy  $sortBy
     */
    private function createQueryBuilder(array $filters, array $sortBy): QueryBuilder
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);

        $queryBuilder = $this->entityRepository->createQueryBuilder('camera');
        if (isset($filters['slug'])) {
            $queryBuilder->andWhere('camera.slug = :slug')->setParameter('slug', $filters['slug']);
        }

        OrderBy::apply($queryBuilder, 'camera', $sortBy);

        return $queryBuilder;
    }
}
