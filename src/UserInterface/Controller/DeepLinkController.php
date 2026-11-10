<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Event\DeepLinkResolver;
use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraRepositoryInterface;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Target of push notifications: /at?camera=<slug>&t=<epoch seconds>.
 */
final class DeepLinkController extends AbstractController
{
    public function __construct(
        private readonly DeepLinkResolver $resolver,
        private readonly CameraRepositoryInterface $cameras,
    ) {
    }

    #[Route('/at', name: 'deep_link', methods: ['GET'])]
    public function __invoke(#[MapQueryParameter] string $camera, #[MapQueryParameter] int $t): Response
    {
        $knownCamera = Camera::isValidSlug($camera) ? $this->cameras->findOneBy(['slug' => $camera]) : null;
        if ($t <= 0 || !$knownCamera instanceof Camera) {
            throw $this->createNotFoundException('Unknown camera or invalid time.');
        }

        $at = new DateTimeImmutable('@'.$t);
        $event = $this->resolver->resolve($camera, $at);
        if ($event instanceof CameraEvent) {
            return $this->redirectToRoute('event_show', ['id' => $event->getId()]);
        }

        $response = $this->render('deep_link/pending.html.twig', ['camera' => $knownCamera, 'at' => $at]);
        $response->headers->set('Refresh', '30');

        return $response;
    }
}
