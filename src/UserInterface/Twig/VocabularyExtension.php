<?php

declare(strict_types=1);

namespace App\UserInterface\Twig;

use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\VocabularyKind;
use Twig\Attribute\AsTwigFilter;

/**
 * Labels for stored vocabulary names: {{ event.subject|term_label('subjects') }}.
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
}
