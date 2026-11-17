<?php

declare(strict_types=1);

namespace App\UserInterface\Twig;

use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\VocabularyKind;
use App\Domain\Model\CameraEvent;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Labels for stored vocabulary names: {{ event.cause|term_label('false_alarm_causes') }}, {{ subject_label(event) }}.
 */
final readonly class VocabularyExtension
{
    public function __construct(
        private SettingsProviderInterface $settings,
    ) {
    }

    #[AsTwigFilter('term_label')]
    public function termLabel(?string $name, string $vocabulary): string
    {
        return null === $name ? '' : $this->settings->settings()->vocabulary(VocabularyKind::from($vocabulary))->label($name);
    }

    /**
     * "Luna", "Other cat" or "Cat"; empty for events without a description.
     */
    #[AsTwigFunction('subject_label')]
    public function subjectLabel(CameraEvent $event): string
    {
        $subject = $event->getSubject();

        return null === $subject ? '' : $this->settings->settings()->subjectLabel($subject, $event->getEffectiveIndividual());
    }
}
