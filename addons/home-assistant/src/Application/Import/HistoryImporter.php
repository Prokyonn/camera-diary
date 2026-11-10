<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Application\Import;

use App\Application\Persistence\UnitOfWorkInterface;
use CameraEvents\HomeAssistant\Application\History\HomeHistoryInterface;
use CameraEvents\HomeAssistant\Application\History\StateChange;
use CameraEvents\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraEvents\HomeAssistant\Application\Settings\SignalMapping;
use CameraEvents\HomeAssistant\Domain\Model\Detection;
use CameraEvents\HomeAssistant\Domain\Model\HomeSignal;
use CameraEvents\HomeAssistant\Domain\Model\ImportCursor;
use CameraEvents\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use CameraEvents\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;
use CameraEvents\HomeAssistant\Domain\Repository\ImportCursorRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Copies camera detections and context signals from the Home Assistant history into the database.
 */
final readonly class HistoryImporter
{
    private const int CHUNK_SECONDS = 6 * 3600;

    /** A signal's time attribute can lie a few minutes before the state change that reports it. */
    private const int SIGNAL_LOOKBACK_SECONDS = 600;

    public function __construct(
        private HomeHistoryInterface $history,
        private DetectionRepositoryInterface $detections,
        private HomeSignalRepositoryInterface $signals,
        private ImportCursorRepositoryInterface $cursors,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    public function importRange(HomeAssistantSettings $settings, DateTimeImmutable $from, DateTimeImmutable $to): ImportResult
    {
        $result = new ImportResult();
        $chunkStart = $from;
        while ($chunkStart < $to) {
            $chunkEnd = min($to, $chunkStart->modify(\sprintf('+%d seconds', self::CHUNK_SECONDS)));
            $result = $result->add($this->importWindow($settings, $chunkStart, $chunkEnd));
            $chunkStart = $chunkEnd;
        }

        return $result;
    }

    public function cursor(): ?ImportCursor
    {
        return $this->cursors->findOneBy(['name' => ImportCursor::HOME_ASSISTANT]);
    }

    private function importWindow(HomeAssistantSettings $settings, DateTimeImmutable $from, DateTimeImmutable $to): ImportResult
    {
        $result = new ImportResult(
            $this->importDetections($settings, $from, $to),
            $this->importSignals($settings, $from, $to),
        );

        $cursor = $this->cursor();
        if ($cursor instanceof ImportCursor) {
            $cursor->advanceTo($to);
        } else {
            $this->cursors->add(new ImportCursor(ImportCursor::HOME_ASSISTANT, $to));
        }

        $this->unitOfWork->flush();

        return $result;
    }

    private function importDetections(HomeAssistantSettings $settings, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $sensors = $settings->detectionSensors();
        if ([] === $sensors) {
            return 0;
        }

        $existing = [];
        foreach ($this->detections->findBy(['detectedAtOrAfter' => $from, 'detectedAtOrBefore' => $to]) as $detection) {
            $existing[$detection->key()] = true;
        }

        $imported = 0;
        foreach ($this->history->fetch(array_keys($sensors), $from, $to, false) as $change) {
            if ('on' !== $change->state || !isset($sensors[$change->entityId])) {
                continue;
            }

            [$camera, $type] = $sensors[$change->entityId];
            $key = Detection::keyOf($camera->slug, $type, $change->changedAt);
            if (isset($existing[$key])) {
                continue;
            }

            $this->detections->add(new Detection($camera->slug, $type, $change->changedAt));
            $existing[$key] = true;
            ++$imported;
        }

        return $imported;
    }

    private function importSignals(HomeAssistantSettings $settings, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ([] === $settings->signals) {
            return 0;
        }

        $existing = [];
        $stored = $this->signals->findBy([
            'changedAtOrAfter' => $from->modify(\sprintf('-%d seconds', self::SIGNAL_LOOKBACK_SECONDS)),
            'changedAtOrBefore' => $to,
        ]);
        foreach ($stored as $signal) {
            $existing[$signal->key()] = true;
        }

        $entityIds = array_map(static fn (SignalMapping $signal): string => $signal->entityId, $settings->signals);
        $imported = 0;
        foreach ($this->history->fetch($entityIds, $from, $to, true) as $change) {
            $mapping = $settings->signal($change->entityId);
            if (!$mapping instanceof SignalMapping || null === $mapping->describe($change->state)) {
                continue;
            }

            $changedAt = $this->signalTime($mapping, $change);
            $key = HomeSignal::keyOf($change->entityId, $change->state, $changedAt);
            if (isset($existing[$key])) {
                continue;
            }

            $this->signals->add(new HomeSignal($change->entityId, $change->state, $changedAt));
            $existing[$key] = true;
            ++$imported;
        }

        return $imported;
    }

    /**
     * Some entities report late (a cat flap lags one to three minutes); their time attribute holds the real time.
     */
    private function signalTime(SignalMapping $mapping, StateChange $change): DateTimeImmutable
    {
        $value = null !== $mapping->timeAttribute ? ($change->attributes[$mapping->timeAttribute] ?? null) : null;
        if (!\is_string($value)) {
            return $change->changedAt;
        }

        try {
            $time = new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return $change->changedAt;
        }

        return $time->setTime((int) $time->format('H'), (int) $time->format('i'), (int) $time->format('s'));
    }
}
