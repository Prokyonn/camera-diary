<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event;

use App\Application\Event\DeepLinkResolver;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(DeepLinkResolver::class)]
final class DeepLinkResolverTest extends TestCase
{
    use ProphecyTrait;

    public function testPicksTheEventThatStartedClosestToThePush(): void
    {
        $at = CameraEventFactory::utc('2026-11-07 13:55:00');
        $early = CameraEventFactory::create('2026-11-07 13:53:30', 'garden', id: 1);
        $close = CameraEventFactory::create('2026-11-07 13:55:20', 'garden', id: 2);
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy([
            'cameraSlug' => 'garden',
            'startedAtOrBefore' => CameraEventFactory::utc('2026-11-07 13:57:00'),
            'endedAtOrAfter' => CameraEventFactory::utc('2026-11-07 13:53:00'),
        ])->willReturn([$early, $close]);

        self::assertSame($close, new DeepLinkResolver($events->reveal())->resolve('garden', $at));
    }

    public function testReturnsNullBeforeTheEventIsImported(): void
    {
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy([
            'cameraSlug' => 'terrace',
            'startedAtOrBefore' => CameraEventFactory::utc('2026-11-07 09:42:00'),
            'endedAtOrAfter' => CameraEventFactory::utc('2026-11-07 09:38:00'),
        ])->willReturn([]);

        self::assertNull(new DeepLinkResolver($events->reveal())->resolve('terrace', CameraEventFactory::utc('2026-11-07 09:40:00')));
    }
}
