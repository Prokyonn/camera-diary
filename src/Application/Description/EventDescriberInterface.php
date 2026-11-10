<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Ai\AiRequestFailedException;
use App\Domain\Model\CameraEvent;

interface EventDescriberInterface
{
    /**
     * Describes what the stored frames of $event show.
     *
     * @param list<string> $hints home signals around the event, e.g. "07:16:20 Luna came in through the cat flap"
     *
     * @throws AiRequestFailedException
     */
    public function describe(CameraEvent $event, array $hints): EventDescription;
}
