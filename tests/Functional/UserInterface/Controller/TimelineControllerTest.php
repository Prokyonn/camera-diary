<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Application\Digest\DayDigests;
use App\Domain\Model\Verdict;
use App\Infrastructure\Ai\AiDigestWriter;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\FakePlatform;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\TimelineController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(TimelineController::class)]
#[CoversClass(DayDigests::class)]
#[CoversClass(AiDigestWriter::class)]
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
            CameraEventFactory::described('A person walks along the gravel path.', '2026-11-07 13:55:00', 'garden', subject: 'person'),
            CameraEventFactory::described('Luna crosses the lawn.', '2026-11-07 12:00:00', 'garden', individual: 'luna'),
            CameraEventFactory::described('A web thread.', '2026-11-07 03:00:00', 'garden', Verdict::FalseAlarm),
            CameraEventFactory::create('2026-11-06 06:16:40'),
        );

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Saturday, 7 November 2026');
        self::assertInputValueSame('day', '2026-11-07');
        $cards = $crawler->filter('.cards article');
        self::assertCount(2, $cards);
        self::assertStringContainsString('14:55:00', $cards->eq(0)->text(), 'Times are shown in Vienna time.');
        self::assertStringContainsString('A person walks along the gravel path.', $cards->eq(0)->text());
        self::assertStringContainsString('Luna', $cards->eq(1)->filter('.badges')->text(), 'Known individuals are named.');
    }

    public function testSummarizesTheDayOnRequest(): void
    {
        self::mockTime('2026-11-07 20:00:00');
        $client = self::createClient();
        $client->disableReboot();
        $this->resetStorage(self::getContainer());
        $this->storeEvents(
            self::getContainer(),
            CameraEventFactory::described('Luna crosses the lawn.', '2026-11-07 12:00:00', 'garden', individual: 'luna'),
            CameraEventFactory::described('A web thread.', '2026-11-07 03:00:00', 'garden', Verdict::FalseAlarm, 'nothing', falseAlarmCause: 'spider_web'),
        );
        $platform = self::getContainer()->get(FakePlatform::class);
        $platform->respondWith('{"text":"A quiet day: Luna crossed the lawn at 13:00. One spider web alarm at night."}');

        $client->request('GET', '/');
        $client->submitForm('Summarize this day');
        self::assertResponseRedirects('/?day=2026-11-07', 303);
        $client->followRedirect();

        self::assertSelectorTextContains('.digest', 'A quiet day: Luna crossed the lawn at 13:00.');
        self::assertSelectorNotExists('.digest button', 'The summary covers every event of the day.');
        $input = json_encode($platform->calls()[0]['input'] ?? self::fail('The AI was not called.'), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('- 13:00 Garden: Luna. Luna crosses the lawn.', $input);
        self::assertStringContainsString('- 04:00 Garden: false alarm (Spider web)', $input);

        $this->storeEvents(self::getContainer(), CameraEventFactory::described('A person.', '2026-11-07 15:00:00', 'street', subject: 'person'));
        $client->request('GET', '/');
        self::assertSelectorTextContains('.digest button', 'Update the summary');
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
