<?php

declare(strict_types=1);

namespace App\Application\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Times are stored in UTC; this converts to and from the home time zone.
 */
final readonly class HomeTimeZone
{
    private DateTimeZone $zone;

    public function __construct(#[Autowire(param: 'app.timezone')] string $name)
    {
        $this->zone = new DateTimeZone($name);
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }

    public function toLocal(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone($this->zone);
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable} start (inclusive) and end (exclusive) of the local day, in UTC
     */
    public function dayBounds(DateTimeImmutable $day): array
    {
        $start = $this->toLocal($day)->setTime(0, 0);
        $utc = new DateTimeZone('UTC');

        return [$start->setTimezone($utc), $start->modify('+1 day')->setTimezone($utc)];
    }
}
