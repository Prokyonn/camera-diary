<?php

declare(strict_types=1);

namespace App\UserInterface\Twig;

use App\Domain\Repository\CameraEventRepositoryInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class ReviewExtension
{
    public function __construct(
        private CameraEventRepositoryInterface $events,
    ) {
    }

    /**
     * Events the AI was not sure of and nobody has looked at yet, for the navigation.
     */
    #[AsTwigFunction('review_count')]
    public function reviewCount(): int
    {
        return $this->events->countBy(['needsReview' => true]);
    }
}
