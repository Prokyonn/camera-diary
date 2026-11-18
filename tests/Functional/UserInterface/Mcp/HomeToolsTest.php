<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Mcp;

use App\Application\Activity\ActivityReport;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Mcp\HomeTools;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(HomeTools::class)]
#[CoversClass(ActivityReport::class)]
final class HomeToolsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetsStorage;
    use StoresEvents;

    private HomeTools $tools;

    protected function setUp(): void
    {
        self::mockTime('2026-11-08 12:00:00');
        self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->tools = self::getContainer()->get(HomeTools::class);
    }

    public function testCountsWhatTheCamerasSaw(): void
    {
        $this->storeEvents(
            self::getContainer(),
            CameraEventFactory::described('Luna on the wall.', '2026-11-06 06:10:00', individual: 'luna'),
            CameraEventFactory::described('Luna at the flap.', '2026-11-07 06:40:00', individual: 'luna'),
            CameraEventFactory::described('A grey cat.', '2026-11-07 21:00:00'),
            CameraEventFactory::described('The postman.', '2026-11-07 10:00:00', 'garden', subject: 'person'),
            CameraEventFactory::described('A web thread.', '2026-11-07 23:00:00', verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'spider_web'),
            CameraEventFactory::described('Too old.', '2026-10-30 10:00:00'),
            CameraEventFactory::create('2026-11-08 11:00:00'),
        );

        $activity = $this->tools->activity();

        self::assertSame(4, $activity['real_events']);
        self::assertSame(1, $activity['false_alarms']);
        self::assertSame(1, $activity['not_described_yet']);
        self::assertSame(['Luna' => 2, 'Person' => 1, 'Other cat' => 1], $activity['real_events_by_who']);
        self::assertSame(['Spider web' => 1], $activity['false_alarms_by_cause']);
        self::assertSame([7 => 2, 11 => 1, 22 => 1], $activity['real_events_by_local_hour'], 'Local hours, Europe/Vienna.');
        self::assertSame(['2026-11-01' => 0, '2026-11-02' => 0, '2026-11-03' => 0, '2026-11-04' => 0, '2026-11-05' => 0, '2026-11-06' => 1, '2026-11-07' => 3, '2026-11-08' => 0], $activity['real_events_by_day']);
        self::assertSame(['garden', 'terrace'], array_column($activity['cameras'], 'slug'));
        self::assertSame(['Garden' => 1, 'Terrace' => 3], array_column($activity['cameras'], 'real_events', 'name'));
    }

    public function testCorrectsAnEvent(): void
    {
        $event = CameraEventFactory::described('A grey cat.');
        $this->storeEvents(self::getContainer(), $event);

        $corrected = $this->tools->correct($event->getId(), individual: 'luna', label: 'Luna after the rain');

        self::assertSame('luna', $corrected['individual'] ?? null);
        $saved = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['id' => $event->getId()]);
        self::assertSame('Luna after the rain', $saved->getLabel());
        self::assertNotNull($saved->getReviewedAt(), 'A correction counts as a review.');
        self::assertSame(['luna'], array_column($this->tools->knownIndividuals(), 'name'));

        $wind = $this->tools->correct($event->getId(), verdict: 'false_alarm', cause: 'other');
        self::assertSame('other', $wind['false_alarm_cause'] ?? null);
        self::assertSame('Luna after the rain', $saved->getLabel(), 'Empty values keep what is set.');
    }

    public function testRejectsAnUnknownCause(): void
    {
        $event = CameraEventFactory::described('A grey cat.');
        $this->storeEvents(self::getContainer(), $event);

        $this->expectExceptionMessage('"tornado" is no false-alarm cause.');

        $this->tools->correct($event->getId(), cause: 'tornado');
    }

    public function testRejectsAnUnknownIndividual(): void
    {
        $event = CameraEventFactory::described('A grey cat.');
        $this->storeEvents(self::getContainer(), $event);

        $this->expectException(InvalidArgumentException::class);

        $this->tools->correct($event->getId(), individual: 'garfield');
    }

    public function testThePromptAsksForAReportOfOneDay(): void
    {
        $messages = $this->tools->dailyReport('2026-11-07');

        self::assertStringContainsString('Saturday, 7 November 2026', $messages[0]['content'] ?? '');
        self::assertStringContainsString('"2026-11-07T00:00:00+01:00" and to "2026-11-08T00:00:00+01:00"', $messages[0]['content'] ?? '');
    }
}
