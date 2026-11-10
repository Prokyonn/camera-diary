<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Application\Persistence\UnitOfWorkInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUnitOfWork implements UnitOfWorkInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
