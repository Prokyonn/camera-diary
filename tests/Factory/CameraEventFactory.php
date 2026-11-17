<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Model\CameraEvent;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionProperty;

final class CameraEventFactory
{
    public static function create(
        string $startedAt = '2026-11-06 06:16:40',
        string $camera = 'terrace',
        string $type = 'animal',
        ?int $id = null,
        string $source = 'api',
        bool $open = false,
        ?string $externalId = null,
    ): CameraEvent {
        $event = new CameraEvent(CameraFactory::get($camera), self::utc($startedAt), [$type], $source, $externalId, open: $open);
        if (null !== $id) {
            new ReflectionProperty(CameraEvent::class, 'id')->setValue($event, $id);
        }

        return $event;
    }

    public static function withFrames(
        string $startedAt = '2026-11-06 06:16:40',
        string $camera = 'terrace',
        ?int $id = null,
    ): CameraEvent {
        $event = self::create($startedAt, $camera, id: $id);
        $event->recordFrames([\sprintf('%s/frame-%s-0.jpg', $camera, self::utc($startedAt)->format('U'))], 8, 4);

        return $event;
    }

    public static function described(
        string $summary,
        string $startedAt = '2026-11-06 06:16:40',
        string $camera = 'terrace',
        Verdict $verdict = Verdict::Real,
        string $subject = 'cat',
        Side $side = Side::OurProperty,
        ?string $falseAlarmCause = null,
        ?int $id = null,
        ?string $individual = null,
    ): CameraEvent {
        $event = self::withFrames($startedAt, $camera, $id);
        $event->describe($verdict, $falseAlarmCause, $side, $subject, $individual, $summary, false, 'test-model', self::utc($startedAt)->modify('+5 minutes'));

        return $event;
    }

    public static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
