<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\UserInterface\Command;

use App\Application\Clock\HomeTimeZone;
use CameraDiary\HomeAssistant\Application\History\HomeHistoryUnavailableException;
use CameraDiary\HomeAssistant\Application\Import\HomeAssistantImport;
use CameraDiary\HomeAssistant\Application\Import\ImportReport;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('home-assistant:backfill', 'Import older Home Assistant history; the worker describes the events afterwards')]
final readonly class BackfillCommand
{
    public function __construct(
        private HomeAssistantImport $import,
        private HomeTimeZone $homeTimeZone,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('First local day to import, YYYY-MM-DD')]
        string $from = '',
        #[Option('Last local day to import, YYYY-MM-DD (default: today)')]
        string $to = '',
    ): int {
        try {
            $start = $this->localDay($from);
            $end = '' === $to ? $this->clock->now() : $this->localDay($to)->modify('+1 day');
        } catch (Exception $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $end = min($end, $this->clock->now());
        if ($start >= $end) {
            $io->error('--from must lie before --to and in the past.');

            return Command::INVALID;
        }

        try {
            $report = $this->import->backfill($start, $end);
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

    private function localDay(string $day): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $this->homeTimeZone->zone());
        if (false === $date) {
            throw new Exception(\sprintf('"%s" is not a day in the form YYYY-MM-DD.', $day));
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
