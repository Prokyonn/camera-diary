<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Clock\HomeTimeZone;
use App\Application\Ingest\EventIngester;
use App\Application\Ingest\IngestRequest;
use App\Application\Ingest\InvalidIngestException;
use App\Domain\Model\DetectionType;
use DateTimeImmutable;
use Exception;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use ValueError;

#[AsCommand('app:ingest', 'Store an event from a video or up to three images, like POST /api/events')]
final readonly class IngestCommand
{
    public function __construct(
        private EventIngester $ingester,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<string> $files
     * @param list<string> $detection
     * @param list<string> $hint
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Camera slug, e.g. garden')]
        string $camera,
        #[Argument('One video or up to three images')]
        array $files,
        #[Option('Start of the event in APP_TIMEZONE, e.g. "2026-11-10 07:16:20" or "-5 minutes" (default: now)')]
        string $at = '',
        #[Option('What the camera detected: person, animal, vehicle or motion')]
        array $detection = [],
        #[Option('Context for the AI, e.g. "Doorbell rang"')]
        array $hint = [],
        #[Option('Your id for the event; sending it again returns the stored event')]
        ?string $externalId = null,
        #[Option('Name of the sending application')]
        string $source = 'cli',
    ): int {
        try {
            $startedAt = '' === $at ? $this->clock->now() : new DateTimeImmutable($at, $this->homeTimeZone->zone());
            $types = array_map(DetectionType::from(...), $detection);
        } catch (Exception|ValueError $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $video = null;
        $images = [];
        foreach ($files as $file) {
            $type = is_file($file) ? mime_content_type($file) : false;
            if (false === $type) {
                $io->error(\sprintf('"%s" is no readable file.', $file));

                return Command::INVALID;
            }

            if (str_starts_with($type, 'video/')) {
                $video = $file;
            } else {
                $images[] = $file;
            }
        }

        try {
            $result = $this->ingester->ingest(new IngestRequest(
                cameraSlug: $camera,
                startedAt: $startedAt,
                videoPath: $video,
                imagePaths: $images,
                detectionTypes: $types,
                hints: $hint,
                source: $source,
                externalId: $externalId,
            ));
        } catch (InvalidIngestException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->success(\sprintf(
            '%s event %d: %s',
            $result->created ? 'Stored' : 'Already stored',
            $result->event->getId(),
            $this->urlGenerator->generate('event_show', ['id' => $result->event->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ));

        return Command::SUCCESS;
    }
}
