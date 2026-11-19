<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Clock\HomeTimeZone;
use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\VocabularyKind;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;

/**
 * Known individuals other cameras saw shortly before or after an event: a cat on the garden camera seconds after Luna
 * left the terrace is very probably her. Only for the AI's prompt, not a hint of the event: a search for "Luna" should
 * not find the events she was merely near.
 */
final readonly class NearbySightings
{
    private const string MARGIN = '2 minutes';

    public function __construct(
        private CameraEventRepositoryInterface $events,
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @return list<string> e.g. "16:16:39 Luna on the Terrace camera, 15 seconds before"
     */
    public function forEvent(CameraEvent $event): array
    {
        $nearby = $this->events->findBy(
            [
                'status' => EventStatus::Described,
                'startedAtOrAfter' => $event->getStartedAt()->modify('-'.self::MARGIN),
                'startedAtOrBefore' => $event->getEndedAt()->modify('+'.self::MARGIN),
            ],
            ['startedAt' => 'asc'],
        );

        $individuals = $this->settings->settings()->vocabulary(VocabularyKind::Individuals);
        $sightings = [];
        foreach ($nearby as $other) {
            if ($other->getCamera()->getSlug() === $event->getCamera()->getSlug()) {
                continue;
            }

            foreach ($other->getIndividualsSeen() as $name) {
                $sightings[] = \sprintf(
                    '%s %s on the %s camera, %s',
                    $this->homeTimeZone->toLocal($other->getStartedAt())->format('H:i:s'),
                    $individuals->label($name),
                    $other->getCamera()->getName(),
                    $this->offset($other, $event),
                );
            }
        }

        return $sightings;
    }

    private function offset(CameraEvent $other, CameraEvent $event): string
    {
        $seconds = $other->getStartedAt()->getTimestamp() - $event->getStartedAt()->getTimestamp();

        return match (true) {
            0 === $seconds => 'at the same time',
            $seconds < 0 => \sprintf('%d seconds before', -$seconds),
            default => \sprintf('%d seconds after', $seconds),
        };
    }
}
