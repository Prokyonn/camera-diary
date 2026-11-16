<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\CameraEvent;

/**
 * Dispatched once an event's AI description is stored and indexed, e.g. for an add-on to send a notification.
 */
final readonly class EventDescribed
{
    public function __construct(
        public CameraEvent $event,
    ) {
    }
}
