<?php

declare(strict_types=1);

namespace App\Infrastructure\Seal;

use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraEventRepositoryInterface;
use CmsIg\Seal\Reindex\ReindexConfig;
use CmsIg\Seal\Reindex\ReindexProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Generator;

/**
 * Feeds `cmsig:seal:reindex` from the database, in batches, so a long-running install does not load every event
 * into memory at once.
 */
final readonly class EventReindexProvider implements ReindexProviderInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private SealEventIndexer $indexer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function total(): int
    {
        return $this->events->countBy();
    }

    public function provide(ReindexConfig $reindexConfig): Generator
    {
        $lastId = 0;
        do {
            $batch = $this->events->findBy(['idAfter' => $lastId], ['id' => 'asc'], self::BATCH_SIZE);
            foreach ($batch as $event) {
                yield $this->indexer->document($event);
            }

            $last = end($batch);
            $lastId = $last instanceof CameraEvent ? $last->getId() : $lastId;
            $this->entityManager->clear();
        } while (self::BATCH_SIZE === \count($batch));
    }

    public static function getIndex(): string
    {
        return SealEventIndexer::INDEX;
    }
}
