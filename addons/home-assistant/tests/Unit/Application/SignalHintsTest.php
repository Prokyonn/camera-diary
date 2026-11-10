<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Unit\Application;

use App\Application\Clock\HomeTimeZone;
use App\Tests\Factory\CameraEventFactory;
use CameraEvents\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraEvents\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraEvents\HomeAssistant\Application\SignalHints;
use CameraEvents\HomeAssistant\Domain\Model\HomeSignal;
use CameraEvents\HomeAssistant\Domain\Repository\HomeSignalRepositoryInterface;
use CameraEvents\HomeAssistant\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(SignalHints::class)]
final class SignalHintsTest extends TestCase
{
    use ProphecyTrait;

    public function testDescribesTheSignalsAroundTheEventInLocalTime(): void
    {
        $event = CameraEventFactory::create('2026-11-06 06:16:40');
        $signals = $this->prophesize(HomeSignalRepositoryInterface::class);
        $signals->findBy([
            'changedAtOrAfter' => CameraEventFactory::utc('2026-11-06 06:13:40'),
            'changedAtOrBefore' => CameraEventFactory::utc('2026-11-06 06:19:40'),
        ], ['changedAt' => 'asc'])->willReturn([
            new HomeSignal('binary_sensor.luna', 'on', CameraEventFactory::utc('2026-11-06 06:17:02')),
            new HomeSignal('binary_sensor.luna', 'unavailable', CameraEventFactory::utc('2026-11-06 06:18:00')),
            new HomeSignal('sensor.removed_from_settings', 'on', CameraEventFactory::utc('2026-11-06 06:18:30')),
        ]);

        self::assertSame(['07:17:02 Luna came in through the cat flap'], $this->hints($signals->reveal(), SettingsFactory::create())->hintsFor($event));
    }

    public function testHasNoHintsWithoutSignals(): void
    {
        $signals = $this->prophesize(HomeSignalRepositoryInterface::class);
        $signals->findBy(Argument::cetera())->shouldNotBeCalled();

        self::assertSame([], $this->hints($signals->reveal(), new HomeAssistantSettings())->hintsFor(CameraEventFactory::create()));
    }

    private function hints(HomeSignalRepositoryInterface $signals, HomeAssistantSettings $settings): SignalHints
    {
        $provider = $this->prophesize(SettingsProviderInterface::class);
        $provider->settings()->willReturn($settings);

        return new SignalHints($signals, $provider->reveal(), new HomeTimeZone('Europe/Vienna'));
    }
}
