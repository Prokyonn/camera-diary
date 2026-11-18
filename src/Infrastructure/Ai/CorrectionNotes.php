<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\VocabularyKind;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;

/**
 * The owner's corrections as prompt text: what the AI said, and what the owner set instead.
 */
final readonly class CorrectionNotes
{
    public function __construct(
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @param list<CameraEvent> $corrected
     */
    public function notes(array $corrected): string
    {
        if ([] === $corrected) {
            return '';
        }

        $lines = ['Earlier answers on this camera that the owner corrected; do not repeat these mistakes:'];
        foreach ($corrected as $event) {
            $lines[] = \sprintf(
                '- %s: you said %s: "%s" The owner: %s.',
                $this->homeTimeZone->toLocal($event->getStartedAt())->format('D j M, H:i'),
                $this->aiAnswer($event),
                $event->getSummary() ?? '',
                implode('; ', $this->ownerAnswer($event)),
            );
        }

        return implode("\n", $lines);
    }

    private function aiAnswer(CameraEvent $event): string
    {
        $settings = $this->settings->settings();
        $verdict = $event->getVerdict() ?? Verdict::Real;
        $cause = $event->getFalseAlarmCause();
        $subject = $event->getSubject();
        $individual = $event->getIndividual();

        return strtolower(implode(', ', array_filter([
            $verdict->label().(Verdict::FalseAlarm === $verdict && null !== $cause ? ' ('.$settings->falseAlarmCauses->label($cause).')' : ''),
            null === $subject ? null : $settings->subjects->label($subject),
            null === $individual ? null : $settings->vocabulary(VocabularyKind::Individuals)->label($individual),
        ], static fn (?string $part): bool => null !== $part)));
    }

    /**
     * @return list<string>
     */
    private function ownerAnswer(CameraEvent $event): array
    {
        $answer = [];
        $verdict = $event->getVerdictOverride();
        if ($verdict instanceof Verdict && $verdict !== $event->getVerdict()) {
            $answer[] = strtolower($verdict->label());
        }

        $individual = $event->getIndividualOverride();
        if (null !== $individual && $individual !== ($event->getIndividual() ?? CameraEvent::NO_KNOWN_INDIVIDUAL)) {
            $answer[] = CameraEvent::NO_KNOWN_INDIVIDUAL === $individual
                ? 'none of the known individuals'
                : 'it was '.$this->settings->settings()->vocabulary(VocabularyKind::Individuals)->label($individual);
        }

        $label = $event->getLabel();
        if (null !== $label) {
            $answer[] = \sprintf('labelled it "%s"', $label);
        }

        return $answer;
    }
}
