<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\Repository\FilterKeys;
use CameraDiary\HomeAssistant\Domain\Model\ImportCursor;
use CameraDiary\HomeAssistant\Domain\Repository\ImportCursorRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrineImportCursorRepository implements ImportCursorRepositoryInterface
{
    public const array ALLOWED_FILTER_KEYS = ['name'];

    /** @var EntityRepository<ImportCursor> */
    private EntityRepository $entityRepository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->entityRepository = $entityManager->getRepository(ImportCursor::class);
    }

    public function add(ImportCursor $cursor): void
    {
        $this->entityManager->persist($cursor);
    }

    public function findOneBy(array $filters): ?ImportCursor
    {
        FilterKeys::assertAllowed($filters, self::ALLOWED_FILTER_KEYS);

        return $this->entityRepository->findOneBy($filters);
    }
}
