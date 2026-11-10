<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Maintenance\NightlyMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:maintenance', 'Run the nightly maintenance now: false-alarm frame retention and database backup')]
final readonly class MaintenanceCommand
{
    public function __construct(
        private NightlyMaintenance $maintenance,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->maintenance->run();
        $io->success('Maintenance done.');

        return Command::SUCCESS;
    }
}
