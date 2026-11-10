<?php

declare(strict_types=1);

namespace App\Application\Frame;

use App\Domain\Model\CameraEvent;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point: an add-on that opens events without frames supplies them later, e.g. from continuous recordings.
 */
#[AutoconfigureTag]
interface FrameSourceInterface
{
    public function supports(CameraEvent $event): bool;

    /**
     * Writes the camera's frame at $at as a JPEG to $targetPath.
     *
     * @throws FootageNotFoundException       when no recording covers $at (yet)
     * @throws FrameExtractionFailedException when the recording exists but yields no frame, e.g. while it is still being written
     */
    public function grab(CameraEvent $event, DateTimeImmutable $at, string $targetPath): void;
}
