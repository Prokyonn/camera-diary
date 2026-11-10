<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Infrastructure\Settings;

use App\Domain\Model\Camera;
use App\Domain\Model\DetectionType;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Schema of the add-on's YAML file (HOME_ASSISTANT_CONFIG).
 */
final class SettingsConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('home_assistant');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('cameras')
                    ->normalizeKeys(false)
                    ->info('Camera slug => mapping')
                    ->useAttributeAsKey('slug')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->defaultNull()->end()
                            ->scalarNode('scene')->defaultNull()->info('What the camera sees; context for the AI')->end()
                            ->scalarNode('sensor_prefix')->isRequired()->cannotBeEmpty()->info('e.g. binary_sensor.garden for binary_sensor.garden_person')->end()
                            ->arrayNode('detections')
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifNotInArray(array_map(static fn (DetectionType $type): string => $type->value, DetectionType::cases()))
                                        ->thenInvalid('Unknown detection type %s.')
                                    ->end()
                                ->end()
                                ->defaultValue(['person', 'animal'])
                            ->end()
                            ->scalarNode('recording_folder')->defaultNull()->info('Folder of continuous recordings under RECORDINGS_DIR')->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $cameras): bool => [] !== array_filter(array_keys($cameras), static fn (int|string $slug): bool => !Camera::isValidSlug((string) $slug)))
                        ->thenInvalid('Camera slugs use lower-case letters, digits, "-" and "_".')
                    ->end()
                ->end()
                ->arrayNode('signals')
                    ->normalizeKeys(false)
                    ->info('Entity id => the hint text per state')
                    ->useAttributeAsKey('entity_id')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('time_attribute')->defaultNull()->info('Attribute with the real time of the change, e.g. since')->end()
                            ->arrayNode('states')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->useAttributeAsKey('state')
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
