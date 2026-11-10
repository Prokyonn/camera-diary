<?php

declare(strict_types=1);

namespace App\Application\Frame;

use App\Domain\Model\CameraEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class FrameSources
{
    /**
     * @param iterable<FrameSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator(FrameSourceInterface::class)]
        private iterable $sources,
    ) {
    }

    public function forEvent(CameraEvent $event): ?FrameSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($event)) {
                return $source;
            }
        }

        return null;
    }
}
