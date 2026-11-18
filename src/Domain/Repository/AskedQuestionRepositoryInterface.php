<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\AskedQuestionNotFoundException;
use App\Domain\Model\AskedQuestion;

/**
 * @phpstan-type AskedQuestionFilters array{id?: int}
 * @phpstan-type AskedQuestionSortBy array{askedAt?: 'asc'|'desc', id?: 'asc'|'desc'}
 */
interface AskedQuestionRepositoryInterface
{
    public function add(AskedQuestion $question): void;

    /**
     * @param AskedQuestionFilters $filters
     * @param AskedQuestionSortBy  $sortBy
     *
     * @return list<AskedQuestion>
     */
    public function findBy(array $filters = [], array $sortBy = [], ?int $limit = null): array;

    /**
     * @param AskedQuestionFilters $filters
     */
    public function findOneBy(array $filters): ?AskedQuestion;

    /**
     * @param AskedQuestionFilters $filters
     *
     * @throws AskedQuestionNotFoundException
     */
    public function getOneBy(array $filters): AskedQuestion;
}
