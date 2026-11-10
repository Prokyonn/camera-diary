<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\UserInterface\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Consumed with `messenger:consume scheduler_default scheduler_home_assistant`.
 */
#[AsSchedule('home_assistant')]
final readonly class HomeAssistantSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every('1 minute', new ImportHistory()));
    }
}
