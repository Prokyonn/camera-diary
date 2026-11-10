<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Clock\HomeTimeZone;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\DetectionType;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\UserInterface\Form\TimelineFilter;
use App\UserInterface\Form\TimelineFilterType;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @phpstan-import-type CameraEventFilters from CameraEventRepositoryInterface
 */
final class TimelineController extends AbstractController
{
    public function __construct(
        private readonly CameraEventRepositoryInterface $events,
        private readonly HomeTimeZone $homeTimeZone,
        private readonly AiCallBudgetInterface $budget,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/', name: 'timeline', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $today = $this->homeTimeZone->toLocal($this->clock->now())->setTime(0, 0);
        // The date field shows the model's date in UTC, so hand it the local calendar date as a UTC date.
        $filter = new TimelineFilter(day: new DateTimeImmutable($today->format('Y-m-d'), new DateTimeZone('UTC')));
        $form = $this->createForm(TimelineFilterType::class, $filter);
        $form->handleRequest($request);

        $day = $today;
        if ($form->isSubmitted() && $form->isValid() && $filter->day instanceof DateTimeImmutable) {
            $day = new DateTimeImmutable($filter->day->format('Y-m-d'), $this->homeTimeZone->zone());
        }

        [$from, $to] = $this->homeTimeZone->dayBounds($day);
        $filters = ['startedAtOrAfter' => $from, 'startedBefore' => $to];
        if (null !== $filter->camera) {
            $filters['cameraSlug'] = $filter->camera;
        }
        if ($filter->type instanceof DetectionType) {
            $filters['detectionType'] = $filter->type;
        }
        if (!$filter->falseAlarms) {
            $filters['falseAlarm'] = false;
        }

        $events = $this->events->findBy($filters, ['startedAt' => 'desc']);

        return $this->render('timeline/index.html.twig', [
            'form' => $form,
            'day' => $day,
            'isToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            'filterQuery' => array_filter([
                'camera' => $filter->camera,
                'type' => $filter->type?->value,
                'falseAlarms' => $filter->falseAlarms ? '1' : null,
            ], static fn (?string $value): bool => null !== $value),
            'events' => $events,
            'latestBefore' => [] === $events ? $this->latestBefore($filters, $from) : null,
            'aiCallsToday' => $this->budget->usedToday(),
            'aiDailyLimit' => $this->budget->dailyLimit(),
        ]);
    }

    /**
     * The latest earlier event with the same filters, so an empty day can point to it.
     *
     * @param CameraEventFilters $filters
     */
    private function latestBefore(array $filters, DateTimeImmutable $from): ?CameraEvent
    {
        unset($filters['startedAtOrAfter']);
        $filters['startedBefore'] = $from;

        return $this->events->findOneBy($filters, ['startedAt' => 'desc']);
    }
}
