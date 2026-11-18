<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Description\HintCollector;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Question\Answer;
use App\Application\Question\AnswerWriterInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Infrastructure\Ai\Client\Prompt;
use App\Infrastructure\Ai\Client\PromptImage;
use App\Infrastructure\Ai\Client\StructuredClientInterface;
use App\Infrastructure\Ai\Output\AnswerOutput;

final readonly class AiAnswerWriter implements AnswerWriterInterface
{
    public function __construct(
        private StructuredClientInterface $client,
        private HintCollector $hints,
        private FrameStorageInterface $frameStorage,
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function answer(string $question, array $events, int $total, bool $broadened): Answer
    {
        $parts = [
            'Question: '.$question,
            match (true) {
                $broadened => \sprintf('No event matched the exact search (camera, side, subject or keywords), so here are %d of the %d events of the same time span; say that nothing matched exactly, then answer from these if they help.', \count($events), $total),
                \count($events) < $total => \sprintf('%d events match; here are the %d most relevant.', $total, \count($events)),
                default => \sprintf('These are all %d events that match.', $total),
            },
        ];
        $withFrames = $this->settings->settings()->questions->answerEvents;
        foreach ($events as $index => $event) {
            array_push($parts, ...$this->eventParts($event, $index < $withFrames));
        }

        $output = $this->client->request(new Prompt(<<<'PROMPT'
            You answer questions about camera events at home. Use only the events and frames given; the first events list their frames right after their text.
            Manual labels and corrections are reliable and override the description. Several events often show the same person or animal; count individuals, not events, when asked how many. Say clearly when the events do not answer the question.
            Answer in a few plain sentences without Markdown.
            PROMPT, $parts), AnswerOutput::class);

        return new Answer($output->text, $output->eventIds);
    }

    /**
     * @return list<string|PromptImage>
     */
    private function eventParts(CameraEvent $event, bool $withFrames): array
    {
        $lines = [
            \sprintf(
                'Event %d: camera %s, %s to %s, reported %s.',
                $event->getId(),
                $event->getCamera()->getName(),
                $this->homeTimeZone->toLocal($event->getStartedAt())->format('D j M Y H:i:s'),
                $this->homeTimeZone->toLocal($event->getEndedAt())->format('H:i:s'),
                implode(', ', $event->getDetectionTypes()),
            ),
        ];
        if (null !== $event->getLabel()) {
            $lines[] = 'Label: '.$event->getLabel();
        }
        $subject = $event->getSubject();
        if (null !== $subject) {
            $lines[] = 'Subject: '.$this->settings->settings()->subjectLabel($subject, $event->getEffectiveIndividual());
        }
        if (null !== $event->getSummary()) {
            $lines[] = \sprintf('Description: %s (verdict %s, side %s)', $event->getSummary(), $event->getEffectiveVerdict()?->value, $event->getSide()?->value);
        }
        foreach ($this->hints->hintsFor($event) as $hint) {
            $lines[] = 'Hint: '.$hint;
        }

        $parts = [implode("\n", $lines)];
        if (!$withFrames) {
            return $parts;
        }

        foreach (\array_slice($event->getFramePaths(), 0, $this->settings->settings()->questions->framesPerEvent) as $framePath) {
            $parts[] = new PromptImage($this->frameStorage->absolutePath($framePath));
        }

        return $parts;
    }
}
