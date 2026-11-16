<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\History;

use DateTimeImmutable;

/**
 * State history of Home Assistant.
 */
interface HomeHistoryInterface
{
    /**
     * State changes strictly after $from and up to $to, in time order; times in UTC, truncated to seconds.
     *
     * @param list<string> $entityIds
     *
     * @return list<StateChange>
     *
     * @throws HomeHistoryUnavailableException
     */
    public function fetch(array $entityIds, DateTimeImmutable $from, DateTimeImmutable $to, bool $withAttributes): array;
}
