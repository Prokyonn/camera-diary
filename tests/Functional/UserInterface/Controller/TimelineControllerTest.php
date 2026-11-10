<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Domain\Model\Verdict;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\TimelineController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(TimelineController::class)]
final class TimelineControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use ResetsStorage;
    use StoresEvents;

    public function testShowsTheDayWithoutFalseAlarms(): void
    {
        self::mockTime('2026-11-07 20:00:00');
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $this->storeEvents(
            self::getContainer(),
            CameraEventFactory::described('A person walks along the gravel path.', '2026-11-07 13:55:00', 'garden'),
            CameraEventFactory::described('A web thread.', '2026-11-07 03:00:00', 'garden', Verdict::FalseAlarm),
            CameraEventFactory::create('2026-11-06 06:16:40'),
        );

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Saturday, 7 November 2026');
        self::assertInputValueSame('day', '2026-11-07');
        self::assertCount(1, $crawler->filter('.cards article'));
        self::assertSelectorTextContains('.cards article', '14:55:00', 'Times are shown in Vienna time.');
        self::assertSelectorTextContains('.cards article', 'A person walks along the gravel path.');
    }

    public function testFiltersByDayAndShowsFalseAlarmsOnRequest(): void
    {
        self::mockTime('2026-11-08 10:00:00');
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $this->storeEvents(
            self::getContainer(),
            CameraEventFactory::described('A web thread.', '2026-11-07 03:00:00', 'garden', Verdict::FalseAlarm),
        );

        $crawler = $client->request('GET', '/', ['day' => '2026-11-07', 'falseAlarms' => '1']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cards article.is-false-alarm'));
        self::assertSelectorExists('a[rel="next"]');
    }

    public function testPointsAnEmptyDayToTheLatestEvents(): void
    {
        self::mockTime('2026-11-08 10:00:00');
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $this->storeEvents(
            self::getContainer(),
            CameraEventFactory::described('A badger on the path.', '2026-11-06 20:40:00', 'garden'),
            CameraEventFactory::described('A web thread.', '2026-11-07 03:00:00', 'garden', Verdict::FalseAlarm),
        );

        $client->request('GET', '/');

        self::assertSelectorTextContains('.empty', 'The latest ones are from Friday, 6 November.');
        self::assertSelectorExists('.empty a[href="/?day=2026-11-06"]', 'Hidden false alarms do not count.');
    }
}
