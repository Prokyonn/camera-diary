<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Seal;

use App\Application\Search\EventIndexerInterface;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use App\Infrastructure\Seal\SealEventIndexer;
use App\Infrastructure\Seal\SealEventSearch;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(SealEventSearch::class)]
#[CoversClass(SealEventIndexer::class)]
final class SealEventSearchTest extends KernelTestCase
{
    use ResetsStorage;

    private CameraEvent $greyCat;

    private CameraEvent $luna;

    private CameraEvent $fence;

    private CameraEvent $web;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetStorage(self::getContainer());

        $this->greyCat = CameraEventFactory::described('A grey cat drags the doormat from the cat flap to the door.', '2026-11-06 06:16:40');
        $this->luna = CameraEventFactory::described('A cat sits right in front of the lens.', '2026-11-06 16:53:53', 'garden');
        $this->luna->relabel('Luna', null);
        $this->fence = CameraEventFactory::described('A person in a dark jacket walks along the gravel path.', '2026-11-07 13:55:00', 'garden', subject: 'person', side: Side::Outside);
        $this->web = CameraEventFactory::described('A thin thread swings across the lens.', '2026-11-06 16:36:00', 'garden', Verdict::FalseAlarm, 'nothing', Side::Unclear, 'spider_web');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $indexer = self::getContainer()->get(EventIndexerInterface::class);
        foreach ([$this->greyCat, $this->luna, $this->fence, $this->web] as $event) {
            $entityManager->persist($event->getCamera());
            $entityManager->persist($event);
        }
        $entityManager->flush();
        foreach ([$this->greyCat, $this->luna, $this->fence, $this->web] as $event) {
            $indexer->index($event);
        }
    }

    public function testFindsEventsByDescription(): void
    {
        $events = $this->search(new SearchCriteria(text: 'grey cat doormat'));

        self::assertSame($this->greyCat, $events[0] ?? null, 'Loupe matches any term and ranks the best match first.');
        self::assertNotContains($this->fence, $events);
    }

    public function testSearchesWithAWholeQuestion(): void
    {
        $found = $this->search(new SearchCriteria(text: 'When was the strange grey cat with the doormat here again, and did it come through the flap at night?'));

        self::assertSame($this->greyCat, $found[0] ?? null, 'Eight or more words with a filter used to break Loupe.');
        self::assertSame('strange grey cat doormat here again', SealEventSearch::keywords('When was the strange grey cat with the doormat here again, and did it come?'));
    }

    public function testFindsEventsByManualLabel(): void
    {
        self::assertSame([$this->luna], $this->search(new SearchCriteria(text: 'Luna')));
    }

    public function testFiltersBySideAndCamera(): void
    {
        self::assertSame([$this->fence], $this->search(new SearchCriteria(cameras: ['garden'], side: Side::Outside)));
    }

    public function testHidesFalseAlarmsUnlessAsked(): void
    {
        self::assertSame([], $this->search(new SearchCriteria(text: 'spider web')));
        self::assertSame([$this->web], $this->search(new SearchCriteria(text: 'spider web', includeFalseAlarms: true)));
    }

    public function testListsNewestFirstWithinATimeRange(): void
    {
        $events = $this->search(new SearchCriteria(
            from: CameraEventFactory::utc('2026-11-06 00:00:00'),
            to: CameraEventFactory::utc('2026-11-06 23:59:59'),
        ));

        self::assertSame([$this->luna, $this->greyCat], $events);
    }

    /**
     * @return list<CameraEvent>
     */
    private function search(SearchCriteria $criteria): array
    {
        return self::getContainer()->get(EventSearchInterface::class)->search($criteria);
    }
}
