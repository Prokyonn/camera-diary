<?php

declare(strict_types=1);

namespace App\UserInterface\Scheduler;

use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(param: 'app.timezone')]
        private string $timezone,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 minute', new DescribePendingEvents()))
            ->add(RecurringMessage::cron('30 3 * * *', new RunNightlyMaintenance(), new DateTimeZone($this->timezone)));
    }
}
