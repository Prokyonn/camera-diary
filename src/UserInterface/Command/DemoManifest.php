<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Demo\DemoLoader;
use App\Application\Description\EventDescription;
use App\Application\Settings\Individual;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads demo/demo.yaml: the cameras, the clips with their time, and optionally the AI's answer recorded from a real
 * run, an owner's correction, and questions asked about them.
 *
 * @phpstan-import-type Manifest from DemoLoader
 */
final class DemoManifest
{
    /**
     * @return Manifest
     *
     * @throws ParseException|ConfigException
     */
    public static function read(string $path): array
    {
        $tree = new TreeBuilder('demo');
        $tree->getRootNode()
            ->children()
                ->arrayNode('cameras')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('slug')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->end()
                            ->scalarNode('scene')->isRequired()->end()
                        ->end()
                    ->end()
                ->end()
                ->append(self::events())
                ->arrayNode('questions')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('question')->isRequired()->end()
                            ->scalarNode('answer')->isRequired()->end()
                            ->arrayNode('events')->info('Keys of the events shown with the answer')->scalarPrototype()->end()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('digests')
                    ->info('Day summaries recorded from a real run, stored by app:demo --recorded')
                    ->arrayPrototype()
                        ->children()
                            ->integerNode('days_ago')->min(0)->defaultValue(1)->end()
                            ->scalarNode('text')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('model')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        /** @var Manifest */
        return new Processor()->process($tree->buildTree(), [Yaml::parseFile($path)]);
    }

    private static function events(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('events');
        $node
            ->normalizeKeys(false)
            ->useAttributeAsKey('key')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('camera')->isRequired()->end()
                    ->scalarNode('clip')->isRequired()->end()
                    ->scalarNode('time')
                        ->isRequired()
                        ->info('Local time of day, HH:MM')
                        ->validate()
                            ->ifTrue(static fn (mixed $time): bool => !\is_string($time) || 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time))
                            ->thenInvalid('Use HH:MM, got %s.')
                        ->end()
                    ->end()
                    ->integerNode('days_ago')->min(0)->defaultValue(0)->info('Days before today: 0 for today (a time still ahead moves to yesterday), 1 for yesterday, ...')->end()
                    ->arrayNode('detections')->scalarPrototype()->end()->end()
                    ->arrayNode('hints')->scalarPrototype()->end()->end()
                    ->arrayNode('recorded')
                        ->info('The AI\'s answer from a real run, stored by app:demo --recorded')
                        ->children()
                            ->scalarNode('model')->isRequired()->end()
                            ->scalarNode('verdict')->isRequired()->end()
                            ->scalarNode('false_alarm_cause')->defaultValue(EventDescription::NO_FALSE_ALARM)->end()
                            ->scalarNode('side')->isRequired()->end()
                            ->scalarNode('subject')->isRequired()->end()
                            ->scalarNode('individual')->defaultValue(Individual::UNKNOWN)->end()
                            ->arrayNode('also_seen')->info('Other known individuals in the frames')->scalarPrototype()->end()->end()
                            ->scalarNode('summary')->isRequired()->end()
                            ->booleanNode('unusual')->defaultFalse()->end()
                            ->scalarNode('confidence')->defaultValue('high')->end()
                        ->end()
                    ->end()
                    ->scalarNode('repeat_of')
                        ->info('Key of an earlier false alarm with a recorded answer: app:demo --recorded files this event as its repeat, as the app does without an AI call')
                    ->end()
                    ->arrayNode('correction')
                        ->info('What the owner set by hand')
                        ->children()
                            ->scalarNode('verdict')->defaultNull()->end()
                            ->scalarNode('individual')->defaultNull()->end()
                            ->scalarNode('label')->defaultNull()->end()
                            ->scalarNode('false_alarm_cause')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}
