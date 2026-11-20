<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Unit\Application\Import;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Tests\Factory\CameraEventFactory;
use CameraDiary\HomeAssistant\Application\History\HomeHistoryInterface;
use CameraDiary\HomeAssistant\Application\History\StateChange;
use CameraDiary\HomeAssistant\Application\Import\HistoryImporter;
use CameraDiary\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraDiary\HomeAssistant\Domain\Model\Detection;
use CameraDiary\HomeAssistant\Domain\Model\GroupingRule;
use CameraDiary\HomeAssistant\Domain\Model\HomeSignal;
use CameraDiary\HomeAssistant\Domain\Model\ImportCursor;
use CameraDiary\HomeAssistant\Domain\Model\PushHandover;
use CameraDiary\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use CameraDiary\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;
use CameraDiary\HomeAssistant\Domain\Repository\ImportCursorRepositoryInterface;
use CameraDiary\HomeAssistant\Domain\Repository\PushHandoverRepositoryInterface;
use CameraDiary\HomeAssistant\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;

#[CoversClass(HistoryImporter::class)]
final class HistoryImporterTest extends TestCase
{
    use ProphecyTrait;

    public function testImportsDetectionsAndSignalsAndAdvancesTheCursor(): void
    {
        $from = CameraEventFactory::utc('2026-11-06 06:00:00');
        $to = CameraEventFactory::utc('2026-11-06 07:00:00');
        $history = $this->prophesize(HomeHistoryInterface::class);
        $history->fetch(Argument::containing('binary_sensor.terrace_animal'), $from, $to, false)->willReturn([
            new StateChange('binary_sensor.terrace_animal', 'on', CameraEventFactory::utc('2026-11-06 06:16:40')),
            new StateChange('binary_sensor.terrace_animal', 'off', CameraEventFactory::utc('2026-11-06 06:16:55')),
            new StateChange('binary_sensor.terrace_animal', 'on', CameraEventFactory::utc('2026-11-06 06:17:20')),
            new StateChange('binary_sensor.garden_motion', 'on', CameraEventFactory::utc('2026-11-06 06:18:00')),
        ]);
        $history->fetch(Argument::containing('binary_sensor.luna'), $from, $to, true)->willReturn([
            new StateChange('binary_sensor.luna', 'on', CameraEventFactory::utc('2026-11-06 06:19:00'), ['since' => '2026-11-06T06:17:02.512+00:00']),
            new StateChange('lock.front_door', 'unavailable', CameraEventFactory::utc('2026-11-06 06:20:00')),
        ]);
        $history->fetch(['input_text.camera_push'], $from, $to, false)->willReturn([
            new StateChange('input_text.camera_push', 'garden|1793945800|1|garden-2026-11-06_07-16-40-person|reolink-garden|motion/garden/2026-11-06_07-16-40_person|Person in the garden|Person at 07:16:40.', CameraEventFactory::utc('2026-11-06 06:16:58')),
            new StateChange('input_text.camera_push', 'garden|1793945900|1|garden-2026-11-06_07-18-20-person|reolink-garden|motion/garden/2026-11-06_07-18-20_person|Person in the garden|Already imported.', CameraEventFactory::utc('2026-11-06 06:18:38')),
            new StateChange('input_text.camera_push', 'not a handover', CameraEventFactory::utc('2026-11-06 06:19:00')),
        ]);
        $pushHandovers = $this->prophesize(PushHandoverRepositoryInterface::class);
        $pushHandovers->findBy(['tag' => 'garden-2026-11-06_07-16-40-person'])->willReturn([]);
        $pushHandovers->findBy(['tag' => 'garden-2026-11-06_07-18-20-person'])->willReturn([$this->prophesize(PushHandover::class)->reveal()]);
        $pushHandovers->add(Argument::that(static fn (PushHandover $handover): bool => 'garden-2026-11-06_07-16-40-person' === $handover->getTag()
            && '2026-11-06 06:16:40' === $handover->getPushedAt()->format('Y-m-d H:i:s')))->shouldBeCalledOnce();

        $existing = new Detection('terrace', 'animal', CameraEventFactory::utc('2026-11-06 06:17:20'));
        $detections = $this->prophesize(DetectionRepositoryInterface::class);
        $detections->findBy(['detectedAtOrAfter' => $from, 'detectedAtOrBefore' => $to])->willReturn([$existing]);
        $detections->add(Argument::that(static fn (Detection $detection): bool => 'terrace' === $detection->getCamera() && '2026-11-06 06:16:40' === $detection->getDetectedAt()->format('Y-m-d H:i:s')))
            ->shouldBeCalledOnce();

        $signals = $this->prophesize(HomeSignalRepositoryInterface::class);
        $signals->findBy(Argument::cetera())->willReturn([]);
        $signals->add(Argument::that(static fn (HomeSignal $signal): bool => '2026-11-06 06:17:02' === $signal->getChangedAt()->format('Y-m-d H:i:s')))
            ->shouldBeCalledOnce();

        $cursors = $this->prophesize(ImportCursorRepositoryInterface::class);
        $cursors->findOneBy(['name' => ImportCursor::HOME_ASSISTANT])->willReturn(null);
        $cursors->add(Argument::that(static fn (ImportCursor $cursor): bool => $cursor->getImportedUntil() === $to))->shouldBeCalledOnce();
        $unitOfWork = $this->prophesize(UnitOfWorkInterface::class);
        $unitOfWork->flush()->shouldBeCalledOnce();

        $importer = new HistoryImporter($history->reveal(), $detections->reveal(), $signals->reveal(), $cursors->reveal(), $pushHandovers->reveal(), $unitOfWork->reveal(), new NullLogger());
        $result = $importer->importRange(SettingsFactory::create(), $from, $to);

        self::assertSame(1, $result->detections);
        self::assertSame(1, $result->signals);
    }

    public function testSkipsARepeatedSignalWhoseTimeLiesLongBeforeTheRange(): void
    {
        $from = CameraEventFactory::utc('2026-11-06 12:00:00');
        $to = CameraEventFactory::utc('2026-11-06 13:00:00');
        $history = $this->prophesize(HomeHistoryInterface::class);
        $history->fetch(Argument::containing('binary_sensor.luna'), $from, $to, true)->willReturn([
            // Home Assistant restarted and reports the flap's state again, with the time of the real passage.
            new StateChange('binary_sensor.luna', 'on', CameraEventFactory::utc('2026-11-06 12:30:00'), ['since' => '2026-11-06T10:12:01+00:00']),
        ]);
        $history->fetch(Argument::cetera())->willReturn([]);
        $detections = $this->prophesize(DetectionRepositoryInterface::class);
        $detections->findBy(Argument::cetera())->willReturn([]);
        $stored = new HomeSignal('binary_sensor.luna', 'on', CameraEventFactory::utc('2026-11-06 10:12:01'));
        $signals = $this->prophesize(HomeSignalRepositoryInterface::class);
        $signals->findBy(['changedAtOrAfter' => CameraEventFactory::utc('2026-11-06 10:12:01'), 'changedAtOrBefore' => $to])->willReturn([$stored]);
        $signals->add(Argument::any())->shouldNotBeCalled();
        $cursors = $this->prophesize(ImportCursorRepositoryInterface::class);
        $cursors->findOneBy(Argument::cetera())->willReturn(null);
        $cursors->add(Argument::type(ImportCursor::class))->shouldBeCalledOnce();

        $importer = new HistoryImporter($history->reveal(), $detections->reveal(), $signals->reveal(), $cursors->reveal(), $this->prophesize(PushHandoverRepositoryInterface::class)->reveal(), $this->prophesize(UnitOfWorkInterface::class)->reveal(), new NullLogger());

        self::assertSame(0, $importer->importRange(SettingsFactory::create(), $from, $to)->signals);
    }

    public function testSplitsLongRangesIntoSixHourChunks(): void
    {
        $history = $this->prophesize(HomeHistoryInterface::class);
        $history->fetch(Argument::cetera())->willReturn([])->shouldBeCalledTimes(6);
        $detections = $this->prophesize(DetectionRepositoryInterface::class);
        $detections->findBy(Argument::cetera())->willReturn([]);
        $signals = $this->prophesize(HomeSignalRepositoryInterface::class);
        $signals->findBy(Argument::cetera())->willReturn([]);
        $cursor = new ImportCursor(ImportCursor::HOME_ASSISTANT, CameraEventFactory::utc('2026-11-05 00:00:00'));
        $cursors = $this->prophesize(ImportCursorRepositoryInterface::class);
        $cursors->findOneBy(Argument::cetera())->willReturn($cursor);
        $unitOfWork = $this->prophesize(UnitOfWorkInterface::class);

        $importer = new HistoryImporter($history->reveal(), $detections->reveal(), $signals->reveal(), $cursors->reveal(), $this->prophesize(PushHandoverRepositoryInterface::class)->reveal(), $unitOfWork->reveal(), new NullLogger());
        $importer->importRange(SettingsFactory::create(), CameraEventFactory::utc('2026-11-06 00:00:00'), CameraEventFactory::utc('2026-11-06 07:00:00'));

        self::assertEquals(CameraEventFactory::utc('2026-11-06 07:00:00'), $cursor->getImportedUntil());
    }

    public function testDoesNotAskHomeAssistantWithoutCamerasOrSignals(): void
    {
        $history = $this->prophesize(HomeHistoryInterface::class);
        $history->fetch(Argument::cetera())->shouldNotBeCalled();
        $cursors = $this->prophesize(ImportCursorRepositoryInterface::class);
        $cursors->findOneBy(Argument::cetera())->willReturn(null);
        $cursors->add(Argument::type(ImportCursor::class))->shouldBeCalledOnce();

        $importer = new HistoryImporter(
            $history->reveal(),
            $this->prophesize(DetectionRepositoryInterface::class)->reveal(),
            $this->prophesize(HomeSignalRepositoryInterface::class)->reveal(),
            $cursors->reveal(),
            $this->prophesize(PushHandoverRepositoryInterface::class)->reveal(),
            $this->prophesize(UnitOfWorkInterface::class)->reveal(),
            new NullLogger(),
        );

        $result = $importer->importRange(new HomeAssistantSettings([], [], new GroupingRule(120, 600), 180), CameraEventFactory::utc('2026-11-06 00:00:00'), CameraEventFactory::utc('2026-11-06 01:00:00'));

        self::assertSame(0, $result->detections + $result->signals);
    }
}
