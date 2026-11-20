<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\EventDescriberInterface;
use App\Application\Description\EventDescription;
use App\Application\Description\NearbySightings;
use App\Application\Description\PastCorrections;
use App\Application\Description\SceneBaseline;
use App\Application\Description\SceneBaselines;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Individual\IndividualReferences;
use App\Application\Individual\ReferencePhotos;
use App\Application\Settings\Individual;
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
        private ReferencePhotos $referencePhotos,
        private PastCorrections $pastCorrections,
        private SceneBaselines $sceneBaselines,
        private CorrectionNotes $correctionNotes,
        private NearbySightings $nearbySightings,
    ) {
    }

    public function describe(CameraEvent $event, array $hints): EventDescription
    {
        $images = array_map(
            fn (string $path): PromptImage => new PromptImage($this->frameStorage->absolutePath($path)),
            $event->getFramePaths(),
        );

        $references = $this->referencePhotos->forEvent($event);
        $baseline = $this->sceneBaselines->forEvent($event);
        $corrections = $this->correctionNotes->notes($this->pastCorrections->forEvent($event));
        $output = $this->client->request(
            new Prompt($this->instructions($event, $references, $baseline), [
                ...$this->references($references),
                ...$this->baseline($baseline),
                ...('' === $corrections ? [] : [$corrections]),
                $this->context($event, $hints, $this->nearbySightings->forEvent($event)),
                ...$images,
            ]),
            EventDescriptionOutput::class,
        );

        return new EventDescription(
            $output->verdict,
            $this->falseAlarmCause($output),
            $output->side,
            $this->subject($output),
            $this->individual($output),
            $output->summary,
            $output->unusual,
            $output->confidence,
            $this->client->modelName(),
            $this->alsoSeen($output),
        );
    }

    /**
     * @param list<IndividualReferences> $references
     */
    private function instructions(CameraEvent $event, array $references, ?SceneBaseline $baseline): string
    {
        $notes = $this->homeContext->notes();

        return trim(<<<PROMPT
            You review frames from a home security camera and classify one event.

            Camera: {$this->homeContext->describeCamera($event->getCamera())}

            The frames are in time order; the subject may only appear in a later frame.
            {$this->baselineInstruction($baseline)}
            Common false alarms: {$this->commonFalseAlarms()}.
            Describe people neutrally and never guess their names. Hints from the home are given when available; they may be unrelated to what the frames show.
            {$this->knownIndividuals($references)}
            Unusual events: {$this->homeContext->unusualRule()}
            {$notes}
            PROMPT);
    }

    /**
     * @param list<IndividualReferences> $references
     */
    private function knownIndividuals(array $references): string
    {
        if ([] === $references) {
            return '';
        }

        return <<<'TEXT'
            Known individuals: their reference photos come before the event. First decide what kind of animal or person the subject is from its build, head, muzzle, ears, coat and tail; a dog, a fox or any other animal is never a known cat. Name a known individual only if the subject is of its kind and clearly matches it in markings, colours and build; similar-looking ones may visit, so answer unknown when in doubt. When the subject is small, far away, dark, blurred or only partly visible, do not answer high confidence. A known individual seen on another camera shortly before or after, listed with the event, makes it likely the same one, but the markings must still fit. List other known individuals that are clearly visible too, e.g. the household cat next to a person, under alsoSeen.
            TEXT;
    }

    private function baselineInstruction(?SceneBaseline $baseline): string
    {
        return $baseline instanceof SceneBaseline
            ? 'The usual view shows the scene with nothing happening: look for what is new or has moved in the event\'s frames, and treat what is in both as background.'
            : '';
    }

    /**
     * @return list<string|PromptImage>
     */
    private function baseline(?SceneBaseline $baseline): array
    {
        if (!$baseline instanceof SceneBaseline) {
            return [];
        }

        return [
            \sprintf('The camera\'s usual view with nothing happening, from %s, for comparison. Not part of the event.', $this->homeTimeZone->toLocal($baseline->recordedAt)->format('j F, H:i')),
            new PromptImage($this->frameStorage->absolutePath($baseline->framePath)),
        ];
    }

    /**
     * Reference photos of the known individuals, each after a line that names it.
     *
     * @param list<IndividualReferences> $references
     *
     * @return list<string|PromptImage>
     */
    private function references(array $references): array
    {
        $parts = [];
        foreach ($references as $reference) {
            $individual = $reference->individual;
            $parts[] = \sprintf(
                'Reference photos of the known individual "%s" (%s, a %s)%s. Not part of the event.',
                $individual->name,
                $individual->label,
                strtolower($this->settings->settings()->subjects->label($individual->subject)),
                null === $individual->description ? '' : ': '.$individual->description,
            );
            foreach ($reference->photos as $photo) {
                $parts[] = new PromptImage($photo);
            }
        }

        return $parts;
    }

    /**
     * @param list<string> $hints
     * @param list<string> $sightings
     */
    private function context(CameraEvent $event, array $hints, array $sightings): string
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

        if ([] !== $sightings) {
            $lines[] = 'Known individuals on other cameras around that time:';
            foreach ($sightings as $sighting) {
                $lines[] = '- '.$sighting;
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

    /**
     * The identified individual; null when the AI did not recognise one, or named one of another subject.
     */
    private function individual(EventDescriptionOutput $output): ?string
    {
        if (Individual::UNKNOWN === $output->individual) {
            return null;
        }

        $individual = $this->settings->settings()->individual($output->individual);
        if (!$individual instanceof Individual) {
            throw new AiRequestFailedException(\sprintf('The AI named the unknown individual "%s".', $output->individual));
        }

        return $individual->subject === $output->subject ? $individual->name : null;
    }

    /**
     * @return list<string> the other known individuals the AI saw, without the subject's and unknown names
     */
    private function alsoSeen(EventDescriptionOutput $output): array
    {
        $settings = $this->settings->settings();

        return array_values(array_filter(
            array_unique($output->alsoSeen),
            static fn (string $name): bool => $name !== $output->individual && $settings->individual($name) instanceof Individual,
        ));
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
