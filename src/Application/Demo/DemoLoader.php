<?php

declare(strict_types=1);

namespace App\Application\Demo;

use App\Application\Camera\CameraSettings;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\DescriptionReplay;
use App\Application\Description\EventDescription;
use App\Application\Digest\DayDigests;
use App\Application\Event\CameraEventLabeler;
use App\Application\Ingest\EventIngester;
use App\Application\Ingest\IngestRequest;
use App\Application\Ingest\IngestResult;
use App\Application\Ingest\InvalidIngestException;
use App\Application\Question\QuestionHistory;
use App\Application\Question\QuestionResult;
use App\Application\Settings\Individual;
use App\Domain\Model\AskedQuestion;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Loads the demo: the cameras and sample clips through the ingest, each at its local time of day, days_ago days back;
 * loading again is a no-op. With $recorded, the AI's answers recorded from a real run are stored instead of asking the
 * AI, so the demo works without an AI key.
 *
 * @phpstan-type Recorded array{model: string, verdict: string, false_alarm_cause: string, side: string, subject: string, individual: string, also_seen: list<string>, summary: string, unusual: bool, confidence: string}
 * @phpstan-type Correction array{verdict: ?string, individual: ?string, label: ?string, false_alarm_cause: ?string}
 * @phpstan-type DemoEvent array{camera: string, clip: string, time: string, days_ago: int, detections: list<string>, hints: list<string>, recorded?: Recorded, repeat_of?: string, correction?: Correction}
 * @phpstan-type Question array{question: string, answer: string, events: list<string>}
 * @phpstan-type Digest array{days_ago: int, text: string, model: string}
 * @phpstan-type Manifest array{cameras: array<string, array{name: string, scene: string}>, events: array<string, DemoEvent>, questions: list<Question>, digests: list<Digest>}
 */
final readonly class DemoLoader
{
    public const string SOURCE = 'demo';

    public function __construct(
        private CameraSettings $cameras,
        private EventIngester $ingester,
        private DescriptionReplay $replay,
        private CameraEventLabeler $labeler,
        private QuestionHistory $questions,
        private DayDigests $digests,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    /**
     * @param Manifest $demo
     * @param string   $directory the folder the clips are relative to
     *
     * @return list<LoadedDemoEvent>
     *
     * @throws InvalidDemoException
     */
    public function load(array $demo, string $directory, bool $recorded): array
    {
        foreach ($demo['cameras'] as $slug => $camera) {
            $this->cameras->update($slug, $camera['name'], $camera['scene']);
        }

        $loaded = [];
        $events = [];
        foreach ($demo['events'] as $key => $entry) {
            try {
                $result = $this->ingest($key, $entry, $directory);
            } catch (InvalidIngestException $exception) {
                throw new InvalidDemoException(\sprintf('Event "%s": %s', $key, $exception->getMessage()), 0, $exception);
            }

            $event = $result->event;
            $events[$key] = $event;
            if ($recorded && isset($entry['recorded'])) {
                $this->replay->replay($event, $this->description($entry['recorded']));
            }

            if ($recorded && isset($entry['repeat_of'])) {
                $checked = $events[$entry['repeat_of']] ?? throw new InvalidDemoException(\sprintf('Event "%s" repeats "%s", which is no earlier event.', $key, $entry['repeat_of']));
                $this->replay->replayRepeat($event, $checked);
            }

            if ($result->created && isset($entry['correction'])) {
                $correction = $entry['correction'];
                $this->labeler->relabel($event, $correction['label'], null === $correction['verdict'] ? null : Verdict::from($correction['verdict']), $correction['individual'], $correction['false_alarm_cause']);
            }

            $loaded[] = new LoadedDemoEvent($key, $entry['camera'], $result->created, $event->getStatus());
        }

        if ($recorded) {
            $this->recordQuestions($demo, $events);
            $this->recordDigests($demo);
        }

        return $loaded;
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
            EventDescription::NO_FALSE_ALARM === $recorded['false_alarm_cause'] ? null : $recorded['false_alarm_cause'],
            Side::from($recorded['side']),
            $recorded['subject'],
            Individual::UNKNOWN === $recorded['individual'] ? null : $recorded['individual'],
            $recorded['summary'],
            $recorded['unusual'],
            Confidence::from($recorded['confidence']),
            $recorded['model'].' (recorded)',
            $recorded['also_seen'],
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

    /**
     * Day summaries from a real run, so the timeline shows one without an AI.
     *
     * @param Manifest $demo
     */
    private function recordDigests(array $demo): void
    {
        foreach ($demo['digests'] as $digest) {
            $this->digests->record($this->occurrence('12:00', $digest['days_ago']), $digest['text'], $digest['model'].' (recorded)');
        }
    }

    private function occurrence(string $time, int $daysAgo): DateTimeImmutable
    {
        // Calendar days, so a day of the demo keeps its events whatever time it is loaded; only a time still ahead
        // today moves to yesterday.
        $now = $this->homeTimeZone->toLocal($this->clock->now());
        $at = $now->modify($time)->modify(\sprintf('-%d days', $daysAgo));

        return ($at > $now ? $at->modify('-1 day') : $at)->setTimezone(new DateTimeZone('UTC'));
    }
}
