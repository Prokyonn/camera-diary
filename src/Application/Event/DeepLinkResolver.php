<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraEventRepositoryInterface;
use DateTimeImmutable;

/**
 * Finds the event a push notification points at: the camera's event around the moment, closest start first.
 */
final readonly class DeepLinkResolver
{
    public const int MARGIN_SECONDS = 120;

    public function __construct(
        private CameraEventRepositoryInterface $events,
    ) {
    }

    public function resolve(string $cameraSlug, DateTimeImmutable $at): ?CameraEvent
    {
        $margin = self::MARGIN_SECONDS;
        $candidates = $this->events->findBy([
            'cameraSlug' => $cameraSlug,
            'startedAtOrBefore' => $at->modify(\sprintf('+%d seconds', $margin)),
            'endedAtOrAfter' => $at->modify(\sprintf('-%d seconds', $margin)),
        ]);

        $closest = null;
        $closestDistance = \PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = abs($candidate->getStartedAt()->getTimestamp() - $at->getTimestamp());
            if ($distance < $closestDistance) {
                $closest = $candidate;
                $closestDistance = $distance;
            }
        }

        return $closest;
    }
}
