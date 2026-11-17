<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Description\PendingEventRunner;
use App\Application\Description\ProcessOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:process', 'Describe the pending events once, like one worker run')]
final readonly class ProcessCommand
{
    public function __construct(
        private PendingEventRunner $runner,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('How many events to describe (default: the batch size from the settings)')] ?int $limit = null): int
    {
        $outcomes = $this->runner->run($limit);
        if (null === $outcomes) {
            $io->warning('Another run holds the lock.');

            return Command::FAILURE;
        }

        $io->success(ucfirst(ProcessOutcome::summarize($outcomes)).'.');

        return Command::SUCCESS;
    }
}
