<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Activity\ActivityReport;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who came by and when, and which camera raises false alarms for what reason.
 */
final class InsightsController extends AbstractController
{
    private const array RANGES = [7, 30];

    public function __construct(
        private readonly ActivityReport $activityReport,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/insights', name: 'insights', methods: ['GET'])]
    public function __invoke(#[MapQueryParameter] int $days = 7): Response
    {
        $days = \in_array($days, self::RANGES, true) ? $days : self::RANGES[0];
        $now = $this->clock->now();

        return $this->render('insights/index.html.twig', [
            'activity' => $this->activityReport->between($now->modify(\sprintf('-%d days', $days)), $now),
            'days' => $days,
            'ranges' => self::RANGES,
        ]);
    }
}
