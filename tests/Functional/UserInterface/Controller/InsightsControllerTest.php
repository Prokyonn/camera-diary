<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\InsightsController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(InsightsController::class)]
final class InsightsControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use ResetsStorage;
    use StoresEvents;

    public function testShowsWhoCameByAndWhatRaisedFalseAlarms(): void
    {
        $client = self::createClient();
        self::mockTime('2026-11-08 12:00:00');
        $this->resetStorage(self::getContainer());
        $webs = array_map(
            static fn (int $minute): CameraEvent => CameraEventFactory::described('A web thread.', \sprintf('2026-11-07 23:%02d:00', $minute), 'garden', Verdict::FalseAlarm, 'nothing', falseAlarmCause: 'spider_web'),
            range(1, 5),
        );
        $this->storeEvents(self::getContainer(), CameraEventFactory::described('Luna on the wall.', '2026-11-07 06:10:00', individual: 'luna'), ...$webs);
        $repeat = CameraEventFactory::withFrames('2026-11-07 23:30:00', 'garden');
        $repeat->describeAsRepeatOf($webs[4], CameraEventFactory::utc('2026-11-07 23:31:00'));
        $this->storeEvents(self::getContainer(), $repeat);

        $client->request('GET', '/insights?days=30');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.summary', '1 real events and 6 false alarms');
        self::assertSelectorTextContains('.bars', 'Luna');
        self::assertSelectorTextContains('.camera-insights .tip', 'Spider web caused 6 of them: worth a look at this camera.');
        self::assertSelectorTextContains('main', '1 of the false alarms repeated an ongoing one and was filed without asking the AI.');
    }
}
