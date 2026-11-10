<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Model\CameraEvent;

interface EventIndexerInterface
{
    public function index(CameraEvent $event): void;
}
