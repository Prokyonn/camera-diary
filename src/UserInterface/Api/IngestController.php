<?php

declare(strict_types=1);

namespace App\UserInterface\Api;

use App\Application\Clock\HomeTimeZone;
use App\Application\Ingest\EventIngester;
use App\Application\Ingest\IngestRequest;
use App\Application\Ingest\InvalidIngestException;
use DateMalformedStringException;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The hook for any application that can send a video or images, e.g.
 * curl -H "Authorization: Bearer $INGEST_TOKEN" -F camera=garden -F detections[]=person -F video=@clip.mp4 .../api/events.
 */
final class IngestController extends AbstractController
{
    public function __construct(
        private readonly EventIngester $ingester,
        private readonly ClockInterface $clock,
        private readonly HomeTimeZone $homeTimeZone,
    ) {
    }

    /**
     * @param list<UploadedFile> $images
     */
    #[Route('/api/events', name: 'api_ingest', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload]
        IngestPayload $payload,
        #[MapUploadedFile([new Assert\File(maxSize: '200M')])]
        ?UploadedFile $video = null,
        #[MapUploadedFile([new Assert\File(maxSize: '20M')])]
        array $images = [],
    ): JsonResponse {
        try {
            $startedAt = $this->time($payload->startedAt);
            $endedAt = $this->time($payload->endedAt);
        } catch (DateMalformedStringException) {
            return $this->json(['error' => 'startedAt and endedAt must be ISO 8601 times.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->ingester->ingest(new IngestRequest(
                cameraSlug: $payload->camera,
                startedAt: $startedAt ?? $this->clock->now(),
                videoPath: $video?->getPathname(),
                imagePaths: array_map(static fn (UploadedFile $image): string => $image->getPathname(), $images),
                detectionTypes: $payload->detections,
                hints: $payload->hints,
                endedAt: $endedAt,
                source: $payload->source,
                externalId: $payload->externalId,
                cameraName: $payload->cameraName,
            ));
        } catch (InvalidIngestException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $event = $result->event;

        return $this->json([
            'id' => $event->getId(),
            'status' => $event->getStatus()->value,
            'created' => $result->created,
            'url' => $this->generateUrl('event_show', ['id' => $event->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], $result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Like the CLI and the MCP tools: a time without an offset is the home's local time.
     */
    private function time(?string $value): ?DateTimeImmutable
    {
        return null === $value || '' === $value ? null : new DateTimeImmutable($value, $this->homeTimeZone->zone());
    }
}
