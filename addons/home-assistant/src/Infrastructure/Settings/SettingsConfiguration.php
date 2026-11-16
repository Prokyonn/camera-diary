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
                ->arrayNode('push')
                    ->info('Optional: the AI verdict replaces the camera push Home Assistant sent')
                    ->children()
                        ->scalarNode('handover_entity')->isRequired()->cannotBeEmpty()->info('input_text the push script sets to "camera|epoch|mute 0/1|tag|group|file base|title|message"')->end()
                        ->scalarNode('notify_service')->isRequired()->cannotBeEmpty()->info('e.g. mobile_app_my_phone for notify.mobile_app_my_phone')->end()
                        ->scalarNode('url')->defaultNull()->info('Where tapping the push leads, e.g. /lovelace/cameras')->end()
                        ->scalarNode('details_title')->defaultValue('Details')->info('Button that opens the event in Camera events')->end()
                        ->enumNode('loud_interruption_level')->values(['active', 'time-sensitive', 'critical'])->defaultValue('active')->info('For unusual events; all others update passively')->end()
                        ->arrayNode('mute_actions')
                            ->normalizeKeys(false)
                            ->info('Action id => button title, kept on pushes that had them')
                            ->useAttributeAsKey('action')
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
