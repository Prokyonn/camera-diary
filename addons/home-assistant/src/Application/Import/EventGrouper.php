<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Import;

use App\Application\Camera\CameraRegistry;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraEventRepositoryInterface;
use CameraDiary\HomeAssistant\Application\Settings\CameraMapping;
use CameraDiary\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraDiary\HomeAssistant\Domain\Model\GroupingRule;
use CameraDiary\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use DateTimeImmutable;

/**
 * Assigns detections without an event to a new or an open event of their camera, and closes the events no
 * detection can join anymore.
 */
final readonly class EventGrouper
{
    public const string SOURCE = 'home-assistant';

    public function __construct(
        private DetectionRepositoryInterface $detections,
        private CameraEventRepositoryInterface $events,
        private CameraRegistry $cameras,
        private UnitOfWorkInterface $unitOfWork,
        private GroupingRule $rule = new GroupingRule(),
    ) {
    }

    /**
     * @return int the number of new events
     */
    public function groupUnassigned(HomeAssistantSettings $settings): int
    {
        /** @var array<string, CameraEvent> $latestNew newest unsaved event per camera */
        $latestNew = [];
        $created = 0;
        foreach ($this->detections->findBy(['unassigned' => true], ['detectedAt' => 'asc']) as $detection) {
            $slug = $detection->getCamera();
            $detectedAt = $detection->getDetectedAt();
            $event = $this->events->findOneBy(['cameraSlug' => $slug, 'source' => self::SOURCE, 'startedAtOrBefore' => $detectedAt], ['startedAt' => 'desc']);
            $new = $latestNew[$slug] ?? null;
            if ($new instanceof CameraEvent && (!$event instanceof CameraEvent || $new->getStartedAt() >= $event->getStartedAt())) {
                $event = $new;
            }

            if ($event instanceof CameraEvent && $event->canTakeDetections() && $this->rule->joins($event, $detectedAt)) {
                $event->addDetection($detection->getType(), $detectedAt);
            } else {
                $event = new CameraEvent($this->camera($settings, $slug), $detectedAt, [$detection->getType()], self::SOURCE, open: true);
                $this->events->add($event);
                $latestNew[$slug] = $event;
                ++$created;
            }

            $detection->assignTo($event);
        }

        $this->unitOfWork->flush();

        return $created;
    }

    /**
     * @return int the number of closed events
     */
    public function closeSettled(DateTimeImmutable $importedUntil): int
    {
        $settled = $this->events->findBy([
            'source' => self::SOURCE,
            'open' => true,
            'endedBefore' => $this->rule->closedBefore($importedUntil),
        ]);
        foreach ($settled as $event) {
            $event->close();
        }

        $this->unitOfWork->flush();

        return \count($settled);
    }

    private function camera(HomeAssistantSettings $settings, string $slug): Camera
    {
        $mapping = $settings->camera($slug);
        $camera = $this->cameras->getOrCreate($slug, $mapping?->name);
        if ($mapping instanceof CameraMapping && null !== $mapping->scene && null === $camera->getScene()) {
            $camera->describeScene($mapping->scene);
        }

        return $camera;
    }
}
