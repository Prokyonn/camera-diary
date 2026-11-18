<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Mcp;

use App\Domain\Model\Side;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Mcp\CameraEventTools;
use App\UserInterface\Mcp\EventPresenter;
use InvalidArgumentException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
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
        $fence = CameraEventFactory::described('A person in a dark jacket walks along the gravel path.', '2026-11-07 13:55:00', 'garden', subject: 'person', side: Side::Outside);
        $this->storeEvents(self::getContainer(), $fence, CameraEventFactory::described('A grey cat.'));

        $results = $this->tools->search(query: 'person gravel path', camera: 'garden', from: '2026-11-07T00:00:00');

        self::assertSame(1, $results['total']);
        $result = $results['events'][0] ?? self::fail('No event found.');
        self::assertSame('2026-11-07T14:55:00+01:00', $result['started_at'] ?? null);
        self::assertSame('outside', $result['side'] ?? null);
        self::assertSame('http://camera-diary.test/events/'.$fence->getId(), $result['url'] ?? null);
    }

    public function testFiltersByKnownIndividual(): void
    {
        $luna = CameraEventFactory::described('A tabby cat on the wall.', '2026-11-07 13:55:00', individual: 'luna');
        $this->storeEvents(self::getContainer(), $luna, CameraEventFactory::described('A grey cat.'));

        $results = $this->tools->search(subject: 'cat', individual: 'luna');

        self::assertSame(1, $results['total']);
        self::assertSame('luna', $results['events'][0]['individual'] ?? null);
    }

    public function testGetsOneEventWithItsFrames(): void
    {
        $event = CameraEventFactory::described('A grey cat.');
        $this->storeEvents(self::getContainer(), $event);

        $content = $this->tools->get($event->getId());

        self::assertCount(2, $content);
        [$text, $image] = $content;
        self::assertInstanceOf(TextContent::class, $text);
        self::assertIsString($text->text);
        self::assertStringContainsString('"summary": "A grey cat."', $text->text);
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/jpeg', $image->mimeType);
        self::assertCount(1, $this->tools->get($event->getId(), includeFrames: false));
    }

    public function testRejectsUnknownCamerasAndEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tools->search(camera: 'kitchen');
    }
}
