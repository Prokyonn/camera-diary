<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\Settings;

use App\Domain\Model\Camera;
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
                                ->info('Detection types (see detection_types in the Camera diary settings); each is the sensor <sensor_prefix>_<type>')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()->end()
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
                ->arrayNode('import')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_gap')->min(1)->defaultValue(120)->info('Seconds between detections that still join one event')->end()
                        ->integerNode('max_duration')->min(1)->defaultValue(600)->info('Seconds an event lasts at most')->end()
                        ->integerNode('hint_window')->min(0)->defaultValue(180)->info('Seconds before and after an event in which signals become its hints')->end()
                    ->end()
                ->end()
                ->arrayNode('push')
                    ->info('Optional: the AI verdict replaces the camera push Home Assistant sent')
                    ->children()
                        ->scalarNode('handover_entity')->isRequired()->cannotBeEmpty()->info('input_text the push script sets to "camera|epoch|mute 0/1|tag|group|file base|title|message"')->end()
                        ->scalarNode('notify_service')->isRequired()->cannotBeEmpty()->info('e.g. mobile_app_my_phone for notify.mobile_app_my_phone')->end()
                        ->scalarNode('url')->defaultNull()->info('Where tapping the push leads, e.g. /lovelace/cameras')->end()
                        ->scalarNode('details_title')->defaultValue('Details')->info('Button that opens the event in Camera diary')->end()
                        ->enumNode('loud_interruption_level')->values(['active', 'time-sensitive', 'critical'])->defaultValue('active')->info('For unusual events; all others update passively')->end()
                        ->arrayNode('mute_actions')
                            ->normalizeKeys(false)
                            ->info('Action id => button title, kept on pushes that had them')
                            ->useAttributeAsKey('action')
                            ->scalarPrototype()->end()
                        ->end()
                        ->integerNode('max_age')->min(0)->defaultValue(3600)->info('Seconds after which a push is no longer updated')->end()
                        ->arrayNode('frames')
                            ->info('Per frame of an event (frame_offsets in the Camera diary settings): "snapshot", or the second in the clip')
                            ->requiresAtLeastOneElement()
                            ->variablePrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $frame): bool => 'snapshot' !== $frame && (!\is_int($frame) && !\is_float($frame) || $frame < 0))
                                    ->thenInvalid('Use "snapshot" or a second in the clip, e.g. 0.5.')
                                ->end()
                            ->end()
                            ->defaultValue(['snapshot', 0.5, 3.5])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
