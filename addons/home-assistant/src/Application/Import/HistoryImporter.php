<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Application\Import;

use App\Application\Persistence\UnitOfWorkInterface;
use CameraDiary\HomeAssistant\Application\History\HomeHistoryInterface;
use CameraDiary\HomeAssistant\Application\History\StateChange;
use CameraDiary\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraDiary\HomeAssistant\Application\Settings\PushSettings;
use CameraDiary\HomeAssistant\Application\Settings\SignalMapping;
use CameraDiary\HomeAssistant\Domain\Model\Detection;
use CameraDiary\HomeAssistant\Domain\Model\HomeSignal;
use CameraDiary\HomeAssistant\Domain\Model\ImportCursor;
use CameraDiary\HomeAssistant\Domain\Model\PushHandover;
use CameraDiary\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use CameraDiary\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;
use CameraDiary\HomeAssistant\Domain\Repository\ImportCursorRepositoryInterface;
use CameraDiary\HomeAssistant\Domain\Repository\PushHandoverRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Copies camera detections and context signals from the Home Assistant history into the database.
 */
final readonly class HistoryImporter
{
    private const int CHUNK_SECONDS = 6 * 3600;

    public function __construct(
        private HomeHistoryInterface $history,
        private DetectionRepositoryInterface $detections,
        private HomeSignalRepositoryInterface $signals,
        private ImportCursorRepositoryInterface $cursors,
        private PushHandoverRepositoryInterface $pushHandovers,
        private UnitOfWorkInterface $unitOfWork,
        private LoggerInterface $logger,
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
        $this->importPushHandovers($settings, $from, $to);

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

        $entityIds = array_map(static fn (SignalMapping $signal): string => $signal->entityId, $settings->signals);
        $candidates = [];
        foreach ($this->history->fetch($entityIds, $from, $to, true) as $change) {
            $mapping = $settings->signal($change->entityId);
            if ($mapping instanceof SignalMapping && null !== $mapping->describe($change->state)) {
                $candidates[] = new HomeSignal($change->entityId, $change->state, $this->signalTime($mapping, $change));
            }
        }

        if ([] === $candidates) {
            return 0;
        }

        // A time attribute can lie long before the change that reports it, e.g. when Home Assistant repeats a state after a restart.
        $earliest = min(array_map(static fn (HomeSignal $signal): DateTimeImmutable => $signal->getChangedAt(), $candidates));
        $existing = [];
        foreach ($this->signals->findBy(['changedAtOrAfter' => min($earliest, $from), 'changedAtOrBefore' => $to]) as $signal) {
            $existing[$signal->key()] = true;
        }

        $imported = 0;
        foreach ($candidates as $signal) {
            if (isset($existing[$signal->key()])) {
                continue;
            }

            $this->signals->add($signal);
            $existing[$signal->key()] = true;
            ++$imported;
        }

        return $imported;
    }

    private function importPushHandovers(HomeAssistantSettings $settings, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        if (!$settings->push instanceof PushSettings) {
            return;
        }

        foreach ($this->history->fetch([$settings->push->handoverEntity], $from, $to, false) as $change) {
            if (\in_array($change->state, ['', 'unknown', 'unavailable'], true)) {
                continue;
            }

            $handover = PushHandover::fromHandoverValue($change->state);
            if (!$handover instanceof PushHandover) {
                $this->logger->warning('Ignored the push handover "{value}": expected "camera|epoch|mute 0/1|tag|group|file base|title|message".', ['value' => $change->state]);

                continue;
            }

            if ([] === $this->pushHandovers->findBy(['tag' => $handover->getTag()])) {
                $this->pushHandovers->add($handover);
            }
        }
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
