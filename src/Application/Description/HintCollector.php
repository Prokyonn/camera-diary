<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\CameraEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The hints sent with an event plus those of the add-ons.
 */
final readonly class HintCollector
{
    /**
     * @param iterable<HintProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(HintProviderInterface::class)]
        private iterable $providers,
    ) {
    }

    /**
     * @return list<string>
     */
    public function hintsFor(CameraEvent $event): array
    {
        $hints = $event->getHints();
        foreach ($this->providers as $provider) {
            array_push($hints, ...$provider->hintsFor($event));
        }

        return array_values(array_unique($hints));
    }
}
