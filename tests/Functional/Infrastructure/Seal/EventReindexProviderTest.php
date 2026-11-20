<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Seal;

use App\Domain\Model\CameraEvent;
use App\Infrastructure\Seal\EventReindexProvider;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use CmsIg\Seal\Reindex\ReindexConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(EventReindexProvider::class)]
final class EventReindexProviderTest extends KernelTestCase
{
    use ResetsStorage;
    use StoresEvents;

    public function testProvidesEveryEventOnceAcrossBatches(): void
    {
        self::bootKernel();
        $this->resetStorage(self::getContainer());
        $events = array_map(
            static fn (int $minute): CameraEvent => CameraEventFactory::create(\sprintf('2026-11-06 %02d:%02d:00', intdiv($minute, 60), $minute % 60)),
            range(0, 204),
        );
        $this->storeEvents(self::getContainer(), ...$events);
        $provider = self::getContainer()->get(EventReindexProvider::class);

        $ids = [];
        foreach ($provider->provide(new ReindexConfig()) as $document) {
            self::assertIsString($document['id'] ?? null);
            $ids[] = $document['id'];
        }

        self::assertSame(205, $provider->total());
        self::assertCount(205, array_unique($ids), 'More than one batch of 200, each event once.');
    }
}
