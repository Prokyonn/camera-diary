<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Camera\CameraSettings;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\DescriptionReplay;
use App\Application\Description\EventDescription;
use App\Application\Event\CameraEventLabeler;
use App\Application\Ingest\EventIngester;
use App\Application\Ingest\IngestRequest;
use App\Application\Ingest\IngestResult;
use App\Application\Ingest\InvalidIngestException;
use App\Application\Question\QuestionHistory;
use App\Application\Question\QuestionResult;
use App\Domain\Model\AskedQuestion;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Loads the sample clips of demo/demo.yaml through the ingest hook, each at its local time of day, days_ago days
 * back; loading again is a no-op. With --recorded, the AI's answers recorded from a real run are stored instead of
 * asking the AI, so the demo works without an AI key.
 *
 * @phpstan-import-type Manifest from DemoManifest
 * @phpstan-import-type DemoEvent from DemoManifest
 * @phpstan-import-type Recorded from DemoManifest
 */
#[AsCommand('app:demo', 'Load the demo cameras and clips; then run app:process (or the worker) to describe them')]
final readonly class DemoCommand
{
    public const string SOURCE = 'demo';

    public function __construct(
        private CameraSettings $cameras,
        private EventIngester $ingester,
        private DescriptionReplay $replay,
        private CameraEventLabeler $labeler,
        private QuestionHistory $questions,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        #[Autowire('%kernel.project_dir%/demo/demo.yaml')]
        private string $defaultManifest,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Demo manifest')]
        string $manifest = '',
        #[Option('Store the AI answers recorded in the manifest instead of asking the AI')]
        bool $recorded = false,
    ): int {
        $path = '' === $manifest ? $this->defaultManifest : $manifest;

        try {
            $demo = DemoManifest::read($path);
        } catch (ParseException|ConfigException $exception) {
            $io->error(\sprintf('Invalid demo manifest "%s": %s', $path, $exception->getMessage()));

            return Command::INVALID;
        }

        foreach ($demo['cameras'] as $slug => $camera) {
            $this->cameras->update($slug, $camera['name'], $camera['scene']);
        }

        $rows = [];
        $events = [];
        foreach ($demo['events'] as $key => $entry) {
            try {
                $result = $this->ingest($key, $entry, \dirname($path));
            } catch (InvalidIngestException $exception) {
                $io->error(\sprintf('Event "%s": %s', $key, $exception->getMessage()));

                return Command::FAILURE;
            }

            $event = $result->event;
            $events[$key] = $event;
            if ($recorded && null !== $entry['recorded']) {
                $this->replay->replay($event, $this->description($entry['recorded']));
            }

            if ($result->created && null !== $entry['correction']) {
                $correction = $entry['correction'];
                $this->labeler->relabel($event, $correction['label'], null === $correction['verdict'] ? null : Verdict::from($correction['verdict']), $correction['individual']);
            }

            $rows[] = [$key, $entry['camera'], $result->created ? 'stored' : 'already stored', $event->getStatus()->value];
        }

        if ($recorded) {
            $this->recordQuestions($demo, $events);
        }

        $io->table(['Event', 'Camera', 'Result', 'Status'], $rows);
        $io->success($recorded
            ? 'Demo loaded with the recorded AI answers.'
            : 'Demo loaded. Run "bin/console app:process" (or the worker) to describe the events.');

        return Command::SUCCESS;
    }

    /**
     * @param DemoEvent $entry
     *
     * @throws InvalidIngestException
     */
    private function ingest(string $key, array $entry, string $directory): IngestResult
    {
        $clip = $directory.'/'.$entry['clip'];
        $isVideo = !\in_array(strtolower(pathinfo($clip, \PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);

        return $this->ingester->ingest(new IngestRequest(
            cameraSlug: $entry['camera'],
            startedAt: $this->occurrence($entry['time'], $entry['days_ago']),
            videoPath: $isVideo ? $clip : null,
            imagePaths: $isVideo ? [] : [$clip],
            detectionTypes: $entry['detections'],
            hints: $entry['hints'],
            source: self::SOURCE,
            externalId: $key,
        ));
    }

    /**
     * @param Recorded $recorded
     */
    private function description(array $recorded): EventDescription
    {
        return new EventDescription(
            Verdict::from($recorded['verdict']),
            'none' === $recorded['false_alarm_cause'] ? null : $recorded['false_alarm_cause'],
            Side::from($recorded['side']),
            $recorded['subject'],
            'unknown' === $recorded['individual'] ? null : $recorded['individual'],
            $recorded['summary'],
            $recorded['unusual'],
            Confidence::from($recorded['confidence']),
            $recorded['model'].' (recorded)',
        );
    }

    /**
     * Earlier questions with the answers from a real run; stored once.
     *
     * @param Manifest                   $demo
     * @param array<string, CameraEvent> $events
     */
    private function recordQuestions(array $demo, array $events): void
    {
        $asked = array_map(static fn (AskedQuestion $question): string => $question->getQuestion(), $this->questions->recent(100));
        foreach ($demo['questions'] as $question) {
            if (\in_array($question['question'], $asked, true)) {
                continue;
            }

            $shown = array_values(array_filter(array_map(static fn (string $key): ?CameraEvent => $events[$key] ?? null, $question['events'])));
            $this->questions->record($question['question'], new QuestionResult($question['answer'], $shown));
        }
    }

    private function occurrence(string $time, int $daysAgo): DateTimeImmutable
    {
        $now = $this->homeTimeZone->toLocal($this->clock->now());
        $at = $now->modify($time);
        $at = $at > $now ? $at->modify('-1 day') : $at;

        return $at->modify(\sprintf('-%d days', $daysAgo))->setTimezone(new DateTimeZone('UTC'));
    }
}
