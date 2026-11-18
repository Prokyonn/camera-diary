<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Domain\Repository\CameraEventRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unusual events and the ones the AI was not sure of: one tap confirms an answer, the event page corrects it, and every correction
 * teaches the AI for that camera.
 */
final class ReviewController extends AbstractController
{
    private const int LIMIT = 100;

    public function __construct(
        private readonly CameraEventRepositoryInterface $events,
    ) {
    }

    #[Route('/check', name: 'review', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('review/index.html.twig', [
            'events' => $this->events->findBy(['needsReview' => true], ['startedAt' => 'desc'], self::LIMIT),
        ]);
    }
}
