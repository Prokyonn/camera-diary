<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Mcp;

use App\Domain\Model\Side;
use App\Domain\Model\Subject;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Mcp\CameraEventTools;
use App\UserInterface\Mcp\EventPresenter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(CameraEventTools::class)]
#[CoversClass(EventPresenter::class)]
final class CameraEventToolsTest extends KernelTestCase
{
    use ResetsStorage;
    use StoresEvents;

    private CameraEventTools $tools;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->tools = self::getContainer()->get(CameraEventTools::class);
    }

    public function testSearchesEventsWithLocalTimesAndLinks(): void
    {
        $fence = CameraEventFactory::described('A person in a dark jacket walks along the gravel path.', '2026-11-07 13:55:00', 'garden', subject: Subject::Person, side: Side::Outside);
        $this->storeEvents(self::getContainer(), $fence, CameraEventFactory::described('A grey cat.'));

        $results = $this->tools->search(query: 'person gravel path', camera: 'garden', from: '2026-11-07T00:00:00');

        self::assertCount(1, $results);
        $result = $results[0];
        self::assertSame('2026-11-07T14:55:00+01:00', $result['started_at'] ?? null);
        self::assertSame('outside', $result['side'] ?? null);
        self::assertSame('http://camera-events.test/events/'.$fence->getId(), $result['url'] ?? null);
    }

    public function testGetsOneEvent(): void
    {
        $event = CameraEventFactory::described('A grey cat.');
        $this->storeEvents(self::getContainer(), $event);

        self::assertSame('A grey cat.', $this->tools->get($event->getId())['summary'] ?? null);
    }

    public function testRejectsUnknownCamerasAndEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tools->search(camera: 'kitchen');
    }
}
