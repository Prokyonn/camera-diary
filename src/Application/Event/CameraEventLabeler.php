<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\Individual;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use Psr\Clock\ClockInterface;

/**
 * Saves the owner's correction or confirmation and makes search see it at once.
 */
final readonly class CameraEventLabeler
{
    public function __construct(
        private UnitOfWorkInterface $unitOfWork,
        private EventIndexerInterface $indexer,
        private ClockInterface $clock,
        private SettingsProviderInterface $settings,
    ) {
    }

    /**
     * @throws InvalidCorrectionException if the individual or the cause is not in the settings; values the event already has stay allowed
     */
    public function relabel(CameraEvent $event, ?string $label, ?Verdict $verdictOverride, ?string $individualOverride, ?string $falseAlarmCauseOverride): void
    {
        $this->assertKnownIndividual($event, $individualOverride);
        $this->assertKnownCause($event, $falseAlarmCauseOverride);
        $event->relabel($label, $verdictOverride, $individualOverride, $falseAlarmCauseOverride);
        $this->saveReviewed($event);
    }

    /**
     * The AI's answer is right as it is.
     */
    public function confirm(CameraEvent $event): void
    {
        $this->saveReviewed($event);
    }

    private function assertKnownIndividual(CameraEvent $event, ?string $name): void
    {
        if (null === $name || Individual::UNKNOWN === $name || $name === $event->getIndividualOverride()) {
            return;
        }

        if (!$this->settings->settings()->individual($name) instanceof Individual) {
            throw new InvalidCorrectionException(\sprintf('"%s" is no known individual; list_known_individuals names them.', $name));
        }
    }

    private function assertKnownCause(CameraEvent $event, ?string $name): void
    {
        if (null === $name || $name === $event->getFalseAlarmCauseOverride()) {
            return;
        }

        $causes = $this->settings->settings()->falseAlarmCauses;
        if (!$causes->has($name)) {
            throw new InvalidCorrectionException(\sprintf('"%s" is no false-alarm cause. Known: %s.', $name, implode(', ', $causes->names())));
        }
    }

    private function saveReviewed(CameraEvent $event): void
    {
        $event->markReviewed($this->clock->now());
        $this->unitOfWork->flush();
        $this->indexer->index($event);
    }
}
