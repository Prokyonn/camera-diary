<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Exception\AskedQuestionNotFoundException;
use App\Domain\Model\AskedQuestion;
use App\Domain\Repository\AskedQuestionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @phpstan-import-type AskedQuestionFilters from AskedQuestionRepositoryInterface
 * @phpstan-import-type AskedQuestionSortBy from AskedQuestionRepositoryInterface
 */
final readonly class DoctrineAskedQuestionRepository implements AskedQuestionRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['id'];

    /** @var EntityRepository<AskedQuestion> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(AskedQuestion::class);
    }

    public function add(AskedQuestion $question): void
    {
        $this->entityManager->persist($question);
    }

    public function findBy(array $filters = [], array $sortBy = [], ?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder($filters, $sortBy);
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var list<AskedQuestion> */
        return $queryBuilder->getQuery()->getResult();
    }

    public function findOneBy(array $filters): ?AskedQuestion
    {
        /** @var AskedQuestion|null */
        return $this->createQueryBuilder($filters, [])->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function getOneBy(array $filters): AskedQuestion
    {
        return $this->findOneBy($filters) ?? throw new AskedQuestionNotFoundException($filters);
    }

    /**
     * @param AskedQuestionFilters $filters
     * @param AskedQuestionSortBy  $sortBy
     */
    private function createQueryBuilder(array $filters, array $sortBy): QueryBuilder
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);

        $queryBuilder = $this->entityRepository->createQueryBuilder('question');
        if (isset($filters['id'])) {
            $queryBuilder->andWhere('question.id = :id')->setParameter('id', $filters['id']);
        }

        OrderBy::apply($queryBuilder, 'question', $sortBy);

        return $queryBuilder;
    }
}
