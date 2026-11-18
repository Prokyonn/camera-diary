<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Clock\HomeTimeZone;
use App\Application\Digest\DayDigests;
use App\Application\Digest\DigestNotWrittenException;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\UserInterface\Form\TimelineFilter;
use App\UserInterface\Form\TimelineFilterType;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        private readonly SettingsProviderInterface $settings,
        private readonly ClockInterface $clock,
        private readonly DayDigests $digests,
    ) {
    }

    #[Route('/', name: 'timeline', methods: ['GET'])]
    public function timeline(Request $request): Response
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
        if (null !== $filter->type) {
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
                'type' => $filter->type,
                'falseAlarms' => $filter->falseAlarms ? '1' : null,
            ], static fn (?string $value): bool => null !== $value),
            'events' => $events,
            'latestBefore' => [] === $events ? $this->latestBefore($filters, $from) : null,
            'aiUsage' => $this->aiUsage(),
            'digest' => $this->digests->of($day),
            'describedCount' => $this->digests->eventCount($day),
        ]);
    }

    #[Route('/days/{day}/digest', name: 'day_digest', requirements: ['day' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function digest(string $day, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('day-digest', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->digests->write(new DateTimeImmutable($day, $this->homeTimeZone->zone()));
        } catch (DigestNotWrittenException $exception) {
            $this->addFlash('success', $exception->getMessage());
        }

        return $this->redirectToRoute('timeline', ['day' => $day], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return list<array{label: string, used: int, limit: int}> today's AI calls in total, then per call pool
     */
    private function aiUsage(): array
    {
        $ai = $this->settings->settings()->ai;
        $usage = [['label' => 'AI calls today', 'used' => $this->budget->usedToday(), 'limit' => $ai->dailyCallLimit]];
        foreach ($ai->callPools as $pool) {
            $usage[] = ['label' => $pool->label(), 'used' => $this->budget->usedToday($pool), 'limit' => $pool->dailyCallLimit];
        }

        return $usage;
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
