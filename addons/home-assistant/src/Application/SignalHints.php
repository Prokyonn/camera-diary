<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application;

use App\Application\Clock\HomeTimeZone;
use App\Application\Description\HintProviderInterface;
use App\Domain\Model\CameraEvent;
use CameraEvents\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraEvents\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;

/**
 * Hints from the signals around an event, e.g. "07:16:20 Luna came in through the cat flap".
 */
final readonly class SignalHints implements HintProviderInterface
{
    public const int WINDOW_SECONDS = 180;

    public function __construct(
        private HomeSignalRepositoryInterface $signals,
        private SettingsProviderInterface $settings,
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    public function hintsFor(CameraEvent $event): array
    {
        $settings = $this->settings->settings();
        if ([] === $settings->signals) {
            return [];
        }

        $signals = $this->signals->findBy([
            'changedAtOrAfter' => $event->getStartedAt()->modify(\sprintf('-%d seconds', self::WINDOW_SECONDS)),
            'changedAtOrBefore' => $event->getEndedAt()->modify(\sprintf('+%d seconds', self::WINDOW_SECONDS)),
        ], ['changedAt' => 'asc']);

        $hints = [];
        foreach ($signals as $signal) {
            $text = $settings->signal($signal->getEntityId())?->describe($signal->getState());
            if (null !== $text) {
                $hints[] = $this->homeTimeZone->toLocal($signal->getChangedAt())->format('H:i:s').' '.$text;
            }
        }

        return $hints;
    }
}
