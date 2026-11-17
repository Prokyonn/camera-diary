<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Camera\CameraSettings;
use App\Application\Clock\HomeTimeZone;
use App\Application\Ingest\EventIngester;
use App\Application\Ingest\IngestRequest;
use App\Application\Ingest\InvalidIngestException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the sample clips of demo/demo.yaml through the ingest hook, each at the latest past occurrence of its local
 * time of day; loading again is a no-op.
 */
#[AsCommand('app:demo', 'Load the demo cameras and clips; then run app:process (or the worker) to describe them')]
final readonly class DemoCommand
{
    public const string SOURCE = 'demo';

    public function __construct(
        private CameraSettings $cameras,
        private EventIngester $ingester,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        #[Autowire('%kernel.project_dir%/demo/demo.yaml')]
        private string $defaultManifest,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Demo manifest')] string $manifest = ''): int
    {
        $path = '' === $manifest ? $this->defaultManifest : $manifest;

        try {
            $demo = $this->read($path);
        } catch (ParseException|ConfigException $exception) {
            $io->error(\sprintf('Invalid demo manifest "%s": %s', $path, $exception->getMessage()));

            return Command::INVALID;
        }

        foreach ($demo['cameras'] as $slug => $camera) {
            $this->cameras->update($slug, $camera['name'], $camera['scene']);
        }

        $rows = [];
        foreach ($demo['events'] as $key => $event) {
            $startedAt = $this->latestOccurrence($event['time']);
            $clip = \dirname($path).'/'.$event['clip'];
            $isVideo = !\in_array(strtolower(pathinfo($clip, \PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);

            try {
                $result = $this->ingester->ingest(new IngestRequest(
                    cameraSlug: $event['camera'],
                    startedAt: $startedAt,
                    videoPath: $isVideo ? $clip : null,
                    imagePaths: $isVideo ? [] : [$clip],
                    detectionTypes: $event['detections'],
                    hints: $event['hints'],
                    source: self::SOURCE,
                    externalId: $key,
                ));
            } catch (InvalidIngestException $exception) {
                $io->error(\sprintf('Event "%s": %s', $key, $exception->getMessage()));

                return Command::FAILURE;
            }

            $rows[] = [$key, $event['camera'], $result->created ? 'stored' : 'already stored', $result->event->getStatus()->value];
        }

        $io->table(['Event', 'Camera', 'Result', 'Status'], $rows);
        $io->success('Demo loaded. Run "bin/console app:process" (or the worker) to describe the events.');

        return Command::SUCCESS;
    }

    private function latestOccurrence(string $time): DateTimeImmutable
    {
        $now = $this->homeTimeZone->toLocal($this->clock->now());
        $at = $now->modify($time);

        return ($at > $now ? $at->modify('-1 day') : $at)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array{
     *     cameras: array<string, array{name: string, scene: string}>,
     *     events: array<string, array{camera: string, clip: string, time: string, detections: list<string>, hints: list<string>}>,
     * }
     */
    private function read(string $path): array
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
                ->arrayNode('events')
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
                            ->arrayNode('detections')->scalarPrototype()->end()->end()
                            ->arrayNode('hints')->scalarPrototype()->end()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        /** @var array{cameras: array<string, array{name: string, scene: string}>, events: array<string, array{camera: string, clip: string, time: string, detections: list<string>, hints: list<string>}>} */
        return new Processor()->process($tree->buildTree(), [Yaml::parseFile($path)]);
    }
}
