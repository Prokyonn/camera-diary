<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event;

use App\Application\Event\CameraEventLabeler;
use App\Application\Event\InvalidCorrectionException;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Search\EventIndexerInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Verdict;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\SettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Clock\ClockInterface;

#[CoversClass(CameraEventLabeler::class)]
final class CameraEventLabelerTest extends TestCase
{
    use ProphecyTrait;
    /** @var ObjectProphecy<UnitOfWorkInterface> */
    private ObjectProphecy $unitOfWork;

    /** @var ObjectProphecy<EventIndexerInterface> */
    private ObjectProphecy $indexer;

    private CameraEventLabeler $labeler;

    protected function setUp(): void
    {
        $this->unitOfWork = $this->prophesize(UnitOfWorkInterface::class);
        $this->indexer = $this->prophesize(EventIndexerInterface::class);
        $clock = $this->prophesize(ClockInterface::class);
        $clock->now()->willReturn(CameraEventFactory::utc('2026-11-07 08:00:00'));
        $settings = $this->prophesize(SettingsProviderInterface::class);
        $settings->settings()->willReturn(SettingsFactory::create([
            'individuals' => ['luna' => ['subject' => 'cat']],
        ]));

        $this->labeler = new CameraEventLabeler($this->unitOfWork->reveal(), $this->indexer->reveal(), $clock->reveal(), $settings->reveal());
    }

    public function testStoresAKnownCorrectionAsReviewedAndIndexesIt(): void
    {
        $event = CameraEventFactory::described('A grey cat.');

        $this->labeler->relabel($event, 'Luna', Verdict::FalseAlarm, 'luna', 'rain');

        self::assertSame('luna', $event->getIndividualOverride());
        self::assertSame('rain', $event->getFalseAlarmCauseOverride());
        self::assertEquals(CameraEventFactory::utc('2026-11-07 08:00:00'), $event->getReviewedAt());
        $this->unitOfWork->flush()->shouldHaveBeenCalledOnce();
        $this->indexer->index($event)->shouldHaveBeenCalledOnce();
    }

    public function testAcceptsNobodyWeKnowAndKeepingTheAnswer(): void
    {
        $event = CameraEventFactory::described('A grey cat.');

        $this->labeler->relabel($event, null, null, CameraEvent::NO_KNOWN_INDIVIDUAL, null);

        self::assertSame(CameraEvent::NO_KNOWN_INDIVIDUAL, $event->getIndividualOverride());
        self::assertNull($event->getFalseAlarmCauseOverride());
    }

    public function testRejectsAnUnknownIndividual(): void
    {
        $event = CameraEventFactory::described('A grey cat.');

        $this->expectException(InvalidCorrectionException::class);
        $this->expectExceptionMessageIsOrContains('"garfield" is no known individual');

        try {
            $this->labeler->relabel($event, null, null, 'garfield', null);
        } finally {
            self::assertNull($event->getIndividualOverride());
            $this->unitOfWork->flush()->shouldNotHaveBeenCalled();
            $this->indexer->index(Argument::any())->shouldNotHaveBeenCalled();
        }
    }

    public function testRejectsAnUnknownCause(): void
    {
        $event = CameraEventFactory::described('A web thread.', verdict: Verdict::FalseAlarm);

        $this->expectException(InvalidCorrectionException::class);
        $this->expectExceptionMessageIsOrContains('"tornado" is no false-alarm cause');

        $this->labeler->relabel($event, null, null, null, 'tornado');
    }

    public function testKeepsAnOverrideThatIsNoLongerInTheSettings(): void
    {
        $event = CameraEventFactory::described('A grey cat.');
        $event->relabel(null, null, 'gone_cat', 'gone_cause');

        $this->labeler->relabel($event, 'Still the same', null, 'gone_cat', 'gone_cause');

        self::assertSame('gone_cat', $event->getIndividualOverride());
        self::assertSame('Still the same', $event->getLabel());
    }

    public function testConfirmMarksTheEventReviewed(): void
    {
        $event = CameraEventFactory::described('A grey cat.');

        $this->labeler->confirm($event);

        self::assertNotNull($event->getReviewedAt());
        $this->indexer->index($event)->shouldHaveBeenCalledOnce();
    }
}
