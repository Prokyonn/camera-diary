<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\UserInterface\Command;

use CameraDiary\HomeAssistant\Application\History\HomeHistoryUnavailableException;
use CameraDiary\HomeAssistant\Application\Import\HomeAssistantImport;
use CameraDiary\HomeAssistant\Application\Import\ImportReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('home-assistant:import', 'Import new Home Assistant history once, like one worker run')]
final readonly class ImportCommand
{
    public function __construct(
        private HomeAssistantImport $import,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $report = $this->import->run();
        } catch (HomeHistoryUnavailableException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!$report instanceof ImportReport) {
            $io->warning('Not configured (HOME_ASSISTANT_CONFIG), or another run holds the lock.');

            return Command::FAILURE;
        }

        $io->success(ucfirst($report->summary()).'.');

        return Command::SUCCESS;
    }
}
