<?php

declare(strict_types=1);

namespace App\Application\Digest;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\DayDigest;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\DayDigestRepositoryInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The AI's summary of a day, written on request.
 */
final readonly class DayDigests
{
    /** More events than one day of home cameras brings; the summary reads them as text only. */
    private const int MAX_EVENTS = 500;

    public function __construct(
        private DayDigestRepositoryInterface $digests,
        private CameraEventRepositoryInterface $events,
        private DigestWriterInterface $writer,
        private AiCallBudgetInterface $budget,
        private UnitOfWorkInterface $unitOfWork,
        private HomeTimeZone $homeTimeZone,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function of(DateTimeImmutable $day): ?DayDigest
    {
        return $this->digests->findOneBy(['day' => $this->homeTimeZone->toLocal($day)->format('Y-m-d')]);
    }

    /**
     * How many described events the day has; a digest with another count is out of date.
     */
    public function eventCount(DateTimeImmutable $day): int
    {
        [$from, $to] = $this->homeTimeZone->dayBounds($day);

        return $this->events->countBy(['startedAtOrAfter' => $from, 'startedBefore' => $to, 'status' => EventStatus::Described]);
    }

    /**
     * @throws DigestNotWrittenException when the day has no described events, the daily AI limit is reached, or the AI fails
     */
    public function write(DateTimeImmutable $day): DayDigest
    {
        [$from, $to] = $this->homeTimeZone->dayBounds($day);
        $events = $this->events->findBy(['startedAtOrAfter' => $from, 'startedBefore' => $to, 'status' => EventStatus::Described], ['startedAt' => 'asc'], self::MAX_EVENTS);
        if ([] === $events) {
            throw new DigestNotWrittenException('There are no described events on this day.');
        }

        if (!$this->budget->tryConsume()) {
            throw new DigestNotWrittenException('The daily AI limit is reached; try again tomorrow.');
        }

        try {
            $text = $this->writer->summarize($this->homeTimeZone->toLocal($from), $events);
        } catch (AiRequestFailedException $exception) {
            $this->logger->warning('The AI could not summarize the day: {message}', ['message' => $exception->getMessage()]);

            throw new DigestNotWrittenException('The AI could not write the summary right now.', 0, $exception);
        }

        return $this->save($from, $text, \count($events), $this->writer->modelName());
    }

    /**
     * Stores a summary written elsewhere without an AI call, e.g. one recorded from a real run for the demo.
     */
    public function record(DateTimeImmutable $day, string $text, string $model): DayDigest
    {
        return $this->save($day, $text, $this->eventCount($day), $model);
    }

    private function save(DateTimeImmutable $day, string $text, int $eventCount, string $model): DayDigest
    {
        $digest = $this->of($day);
        if ($digest instanceof DayDigest) {
            $digest->rewrite($text, $eventCount, $model, $this->clock->now());
        } else {
            $digest = new DayDigest($this->homeTimeZone->toLocal($day)->format('Y-m-d'), $text, $eventCount, $model, $this->clock->now());
            $this->digests->add($digest);
        }

        $this->unitOfWork->flush();

        return $digest;
    }
}
