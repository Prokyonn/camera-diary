<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\EventDescriberInterface;
use App\Application\Description\EventDescription;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\Term;
use App\Domain\Model\CameraEvent;
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
        private SettingsProviderInterface $settings,
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
            $this->falseAlarmCause($output),
            $output->side,
            $this->subject($output),
            $output->summary,
            $output->unusual,
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
            Common false alarms: {$this->commonFalseAlarms()}.
            Describe people neutrally and never guess their names. Hints from the home are given when available; they may be unrelated to what the frames show.
            Unusual events: {$this->homeContext->unusualRule()}
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
                [] === $types ? 'an event' : implode(' and ', $types),
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

    private function subject(EventDescriptionOutput $output): string
    {
        if (!$this->settings->settings()->subjects->has($output->subject)) {
            throw new AiRequestFailedException(\sprintf('The AI named the unknown subject "%s".', $output->subject));
        }

        return $output->subject;
    }

    private function falseAlarmCause(EventDescriptionOutput $output): ?string
    {
        if (EventDescriptionOutput::NO_FALSE_ALARM === $output->falseAlarmCause) {
            return null;
        }

        if (!$this->settings->settings()->falseAlarmCauses->has($output->falseAlarmCause)) {
            throw new AiRequestFailedException(\sprintf('The AI named the unknown false-alarm cause "%s".', $output->falseAlarmCause));
        }

        return $output->falseAlarmCause;
    }

    private function commonFalseAlarms(): string
    {
        $causes = array_map(
            static fn (Term $cause): string => $cause->description ?? strtolower($cause->label),
            $this->settings->settings()->falseAlarmCauses->terms,
        );

        return implode(', ', $causes);
    }
}
