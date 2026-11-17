<?php

declare(strict_types=1);

namespace App\Infrastructure\Seal;

use App\Application\Description\HintCollector;
use App\Application\Search\EventIndexerInterface;
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
        $cause = $event->isFalseAlarm() ? $event->getFalseAlarmCause() : null;

        return [
            'id' => (string) $event->getId(),
            'label' => $event->getLabel() ?? '',
            'summary' => $event->getSummary() ?? '',
            'subject' => null === $subject ? '' : $this->subjectText($subject, $event->getEffectiveIndividual()),
            'subjectName' => $subject ?? '',
            'individual' => $event->getEffectiveIndividual() ?? '',
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
     * "Anna Person" for a known individual, so a search for either finds the event; else "Other cat" or "Cat".
     */
    private function subjectText(string $subject, ?string $individual): string
    {
        $settings = $this->settings->settings();
        $label = $settings->subjectLabel($subject, $individual);

        return null === $individual ? $label : $label.' '.$settings->subjects->label($subject);
    }
}
