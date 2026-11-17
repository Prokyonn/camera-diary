<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Individual\ReferencePhotos;
use App\Application\Individual\ReferencePhotoStoreInterface;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IndividualController extends AbstractController
{
    public function __construct(
        private readonly SettingsProviderInterface $settings,
        private readonly ReferencePhotos $referencePhotos,
        private readonly ReferencePhotoStoreInterface $store,
    ) {
    }

    #[Route('/individuals', name: 'individuals', methods: ['GET'])]
    public function index(): Response
    {
        $individuals = array_map(
            fn (Individual $individual): array => ['individual' => $individual, 'photos' => $this->referencePhotos->of($individual)],
            $this->settings->settings()->individuals,
        );

        return $this->render('individual/index.html.twig', ['individuals' => $individuals]);
    }

    #[Route('/individuals/{name}/photos/{index}.jpg', name: 'individual_photo', requirements: ['name' => '[a-z][a-z0-9_]{0,31}', 'index' => '\d+'], methods: ['GET'])]
    public function photo(string $name, int $index): BinaryFileResponse
    {
        $photo = $this->referencePhotos->of($this->individual($name))[$index] ?? null;
        if (null === $photo || !is_file($photo->path)) {
            throw $this->createNotFoundException('Photo not found.');
        }

        $size = getimagesize($photo->path);
        if (false === $size) {
            throw $this->createNotFoundException('Photo not found.');
        }

        $response = new BinaryFileResponse($photo->path, headers: ['Content-Type' => $size['mime']]);
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/individuals/{name}/photos/{id}/remove', name: 'individual_photo_remove', requirements: ['name' => '[a-z][a-z0-9_]{0,31}', 'id' => '\d{8}-\d{6}-[0-9a-f]{8}\.jpg'], methods: ['POST'])]
    public function remove(string $name, string $id, Request $request): RedirectResponse
    {
        $individual = $this->individual($name);
        if (!$this->isCsrfTokenValid('remove-reference', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->store->remove($individual->name, $id);
        $this->addFlash('success', \sprintf('Removed a reference photo of %s.', $individual->label));

        return $this->redirectToRoute('individuals', status: Response::HTTP_SEE_OTHER);
    }

    private function individual(string $name): Individual
    {
        return $this->settings->settings()->individual($name) ?? throw $this->createNotFoundException('Unknown individual.');
    }
}
