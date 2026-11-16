<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Push;

use App\Domain\Model\CameraEvent;
use CameraDiary\HomeAssistant\Domain\Model\PushHandover;
use CameraDiary\HomeAssistant\Domain\Repository\PushHandoverRepositoryInterface;

/**
 * The pushes Home Assistant sent for an event's camera while the event lasted, oldest first.
 */
final readonly class EventHandovers
{
    /** The push script starts within a second or two of the detection. */
    private const int MARGIN_SECONDS = 30;

    public function __construct(
        private PushHandoverRepositoryInterface $handovers,
    ) {
    }

    /**
     * @return list<PushHandover>
     */
    public function forEvent(CameraEvent $event): array
    {
        return $this->handovers->findBy([
            'camera' => $event->getCamera()->getSlug(),
            'pushedAtOrAfter' => $event->getStartedAt()->modify(\sprintf('-%d seconds', self::MARGIN_SECONDS)),
            'pushedAtOrBefore' => $event->getEndedAt()->modify(\sprintf('+%d seconds', self::MARGIN_SECONDS)),
        ], ['pushedAt' => 'asc']);
    }
}
