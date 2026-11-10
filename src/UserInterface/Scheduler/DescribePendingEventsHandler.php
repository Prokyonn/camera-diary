<?php

declare(strict_types=1);

namespace App\UserInterface\Scheduler;

use App\Application\Description\PendingEventRunner;
use App\Application\Description\ProcessOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DescribePendingEventsHandler
{
    public function __construct(
        private PendingEventRunner $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DescribePendingEvents $message): void
    {
        $outcomes = $this->runner->run();

        $this->logger->info(null === $outcomes ? 'Description skipped: another run holds the lock' : 'Description: '.ProcessOutcome::summarize($outcomes));
    }
}
