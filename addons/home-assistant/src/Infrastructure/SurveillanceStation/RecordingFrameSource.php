<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\SurveillanceStation;

use App\Application\Frame\FootageNotFoundException;
use App\Application\Frame\FrameExtractorInterface;
use App\Application\Frame\FrameSourceInterface;
use App\Domain\Model\CameraEvent;
use CameraDiary\HomeAssistant\Application\Import\EventGrouper;
use CameraDiary\HomeAssistant\Application\Settings\SettingsProviderInterface;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Frames for Home Assistant events, cut out of the camera's continuous recordings.
 */
final readonly class RecordingFrameSource implements FrameSourceInterface
{
    public function __construct(
        private SettingsProviderInterface $settings,
        private RecordingLocator $locator,
        private FrameExtractorInterface $extractor,
    ) {
    }

    public function supports(CameraEvent $event): bool
    {
        return EventGrouper::SOURCE === $event->getSource()
            && null !== $this->recordingFolder($event)
            && $this->locator->isConfigured();
    }

    public function grab(CameraEvent $event, DateTimeImmutable $at, string $targetPath): void
    {
        $folder = $this->recordingFolder($event) ?? throw new FootageNotFoundException(\sprintf('Camera "%s" has no recording folder.', $event->getCamera()->getSlug()));
        $recording = $this->locator->locate($folder, $at)
            ?? throw new FootageNotFoundException(\sprintf('No %s recording covers %s.', $event->getCamera()->getName(), $at->format(DateTimeInterface::ATOM)));

        $this->extractor->extract($recording->path, $recording->offsetSeconds($at), $targetPath);
    }

    private function recordingFolder(CameraEvent $event): ?string
    {
        return $this->settings->settings()->camera($event->getCamera()->getSlug())?->recordingFolder;
    }
}
