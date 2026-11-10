<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Description\EventDescriberInterface;
use App\Application\Description\EventDescription;
use App\Application\Frame\FrameStorageInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use App\Infrastructure\Ai\Client\Prompt;
use App\Infrastructure\Ai\Client\PromptImage;
use App\Infrastructure\Ai\Client\StructuredClientInterface;
use App\Infrastructure\Ai\Output\EventDescriptionOutput;

final readonly class AiEventDescriber implements EventDescriberInterface
{
    public function __construct(
        private StructuredClientInterface $client,
        private FrameStorageInterface $frameStorage,
        private HomeTimeZone $homeTimeZone,
        private HomeContext $homeContext,
    ) {
    }

    public function describe(CameraEvent $event, array $hints): EventDescription
    {
        $images = array_map(
            fn (string $path): PromptImage => new PromptImage($this->frameStorage->absolutePath($path)),
            $event->getFramePaths(),
        );

        $output = $this->client->request(
            new Prompt($this->instructions($event), [$this->context($event, $hints), ...$images]),
            EventDescriptionOutput::class,
        );

        return new EventDescription(
            $output->verdict,
            $output->falseAlarmCause,
            $output->side,
            $output->subject,
            $output->summary,
            $this->client->modelName(),
        );
    }

    private function instructions(CameraEvent $event): string
    {
        $notes = $this->homeContext->notes();

        return trim(<<<PROMPT
            You review frames from a home security camera and classify one event.

            Camera: {$this->homeContext->describeCamera($event->getCamera())}

            The frames are in time order; the subject may only appear in a later frame.
            Common false alarms: a spider web or a single thread swinging in front of the lens (often lit by the infrared light at night), insects close to the lens, rain or snow, headlights or sun reflections, moving shadows, plants moving in the wind.
            Describe people neutrally and never guess their names. Hints from the home are given when available; they may be unrelated to what the frames show.
            {$notes}
            PROMPT);
    }

    /**
     * @param list<string> $hints
     */
    private function context(CameraEvent $event, array $hints): string
    {
        $types = $event->getDetectionTypes();
        $lines = [
            \sprintf(
                'The camera reported %s at %s (until %s).',
                [] === $types ? 'an event' : implode(' and ', array_map(static fn (DetectionType $type): string => $type->value, $types)),
                $this->homeTimeZone->toLocal($event->getStartedAt())->format('l, j F Y, H:i:s'),
                $this->homeTimeZone->toLocal($event->getEndedAt())->format('H:i:s'),
            ),
        ];

        if ([] !== $hints) {
            $lines[] = 'Hints from the home around that time:';
            foreach ($hints as $hint) {
                $lines[] = '- '.$hint;
            }
        }

        return implode("\n", $lines);
    }
}
