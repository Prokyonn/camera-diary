<?php

declare(strict_types=1);

namespace App\Infrastructure\Seal;

use App\Domain\Repository\CameraEventRepositoryInterface;
use CmsIg\Seal\Reindex\ReindexConfig;
use CmsIg\Seal\Reindex\ReindexProviderInterface;
use Generator;

/**
 * Feeds `cmsig:seal:reindex` from the database.
 */
final readonly class EventReindexProvider implements ReindexProviderInterface
{
    public function __construct(
        private CameraEventRepositoryInterface $events,
        private SealEventIndexer $indexer,
    ) {
    }

    public function total(): int
    {
        return $this->events->countBy();
    }

    public function provide(ReindexConfig $reindexConfig): Generator
    {
        foreach ($this->events->findBy([], ['id' => 'asc']) as $event) {
            yield $this->indexer->document($event);
        }
    }

    public static function getIndex(): string
    {
        return SealEventIndexer::INDEX;
    }
}
