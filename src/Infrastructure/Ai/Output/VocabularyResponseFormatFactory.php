<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Output;

use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\Term;
use ReflectionClass;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Adds the configured vocabularies to the JSON schema of AI outputs: fields marked with #[VocabularyChoice] get the
 * names as their allowed values, and the labels and hints in their description.
 */
#[AsDecorator('ai.platform.response_format_factory')]
final readonly class VocabularyResponseFormatFactory implements ResponseFormatFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ResponseFormatFactoryInterface $inner,
        private SettingsProviderInterface $settings,
    ) {
    }

    public function create(string $responseClass): array
    {
        $format = $this->inner->create($responseClass);
        $properties = $format['json_schema']['schema']['properties'] ?? null;
        if (!class_exists($responseClass) || !\is_array($properties)) {
            return $format;
        }

        foreach (new ReflectionClass($responseClass)->getProperties() as $property) {
            $attribute = $property->getAttributes(VocabularyChoice::class)[0] ?? null;
            $schema = $properties[$property->getName()] ?? null;
            if (null !== $attribute && \is_array($schema)) {
                $properties[$property->getName()] = $this->restrict($schema, $attribute->newInstance());
            }
        }

        $format['json_schema']['schema']['properties'] = $properties;

        return $format;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<mixed>
     */
    private function restrict(array $schema, VocabularyChoice $choice): array
    {
        $choices = $choice->extra;
        foreach ($this->settings->settings()->vocabulary($choice->vocabulary)->terms as $term) {
            $choices[$term->name] = $this->meaning($term);
        }

        $schema['enum'] = array_keys($choices);
        $explained = implode('; ', array_map(
            static fn (string $name, string $meaning): string => '' === $meaning ? $name : \sprintf('%s: %s', $name, $meaning),
            array_keys($choices),
            $choices,
        ));
        $description = \is_string($schema['description'] ?? null) ? $schema['description'] : '';
        $schema['description'] = trim(\sprintf('%s. One of: %s', rtrim($description, '.'), $explained), '. ');

        return $schema;
    }

    private function meaning(Term $term): string
    {
        return $term->description ?? (strtolower($term->label) === str_replace('_', ' ', $term->name) ? '' : $term->label);
    }
}
