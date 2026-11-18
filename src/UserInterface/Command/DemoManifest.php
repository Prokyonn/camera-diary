<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

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
 * @phpstan-type Recorded array{model: string, verdict: string, false_alarm_cause: string, side: string, subject: string, individual: string, summary: string, unusual: bool, confidence: string}
 * @phpstan-type Correction array{verdict: ?string, individual: ?string, label: ?string, false_alarm_cause: ?string}
 * @phpstan-type DemoEvent array{camera: string, clip: string, time: string, days_ago: int, detections: list<string>, hints: list<string>, recorded?: Recorded, correction?: Correction}
 * @phpstan-type Question array{question: string, answer: string, events: list<string>}
 * @phpstan-type Manifest array{cameras: array<string, array{name: string, scene: string}>, events: array<string, DemoEvent>, questions: list<Question>}
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
                    ->integerNode('days_ago')->min(0)->defaultValue(0)->info('0 for the latest past occurrence of the time, 1 for the day before, ...')->end()
                    ->arrayNode('detections')->scalarPrototype()->end()->end()
                    ->arrayNode('hints')->scalarPrototype()->end()->end()
                    ->arrayNode('recorded')
                        ->info('The AI\'s answer from a real run, stored by app:demo --recorded')
                        ->children()
                            ->scalarNode('model')->isRequired()->end()
                            ->scalarNode('verdict')->isRequired()->end()
                            ->scalarNode('false_alarm_cause')->defaultValue('none')->end()
                            ->scalarNode('side')->isRequired()->end()
                            ->scalarNode('subject')->isRequired()->end()
                            ->scalarNode('individual')->defaultValue('unknown')->end()
                            ->scalarNode('summary')->isRequired()->end()
                            ->booleanNode('unusual')->defaultFalse()->end()
                            ->scalarNode('confidence')->defaultValue('high')->end()
                        ->end()
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
