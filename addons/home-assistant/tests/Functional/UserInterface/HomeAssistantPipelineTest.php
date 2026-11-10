<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Tests\Functional\UserInterface;

use App\Application\Description\HintCollector;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\FakeHomeAssistant;
use App\Tests\Support\FakePlatform;
use App\Tests\Support\ResetsStorage;
use CameraEvents\HomeAssistant\Application\Import\HomeAssistantImport;
use CameraEvents\HomeAssistant\Tests\Support\RecordingFixture;
use CameraEvents\HomeAssistant\UserInterface\Command\ImportCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The whole add-on: Home Assistant history in, frames cut from a recording by the core worker, the AI's description
 * stored and searchable.
 */
#[CoversClass(ImportCommand::class)]
#[CoversClass(HomeAssistantImport::class)]
final class HomeAssistantPipelineTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetsStorage;

    private RecordingFixture $recordings;

    private Application $application;

    protected function setUp(): void
    {
        if (null === RecordingFixture::ffmpeg()) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        self::mockTime('2026-11-06 06:30:00');
        $this->application = new Application(self::bootKernel());
        $this->resetStorage(self::getContainer());

        $this->recordings = new RecordingFixture(self::getContainer()->getParameter('kernel.project_dir').'/var/test/recordings');
        $this->recordings->remove();
    }

    protected function tearDown(): void
    {
        $this->recordings->remove();
        parent::tearDown();
    }

    public function testImportsGroupsAndDescribesAnEvent(): void
    {
        $homeAssistant = self::getContainer()->get(FakeHomeAssistant::class);
        $homeAssistant->addState('binary_sensor.terrace_animal', 'on', '2026-11-06T06:16:40.120+00:00');
        $homeAssistant->addState('binary_sensor.terrace_animal', 'off', '2026-11-06T06:16:52+00:00');
        $homeAssistant->addState('binary_sensor.terrace_animal', 'on', '2026-11-06T06:17:20+00:00');
        $homeAssistant->addState('binary_sensor.luna', 'on', '2026-11-06T06:19:30+00:00', ['since' => '2026-11-06T06:17:02+00:00']);
        $this->recordings->video('Terrace', CameraEventFactory::utc('2026-11-06 06:16:35'));
        self::getContainer()->get(FakePlatform::class)->respondWith(
            '{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"cat","summary":"A strange grey cat drags the doormat from the cat flap to the door."}',
        );

        $import = new CommandTester($this->application->find('home-assistant:import'));
        $import->execute([]);
        $import->assertCommandIsSuccessful();
        self::assertStringContainsString('Imported 2 detections and 1 signals, 1 new and 1 closed events', $import->getDisplay());

        $process = new CommandTester($this->application->find('app:process'));
        $process->execute([]);
        $process->assertCommandIsSuccessful();
        self::assertStringContainsString('Described 1', $process->getDisplay());

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['cameraSlug' => 'terrace']);
        self::assertSame(EventStatus::Described, $event->getStatus());
        self::assertSame(2, $event->getDetectionCount());
        self::assertCount(3, $event->getFramePaths());
        self::assertSame('Terrace', $event->getCamera()->getName(), 'The camera takes its name from the settings.');
        self::assertSame(['07:17:02 Luna came in through the cat flap'], self::getContainer()->get(HintCollector::class)->hintsFor($event));

        $found = self::getContainer()->get(EventSearchInterface::class)->search(new SearchCriteria(text: 'grey cat'));
        self::assertSame([$event->getId()], array_map(static fn ($match): int => $match->getId(), $found));
    }

    public function testDoesNotDescribeAnEventThatMayStillGrow(): void
    {
        self::mockTime('2026-11-06 06:18:30');
        $homeAssistant = self::getContainer()->get(FakeHomeAssistant::class);
        $homeAssistant->addState('binary_sensor.terrace_animal', 'on', '2026-11-06T06:17:20+00:00');

        $import = new CommandTester($this->application->find('home-assistant:import'));
        self::assertSame(Command::SUCCESS, $import->execute([]));
        self::assertStringContainsString('1 new and 0 closed events', $import->getDisplay());

        $process = new CommandTester($this->application->find('app:process'));
        self::assertSame(Command::SUCCESS, $process->execute([]));
        self::assertStringContainsString('Nothing to describe', $process->getDisplay());
        self::assertSame([], self::getContainer()->get(FakePlatform::class)->calls());
    }
}
