<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Domain\Model\CameraEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point: add-ons add context to an event, e.g. "07:16:20 Front door opened".
 */
#[AutoconfigureTag]
interface HintProviderInterface
{
    /**
     * @return list<string>
     */
    public function hintsFor(CameraEvent $event): array;
}
