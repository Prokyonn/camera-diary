<?php

declare(strict_types=1);

namespace App\UserInterface\Mcp;

use App\Application\Clock\HomeTimeZone;
use DateTimeImmutable;
use Mcp\Exception\ToolCallException;

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

        return $this->homeTimeZone->parseLocal($value) ?? throw new ToolCallException(\sprintf('"%s" is not a valid time.', $value));
    }
}
