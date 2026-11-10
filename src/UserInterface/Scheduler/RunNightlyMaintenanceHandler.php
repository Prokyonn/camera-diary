<?php

declare(strict_types=1);

namespace App\UserInterface\Scheduler;

use App\Application\Maintenance\NightlyMaintenance;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunNightlyMaintenanceHandler
{
    public function __construct(
        private NightlyMaintenance $maintenance,
    ) {
    }

    public function __invoke(RunNightlyMaintenance $message): void
    {
        $this->maintenance->run();
    }
}
