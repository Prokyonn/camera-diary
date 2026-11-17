<?php

declare(strict_types=1);

namespace App\UserInterface\Mcp;

use App\Application\Clock\HomeTimeZone;
use App\Application\Description\HintCollector;
use App\Domain\Model\CameraEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Plain data for one event, as MCP clients such as Home Assistant Assist receive it.
 */
final readonly class EventPresenter
{
    public function __construct(
        private HomeTimeZone $homeTimeZone,
        private HintCollector $hints,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CameraEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'camera' => $event->getCamera()->getSlug(),
            'camera_name' => $event->getCamera()->getName(),
            'started_at' => $this->homeTimeZone->toLocal($event->getStartedAt())->format(\DATE_ATOM),
            'ended_at' => $this->homeTimeZone->toLocal($event->getEndedAt())->format(\DATE_ATOM),
            'detected' => $event->getDetectionTypes(),
            'status' => $event->getStatus()->value,
            'verdict' => $event->getEffectiveVerdict()?->value,
            'side' => $event->getSide()?->value,
            'subject' => $event->getSubject(),
            'summary' => $event->getSummary(),
            'label' => $event->getLabel(),
            'hints' => $this->hints->hintsFor($event),
            'url' => $this->urlGenerator->generate('event_show', ['id' => $event->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
