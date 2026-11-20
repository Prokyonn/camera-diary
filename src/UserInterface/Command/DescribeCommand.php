<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Description\PendingEventRunner;
use App\Application\Description\ProcessOutcome;
use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraEventRepositoryInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:describe', 'Describe one event now, also again if it was described before')]
final readonly class DescribeCommand
{
    public function __construct(
        private CameraEventRepositoryInterface $events,
        private PendingEventRunner $runner,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Event id')] int $id): int
    {
        $event = $this->events->findOneBy(['id' => $id]);
        if (!$event instanceof CameraEvent) {
            $io->error(\sprintf('Event %d does not exist.', $id));

            return Command::FAILURE;
        }

        $outcome = $this->runner->describe($event);
        if (!$outcome instanceof ProcessOutcome) {
            $io->error('Another description run is in progress; try again in a minute.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Outcome' => $outcome->value],
            ['Verdict' => $event->getVerdict()->value ?? '-'],
            ['Side' => $event->getSide()->value ?? '-'],
            ['Subject' => $event->getSubject() ?? '-'],
            ['Individual' => $event->getIndividual() ?? '-'],
            ['Summary' => $event->getSummary() ?? '-'],
        );

        return Command::SUCCESS;
    }
}
