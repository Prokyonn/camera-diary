<?php

declare(strict_types=1);

namespace App\Application\Description;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Frame\FootageNotFoundException;
use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameSourceInterface;
use App\Application\Frame\FrameSources;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\CallPool;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes one closed event from pending to described: frames first (from its add-on, if it came without), then the AI
 * description.
 */
final readonly class EventProcessor
{
    public function __construct(
        private FrameSources $frameSources,
        private FrameStorageInterface $frameStorage,
        private EventDescriberInterface $describer,
        private AiCallBudgetInterface $budget,
        private HintCollector $hints,
        private RepeatedFalseAlarms $repeatedFalseAlarms,
        private EventIndexerInterface $indexer,
        private UnitOfWorkInterface $unitOfWork,
        private EventDispatcherInterface $eventDispatcher,
        private SettingsProviderInterface $settings,
        private ClockInterface $clock,
        private HomeTimeZone $homeTimeZone,
        private LoggerInterface $logger,
    ) {
    }

    public function process(CameraEvent $event): ProcessOutcome
    {
        if ($event->isOpen()) {
            return ProcessOutcome::Open;
        }

        try {
            $outcome = $this->advance($event);
        } catch (Throwable $exception) {
            // One broken event (a frame or reference photo gone, an unexpected answer) must not block the queue.
            $this->logger->error('Event {id} could not be processed: {message}', ['id' => $event->getId(), 'message' => $exception->getMessage(), 'exception' => $exception]);
            $outcome = $this->retryOrFail($event);
        }
        $this->unitOfWork->flush();
        $this->indexer->index($event);
        if (ProcessOutcome::Described === $outcome) {
            $this->eventDispatcher->dispatch(new EventDescribed($event));
        }

        return $outcome;
    }

    private function advance(CameraEvent $event): ProcessOutcome
    {
        if ([] === $event->getFramePaths()) {
            $outcome = $this->grabFrames($event);
            if ($outcome instanceof ProcessOutcome) {
                return $outcome;
            }
        }

        $checkedOriginal = $this->repeatedFalseAlarms->checkedOriginalOf($event);
        if ($checkedOriginal instanceof CameraEvent) {
            $event->describeAsRepeatOf($checkedOriginal, $this->clock->now());

            return ProcessOutcome::Described;
        }

        $pool = $this->settings->settings()->ai->callPoolFor($event->getDetectionTypes());
        if (!$this->budget->tryConsume($pool)) {
            if ($pool instanceof CallPool && $this->budget->hasCallsLeftToday()) {
                // Only this pool is used up: wait for tomorrow's calls, so its events do not crowd out the other pools'.
                $event->postponeUntil($this->homeTimeZone->dayBounds($this->clock->now())[1]);
            }

            return ProcessOutcome::OverBudget;
        }

        return $this->describe($event);
    }

    /**
     * @return ProcessOutcome|null null when all frames were stored
     */
    private function grabFrames(CameraEvent $event): ?ProcessOutcome
    {
        $source = $this->frameSources->forEvent($event);
        if (!$source instanceof FrameSourceInterface) {
            $event->markNoFootage();

            return ProcessOutcome::NoFootage;
        }

        $paths = [];
        $size = [0, 0];
        foreach ($this->settings->settings()->description->frameOffsets as $index => $offset) {
            $relativePath = $this->frameStorage->relativePathFor($event, $index);
            try {
                $source->grab($event, $event->getStartedAt()->modify(\sprintf('+%d seconds', $offset)), $this->frameStorage->prepare($relativePath));
                if (0 === $index) {
                    $size = $this->frameStorage->imageSize($relativePath);
                }
            } catch (FootageNotFoundException) {
                $this->frameStorage->remove($paths);

                return $this->waitForFootage($event);
            } catch (FrameExtractionFailedException $exception) {
                $this->logger->warning('Frame extraction failed for event {id}: {message}', ['id' => $event->getId(), 'message' => $exception->getMessage()]);
                $this->frameStorage->remove($paths);

                return $this->retryOrFail($event);
            }

            $paths[] = $relativePath;
        }

        $event->recordFrames($paths, ...$size);

        return null;
    }

    private function describe(CameraEvent $event): ProcessOutcome
    {
        try {
            $description = $this->describer->describe($event, $this->hints->hintsFor($event));
        } catch (AiRequestFailedException $exception) {
            $this->logger->warning('The AI could not describe event {id}: {message}', ['id' => $event->getId(), 'message' => $exception->getMessage()]);

            return $this->retryOrFail($event);
        }

        $event->describe(
            $description->verdict,
            $description->falseAlarmCause,
            $description->side,
            $description->subject,
            $description->individual,
            $description->summary,
            $description->unusual,
            $description->confidence,
            $description->model,
            $this->clock->now(),
            $description->alsoSeen,
        );

        return ProcessOutcome::Described;
    }

    private function waitForFootage(CameraEvent $event): ProcessOutcome
    {
        $age = $this->clock->now()->getTimestamp() - $event->getStartedAt()->getTimestamp();
        if ($age > $this->settings->settings()->description->footageGraceSeconds) {
            $event->markNoFootage();

            return ProcessOutcome::NoFootage;
        }

        $this->retryLater($event);

        return ProcessOutcome::Waiting;
    }

    private function retryOrFail(CameraEvent $event): ProcessOutcome
    {
        if ($event->getAttempts() + 1 >= $this->settings->settings()->description->maxAttempts) {
            $event->markFailed();

            return ProcessOutcome::Failed;
        }

        $this->retryLater($event);

        return ProcessOutcome::Waiting;
    }

    private function retryLater(CameraEvent $event): void
    {
        $delay = $this->settings->settings()->description->retryDelaySeconds;
        $event->retryAt($this->clock->now()->modify(\sprintf('+%d seconds', $delay)));
    }
}
