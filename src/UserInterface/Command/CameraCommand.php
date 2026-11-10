<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Camera\CameraSettings;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:camera', 'List the cameras, or create or change one: its name and the scene the AI gets as context')]
final readonly class CameraCommand
{
    public function __construct(
        private CameraRepositoryInterface $cameras,
        private CameraSettings $settings,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Camera slug, e.g. garden')]
        string $slug = '',
        #[Option('Display name')]
        ?string $name = null,
        #[Option('What the camera sees, e.g. "Back garden; the fence at the back is the property line"')]
        ?string $scene = null,
    ): int {
        if ('' !== $slug) {
            try {
                $this->settings->update($slug, $name, $scene);
            } catch (InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::INVALID;
            }
        }

        $io->table(['Slug', 'Name', 'Scene'], array_map(
            static fn (Camera $camera): array => [$camera->getSlug(), $camera->getName(), $camera->getScene() ?? ''],
            $this->cameras->findBy([], ['name' => 'asc']),
        ));

        return Command::SUCCESS;
    }
}
