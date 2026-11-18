<?php

declare(strict_types=1);

namespace App\UserInterface\Mcp;

use App\Application\Clock\HomeTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Reads a time an assistant sends: ISO 8601, local time if it has no offset; returns UTC.
 */
final readonly class TimeParser
{
    public function __construct(
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    public function parse(string $value): ?DateTimeImmutable
    {
        if ('' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->homeTimeZone->zone())->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception $exception) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid time.', $value), 0, $exception);
        }
    }
}
