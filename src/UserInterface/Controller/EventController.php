<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Description\HintCollector;
use App\Application\Event\CameraEventLabeler;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Individual\InvalidReferencePhotoException;
use App\Application\Individual\ReferencePhotos;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\UserInterface\Form\LabelData;
use App\UserInterface\Form\LabelType;
use App\UserInterface\Form\ReferenceData;
use App\UserInterface\Form\ReferenceType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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
        private readonly ReferencePhotos $referencePhotos,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    #[Route('/events/{id:event}', name: 'event_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(CameraEvent $event, Request $request): Response
    {
        $label = LabelData::fromEvent($event);
        $form = $this->createForm(LabelType::class, $label);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->labeler->relabel($event, $label->label, $label->verdictOverride, $label->individualOverride);
            $this->addFlash('success', 'Saved.');

            return $this->redirectToRoute('event_show', ['id' => $event->getId()], Response::HTTP_SEE_OTHER);
        }

        $referenceForm = $this->referenceForm($event);
        $referenceForm?->handleRequest($request);
        if ($referenceForm instanceof FormInterface && $referenceForm->isSubmitted() && $referenceForm->isValid() && $this->addReference($event, $referenceForm)) {
            return $this->redirectToRoute('event_show', ['id' => $event->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('event/show.html.twig', [
            'event' => $event,
            'hints' => $this->hints->hintsFor($event),
            'form' => $form,
            'reference_form' => $referenceForm,
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

    /**
     * @return ?FormInterface<ReferenceData> null when there are no known individuals or no frames
     */
    private function referenceForm(CameraEvent $event): ?FormInterface
    {
        if ([] === $this->settings->settings()->individuals || [] === $event->getFramePaths()) {
            return null;
        }

        return $this->createForm(ReferenceType::class, new ReferenceData(), ['frame_count' => \count($event->getFramePaths())]);
    }

    /**
     * Stores the frame, or the dragged box of it, as a reference photo, and sets the event to that individual.
     *
     * @param FormInterface<ReferenceData> $form
     */
    private function addReference(CameraEvent $event, FormInterface $form): bool
    {
        $reference = $form->getData();
        $individual = $this->settings->settings()->individual($reference->individual ?? '');
        if (!$individual instanceof Individual) {
            throw $this->createNotFoundException('Unknown individual.');
        }

        try {
            $this->referencePhotos->addFromFrame($event, (int) $reference->frame, $individual, $reference->cropBox());
        } catch (InvalidReferencePhotoException $exception) {
            $form->addError(new FormError($exception->getMessage()));

            return false;
        }

        $this->labeler->relabel($event, $event->getLabel(), $event->getVerdictOverride(), $individual->name);
        $this->addFlash('success', \sprintf('Saved as a reference photo of %s.', $individual->label));

        return true;
    }
}
