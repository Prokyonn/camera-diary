<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Description\HintCollector;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Question\Answer;
use App\Application\Question\AnswerWriterInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use App\Infrastructure\Ai\Client\Prompt;
use App\Infrastructure\Ai\Client\PromptImage;
use App\Infrastructure\Ai\Client\StructuredClientInterface;
use App\Infrastructure\Ai\Output\AnswerOutput;

final readonly class AiAnswerWriter implements AnswerWriterInterface
{
    private const int FRAMES_PER_EVENT = 2;

    public function __construct(
        private StructuredClientInterface $client,
        private HintCollector $hints,
        private FrameStorageInterface $frameStorage,
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    public function answer(string $question, array $events): Answer
    {
        $parts = ['Question: '.$question];
        foreach (\array_slice($events, 0, self::MAX_EVENTS) as $event) {
            array_push($parts, ...$this->eventParts($event));
        }

        $output = $this->client->request(new Prompt(<<<'PROMPT'
            You answer questions about camera events at home. Use only the events and frames given; each event lists its frames right after its text.
            Manual labels are reliable and override the description. Say clearly when the events do not answer the question.
            Answer in a few plain sentences without Markdown.
            PROMPT, $parts), AnswerOutput::class);

        return new Answer($output->text, $output->eventIds);
    }

    /**
     * @return list<string|PromptImage>
     */
    private function eventParts(CameraEvent $event): array
    {
        $lines = [
            \sprintf(
                'Event %d: camera %s, %s to %s, reported %s.',
                $event->getId(),
                $event->getCamera()->getName(),
                $this->homeTimeZone->toLocal($event->getStartedAt())->format('D j M Y H:i:s'),
                $this->homeTimeZone->toLocal($event->getEndedAt())->format('H:i:s'),
                implode(', ', array_map(static fn (DetectionType $type): string => $type->value, $event->getDetectionTypes())),
            ),
        ];
        if (null !== $event->getLabel()) {
            $lines[] = 'Label: '.$event->getLabel();
        }
        if (null !== $event->getSummary()) {
            $lines[] = \sprintf('Description: %s (verdict %s, side %s)', $event->getSummary(), $event->getEffectiveVerdict()?->value, $event->getSide()?->value);
        }
        foreach ($this->hints->hintsFor($event) as $hint) {
            $lines[] = 'Hint: '.$hint;
        }

        $parts = [implode("\n", $lines)];
        foreach (\array_slice($event->getFramePaths(), 0, self::FRAMES_PER_EVENT) as $framePath) {
            $parts[] = new PromptImage($this->frameStorage->absolutePath($framePath));
        }

        return $parts;
    }
}
