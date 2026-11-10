<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\Import;

use CameraEvents\HomeAssistant\Application\History\HomeHistoryUnavailableException;
use CameraEvents\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraEvents\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraEvents\HomeAssistant\Domain\Model\ImportCursor;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * One run: import new history, group the detections into events and close the settled ones. The core worker
 * then cuts their frames from the recordings and describes them.
 */
final readonly class HomeAssistantImport
{
    public const int OVERLAP_SECONDS = 300;
    public const int SETTLE_SECONDS = 30;
    public const int FIRST_RUN_LOOKBACK_SECONDS = 3600;
    private const int LOCK_TTL_SECONDS = 1800;

    public function __construct(
        private SettingsProviderInterface $settings,
        private HistoryImporter $importer,
        private EventGrouper $grouper,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return ImportReport|null null when the add-on is not configured or another run holds the lock
     *
     * @throws HomeHistoryUnavailableException
     */
    public function run(): ?ImportReport
    {
        $settings = $this->settings->settings();
        if ($settings->isEmpty()) {
            return null;
        }

        return $this->locked(function () use ($settings): ImportReport {
            $now = $this->clock->now();

            return $this->importAndGroup($settings, $this->importStart($now), $now->modify(\sprintf('-%d seconds', self::SETTLE_SECONDS)));
        });
    }

    /**
     * @throws HomeHistoryUnavailableException
     */
    public function backfill(DateTimeImmutable $from, DateTimeImmutable $to): ?ImportReport
    {
        $settings = $this->settings->settings();
        if ($settings->isEmpty()) {
            return null;
        }

        return $this->locked(fn (): ImportReport => $this->importAndGroup($settings, $from, $to));
    }

    private function importAndGroup(HomeAssistantSettings $settings, DateTimeImmutable $from, DateTimeImmutable $to): ImportReport
    {
        $import = $this->importer->importRange($settings, $from, $to);
        $newEvents = $this->grouper->groupUnassigned($settings);
        $cursor = $this->importer->cursor();

        return new ImportReport(
            $import,
            $newEvents,
            $cursor instanceof ImportCursor ? $this->grouper->closeSettled($cursor->getImportedUntil()) : 0,
        );
    }

    private function importStart(DateTimeImmutable $now): DateTimeImmutable
    {
        $cursor = $this->importer->cursor();
        if (!$cursor instanceof ImportCursor) {
            return $now->modify(\sprintf('-%d seconds', self::FIRST_RUN_LOOKBACK_SECONDS));
        }

        return $cursor->getImportedUntil()->modify(\sprintf('-%d seconds', self::OVERLAP_SECONDS));
    }

    /**
     * @param callable(): ImportReport $work
     */
    private function locked(callable $work): ?ImportReport
    {
        $lock = $this->lockFactory->createLock('home-assistant-import', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
