<?php

declare(strict_types=1);

namespace App\Infrastructure\Settings;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Schema of the settings file (CAMERA_DIARY_CONFIG). Every key is optional; the vocabularies merge with the defaults.
 */
final class SettingsConfiguration implements ConfigurationInterface
{
    /** @var array<string, array{label: string, description?: string}> */
    public const array DEFAULT_DETECTION_TYPES = [
        'person' => ['label' => 'Person'],
        'animal' => ['label' => 'Animal'],
        'vehicle' => ['label' => 'Vehicle'],
        'motion' => ['label' => 'Motion'],
    ];

    /** @var array<string, array{label: string, description?: string}> */
    public const array DEFAULT_SUBJECTS = [
        'person' => ['label' => 'Person'],
        'cat' => ['label' => 'Cat'],
        'dog' => ['label' => 'Dog'],
        'bird' => ['label' => 'Bird'],
        'other_animal' => ['label' => 'Other animal'],
        'vehicle' => ['label' => 'Vehicle'],
        'nothing' => ['label' => 'Nothing', 'description' => 'nothing relevant is visible (false alarms)'],
        'other' => ['label' => 'Other'],
    ];

    /** @var array<string, array{label: string, description?: string}> */
    public const array DEFAULT_FALSE_ALARM_CAUSES = [
        'spider_web' => ['label' => 'Spider web', 'description' => 'a spider web or a single thread swinging in front of the lens, often lit by the infrared light at night'],
        'insect' => ['label' => 'Insect', 'description' => 'insects close to the lens'],
        'rain' => ['label' => 'Rain', 'description' => 'rain or snow'],
        'light' => ['label' => 'Light', 'description' => 'headlights, sun reflections or moving shadows'],
        'nothing' => ['label' => 'Nothing', 'description' => 'no visible cause'],
        'other' => ['label' => 'Other', 'description' => 'anything else, e.g. plants moving in the wind'],
    ];

    public const string DEFAULT_UNUSUAL_RULE = 'A person on our property who does not seem to belong there, or anyone on our property at night.';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('camera_diary');

        $treeBuilder->getRootNode()
            ->children()
                ->append($this->ai())
                ->append($this->vocabulary('detection_types', 'What cameras report, e.g. person or animal.'))
                ->append($this->vocabulary('subjects', 'What the AI may name as the main subject of an event.'))
                ->append($this->vocabulary('false_alarm_causes', 'What the AI may name as the cause of a false alarm ("none" is reserved for real events).'))
                ->append($this->individuals())
                ->append($this->description())
                ->append($this->questions())
                ->append($this->maintenance())
            ->end();

        return $treeBuilder;
    }

    private function ai(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('ai');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('daily_call_limit')->min(0)->defaultValue(500)->info('AI calls per local day, for events and questions')->end()
                ->arrayNode('call_pools')
                    ->info('Shares of the daily limit per detection type; an event counts towards the first pool that holds one of its detection types')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('detection_types')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
                            ->integerNode('daily_call_limit')->isRequired()->min(0)->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('home_context')->defaultValue('')->info('Free text every prompt gets, e.g. "Our cat is called Luna."')->end()
                ->scalarNode('unusual_rule')->defaultValue(self::DEFAULT_UNUSUAL_RULE)->info('Which events the AI flags as unusual')->end()
            ->end();

        return $node;
    }

    private function vocabulary(string $name, string $info): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->info($info.' Merged with the defaults; "<name>: false" removes one.')
            ->normalizeKeys(false)
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->canBeDisabled()
                ->children()
                    ->scalarNode('label')->defaultNull()->info('Shown in the app; default: the name as words')->end()
                    ->scalarNode('description')->defaultNull()->info('Hint for the AI')->end()
                ->end()
            ->end();

        return $node;
    }

    private function individuals(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('individuals');
        $node
            ->info('Known individuals, e.g. your cat: the AI compares the subject with their reference photos ("unknown" is reserved)')
            ->normalizeKeys(false)
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('label')->defaultNull()->info('Shown in the app; default: the name as words')->end()
                    ->scalarNode('subject')->isRequired()->cannotBeEmpty()->info('What it is, a name from subjects, e.g. cat')->end()
                    ->scalarNode('description')->defaultNull()->info('What tells it apart, for the AI')->end()
                    ->arrayNode('photos')
                        ->info('Reference photos, relative to the settings file; crops that show the individual large work best')
                        ->isRequired()
                        ->requiresAtLeastOneElement()
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function description(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('description');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('frame_offsets')
                    ->info('Seconds after the start of an event at which a frame source (e.g. recordings) cuts the frames')
                    ->requiresAtLeastOneElement()
                    ->integerPrototype()->min(0)->end()
                    ->defaultValue([1, 2, 3])
                ->end()
                ->integerNode('retry_delay')->min(1)->defaultValue(600)->info('Seconds until an AI or ffmpeg failure, or missing footage, is tried again')->end()
                ->integerNode('max_attempts')->min(1)->defaultValue(8)->info('Failed AI or ffmpeg attempts before an event ends as failed')->end()
                ->integerNode('footage_grace')->min(0)->defaultValue(3600)->info('Seconds to wait for footage before an event ends as no_footage')->end()
                ->integerNode('batch_size')->min(1)->defaultValue(20)->info('Events the worker describes per run')->end()
            ->end();

        return $node;
    }

    private function questions(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('questions');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('search_results')->min(1)->defaultValue(12)->info('Events a question searches for')->end()
                ->integerNode('answer_events')->min(1)->defaultValue(6)->info('Top events the AI gets to answer')->end()
                ->integerNode('frames_per_event')->min(0)->defaultValue(2)->info('Frames per event the AI gets to answer')->end()
            ->end();

        return $node;
    }

    private function maintenance(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('maintenance');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('nightly_at')
                    ->defaultValue('03:30')
                    ->info('Local time of the nightly clean-up and database backup, HH:MM')
                    ->validate()
                        ->ifTrue(static fn (mixed $time): bool => !\is_string($time) || 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time))
                        ->thenInvalid('Use HH:MM, e.g. "03:30" (quoted).')
                    ->end()
                ->end()
                ->integerNode('false_alarm_frame_days')->min(1)->defaultValue(30)->info('Days false-alarm frames are kept')->end()
                ->integerNode('backups_kept')->min(1)->defaultValue(14)->info('Nightly database backups kept')->end()
            ->end();

        return $node;
    }
}
