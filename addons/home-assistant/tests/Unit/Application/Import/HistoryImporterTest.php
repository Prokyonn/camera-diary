<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Unit\Application\Import;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\DetectionType;
use App\Tests\Factory\CameraEventFactory;
use CameraEvents\HomeAssistant\Application\History\HomeHistoryInterface;
use CameraEvents\HomeAssistant\Application\History\StateChange;
use CameraEvents\HomeAssistant\Application\Import\HistoryImporter;
use CameraEvents\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraEvents\HomeAssistant\Domain\Model\Detection;
use CameraEvents\HomeAssistant\Domain\Model\HomeSignal;
use CameraEvents\HomeAssistant\Domain\Model\ImportCursor;
use CameraEvents\HomeAssistant\Domain\Repository\DetectionRepositoryInterface;
use CameraEvents\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;
use CameraEvents\HomeAssistant\Domain\Repository\ImportCursorRepositoryInterface;
use CameraEvents\HomeAssistant\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

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

        $existing = new Detection('terrace', DetectionType::Animal, CameraEventFactory::utc('2026-11-06 06:17:20'));
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

        $importer = new HistoryImporter($history->reveal(), $detections->reveal(), $signals->reveal(), $cursors->reveal(), $unitOfWork->reveal());
        $result = $importer->importRange(SettingsFactory::create(), $from, $to);

        self::assertSame(1, $result->detections);
        self::assertSame(1, $result->signals);
    }

    public function testSplitsLongRangesIntoSixHourChunks(): void
    {
        $history = $this->prophesize(HomeHistoryInterface::class);
        $history->fetch(Argument::cetera())->willReturn([])->shouldBeCalledTimes(4);
        $detections = $this->prophesize(DetectionRepositoryInterface::class);
        $detections->findBy(Argument::cetera())->willReturn([]);
        $signals = $this->prophesize(HomeSignalRepositoryInterface::class);
        $signals->findBy(Argument::cetera())->willReturn([]);
        $cursor = new ImportCursor(ImportCursor::HOME_ASSISTANT, CameraEventFactory::utc('2026-11-05 00:00:00'));
        $cursors = $this->prophesize(ImportCursorRepositoryInterface::class);
        $cursors->findOneBy(Argument::cetera())->willReturn($cursor);
        $unitOfWork = $this->prophesize(UnitOfWorkInterface::class);

        $importer = new HistoryImporter($history->reveal(), $detections->reveal(), $signals->reveal(), $cursors->reveal(), $unitOfWork->reveal());
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
            $this->prophesize(UnitOfWorkInterface::class)->reveal(),
        );

        $result = $importer->importRange(new HomeAssistantSettings(), CameraEventFactory::utc('2026-11-06 00:00:00'), CameraEventFactory::utc('2026-11-06 01:00:00'));

        self::assertSame(0, $result->detections + $result->signals);
    }
}
