<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use Symfony\Component\Validator\Constraints as Assert;

final class LabelData
{
    public function __construct(
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        public ?Verdict $verdictOverride = null,
    ) {
    }

    public static function fromEvent(CameraEvent $event): self
    {
        return new self($event->getLabel(), $event->getVerdictOverride());
    }
}
