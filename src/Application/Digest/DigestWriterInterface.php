<?php

declare(strict_types=1);

namespace App\Application\Digest;

use App\Application\Ai\AiRequestFailedException;
use App\Domain\Model\CameraEvent;
use DateTimeImmutable;

interface DigestWriterInterface
{
    /**
     * A few sentences on what happened on the local day.
     *
     * @param list<CameraEvent> $events all events of the day, oldest first, false alarms included
     *
     * @throws AiRequestFailedException
     */
    public function summarize(DateTimeImmutable $day, array $events): string;

    public function modelName(): string;
}
