<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Description\HintCollector;
use App\Application\Event\CameraEventLabeler;
use App\Application\Frame\FrameStorageInterface;
use App\Domain\Model\CameraEvent;
use App\UserInterface\Form\LabelData;
use App\UserInterface\Form\LabelType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventController extends AbstractController
{
    public function __construct(
        private readonly HintCollector $hints,
        private readonly FrameStorageInterface $frameStorage,
        private readonly CameraEventLabeler $labeler,
    ) {
    }

    #[Route('/events/{id:event}', name: 'event_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(CameraEvent $event, Request $request): Response
    {
        $label = LabelData::fromEvent($event);
        $form = $this->createForm(LabelType::class, $label);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->labeler->relabel($event, $label->label, $label->verdictOverride);
            $this->addFlash('success', 'Saved.');

            return $this->redirectToRoute('event_show', ['id' => $event->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('event/show.html.twig', [
            'event' => $event,
            'hints' => $this->hints->hintsFor($event),
            'form' => $form,
        ]);
    }

    #[Route('/events/{id:event}/frames/{index}.jpg', name: 'event_frame', requirements: ['id' => '\d+', 'index' => '\d'], methods: ['GET'])]
    public function frame(CameraEvent $event, int $index): BinaryFileResponse
    {
        $relativePath = $event->getFramePaths()[$index] ?? null;
        $path = null !== $relativePath ? $this->frameStorage->absolutePath($relativePath) : null;
        if (null === $path || !is_file($path)) {
            throw $this->createNotFoundException('Frame not found.');
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/jpeg']);
        $response->setPrivate();
        $response->setMaxAge(86400 * 30);
        $response->setImmutable();

        return $response;
    }
}
