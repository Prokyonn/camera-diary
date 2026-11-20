<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Ai\AiUsage;
use App\Application\Clock\HomeTimeZone;
use App\Application\Digest\DayDigests;
use App\Application\Digest\DigestNotWrittenException;
use App\Application\Timeline\Timeline;
use App\Domain\Model\CameraEvent;
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

final class TimelineController extends AbstractController
{
    public function __construct(
        private readonly Timeline $timeline,
        private readonly HomeTimeZone $homeTimeZone,
        private readonly AiUsage $aiUsage,
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

        $timelineDay = $this->timeline->day($day, $filter->camera, $filter->type, $filter->falseAlarms);
        if ($timelineDay->latestBefore instanceof CameraEvent && !$request->query->has('day')) {
            // A quiet today opens on the latest day with events; "Next day" still leads to today.
            return $this->redirectToRoute('timeline', [...$request->query->all(), 'day' => $this->homeTimeZone->toLocal($timelineDay->latestBefore->getStartedAt())->format('Y-m-d')]);
        }

        return $this->render('timeline/index.html.twig', [
            'form' => $form,
            'day' => $day,
            'isToday' => $day->format('Y-m-d') === $today->format('Y-m-d'),
            'filterQuery' => array_filter([
                'camera' => $filter->camera,
                'type' => $filter->type,
                'falseAlarms' => $filter->falseAlarms ? '1' : null,
            ], static fn (?string $value): bool => null !== $value),
            'events' => $timelineDay->events,
            'latestBefore' => $timelineDay->latestBefore,
            'aiUsage' => $this->aiUsage->today(),
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
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('timeline', ['day' => $day], Response::HTTP_SEE_OTHER);
    }
}
