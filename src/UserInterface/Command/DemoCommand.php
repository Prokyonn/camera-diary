<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Demo\DemoLoader;
use App\Application\Demo\InvalidDemoException;
use App\Application\Demo\LoadedDemoEvent;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;

#[AsCommand('app:demo', 'Load the demo cameras and clips; then run app:process (or the worker) to describe them')]
final readonly class DemoCommand
{
    public function __construct(
        private DemoLoader $loader,
        #[Autowire('%kernel.project_dir%/demo/demo.yaml')]
        private string $defaultManifest,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Demo manifest')]
        string $manifest = '',
        #[Option('Store the AI answers recorded in the manifest instead of asking the AI')]
        bool $recorded = false,
    ): int {
        $path = '' === $manifest ? $this->defaultManifest : $manifest;

        try {
            $demo = DemoManifest::read($path);
        } catch (ParseException|ConfigException $exception) {
            $io->error(\sprintf('Invalid demo manifest "%s": %s', $path, $exception->getMessage()));

            return Command::INVALID;
        }

        try {
            $loaded = $this->loader->load($demo, \dirname($path), $recorded);
        } catch (InvalidDemoException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Event', 'Camera', 'Result', 'Status'], array_map(
            static fn (LoadedDemoEvent $event): array => [$event->key, $event->camera, $event->created ? 'stored' : 'already stored', $event->status->value],
            $loaded,
        ));
        $io->success($recorded
            ? 'Demo loaded with the recorded AI answers.'
            : 'Demo loaded. Run "bin/console app:process" (or the worker) to describe the events.');

        return Command::SUCCESS;
    }
}
