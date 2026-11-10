<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\UserInterface\Scheduler;

use CameraEvents\HomeAssistant\Application\History\HomeHistoryUnavailableException;
use CameraEvents\HomeAssistant\Application\Import\HomeAssistantImport;
use CameraEvents\HomeAssistant\Application\Import\ImportReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ImportHistoryHandler
{
    public function __construct(
        private HomeAssistantImport $import,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportHistory $message): void
    {
        try {
            $report = $this->import->run();
        } catch (HomeHistoryUnavailableException $exception) {
            $this->logger->warning('Home Assistant import skipped: {message}', ['message' => $exception->getMessage()]);

            return;
        }

        if ($report instanceof ImportReport) {
            $this->logger->info('Home Assistant import: '.$report->summary());
        }
    }
}
