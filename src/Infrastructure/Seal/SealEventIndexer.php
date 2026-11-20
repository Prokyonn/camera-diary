<?php

declare(strict_types=1);

namespace App\Infrastructure\Seal;

use App\Application\Description\HintCollector;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use CmsIg\Seal\EngineInterface;
use DateTimeInterface;

/**
 * Keeps the SEAL index in step with the database, which stays the source of truth.
 */
final readonly class SealEventIndexer implements EventIndexerInterface
{
    public const string INDEX = 'camera_event';
    public const string UNKNOWN = 'unknown';

    public function __construct(
        private EngineInterface $engine,
        private HintCollector $hints,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function index(CameraEvent $event): void
    {
        $this->engine->saveDocument(self::INDEX, $this->document($event));
    }

    /**
     * @return array<string, mixed>
     */
    public function document(CameraEvent $event): array
    {
        $settings = $this->settings->settings();
        $subject = $event->getSubject();
        $cause = $event->getEffectiveFalseAlarmCause();

        return [
            'id' => (string) $event->getId(),
            'label' => $event->getLabel() ?? '',
            'summary' => $event->getSummary() ?? '',
            'subject' => null === $subject ? '' : $this->subjectText($subject, $event),
            'subjectName' => null === $subject ? [] : $this->subjectNames($subject, $event),
            'individual' => $event->getIndividualsSeen(),
            'falseAlarmCause' => null === $cause ? '' : $settings->falseAlarmCauses->label($cause),
            'hints' => $this->hints->hintsFor($event),
            'camera' => $event->getCamera()->getSlug(),
            'types' => $event->getDetectionTypes(),
            'verdict' => $event->getEffectiveVerdict()->value ?? self::UNKNOWN,
            'side' => $event->getSide()->value ?? self::UNKNOWN,
            'status' => $event->getStatus()->value,
            'startedAt' => $event->getStartedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * "Sam Person" for a known individual, so a search for either finds the event; else "Other cat" or "Cat"; then the
     * other known individuals in the event ("Person Luna").
     */
    private function subjectText(string $subject, CameraEvent $event): string
    {
        $settings = $this->settings->settings();
        $individual = $event->getEffectiveIndividual();
        $label = $settings->subjectLabel($subject, $individual);
        $others = array_map($settings->individualLabel(...), $event->getAlsoSeen());

        return implode(' ', [null === $individual ? $label : $label.' '.$settings->subjects->label($subject), ...$others]);
    }

    /**
     * @return list<string> the subject and those of the other known individuals, so "cats" also finds a person with the cat
     */
    private function subjectNames(string $subject, CameraEvent $event): array
    {
        $names = [$subject];
        foreach ($event->getAlsoSeen() as $other) {
            $individual = $this->settings->settings()->individual($other);
            if ($individual instanceof Individual) {
                $names[] = $individual->subject;
            }
        }

        return array_values(array_unique($names));
    }
}
