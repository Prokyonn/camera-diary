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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
            '{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"cat","summary":"A strange grey cat drags the doormat from the cat flap to the door.","unusual":false}',
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
        self::assertSame([], $homeAssistant->notifications(), 'Without a handover there is no push to replace.');
    }

    public function testReplacesTheHomeAssistantPushWithTheVerdict(): void
    {
        $homeAssistant = self::getContainer()->get(FakeHomeAssistant::class);
        $homeAssistant->addState('binary_sensor.garden_person', 'on', '2026-11-06T06:16:40.120+00:00');
        $homeAssistant->addState('binary_sensor.garden_person', 'off', '2026-11-06T06:16:52+00:00');
        $homeAssistant->addState('input_text.camera_push', 'garden|1793945800|1|garden-2026-11-06_07-16-40-person|reolink-garden|motion/garden/2026-11-06_07-16-40_person|Person in the garden|Person at 07:16:40.', '2026-11-06T06:16:58+00:00');
        $homeAssistant->addMedia('motion/garden/2026-11-06_07-16-40_person.jpg', $this->snapshot());
        $homeAssistant->addMedia('motion/garden/2026-11-06_07-16-40_person.mp4', (string) file_get_contents(RecordingFixture::videoFile($this->recordings->dir.'/clip.mp4')));
        self::getContainer()->get(FakePlatform::class)->respondWith(
            '{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"person","summary":"A man in a dark hood looks into the shed.","unusual":true}',
        );

        new CommandTester($this->application->find('home-assistant:import'))->execute([]);
        $process = new CommandTester($this->application->find('app:process'));
        $process->execute([]);
        self::assertStringContainsString('Described 1', $process->getDisplay());

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['cameraSlug' => 'garden']);
        self::assertCount(3, $event->getFramePaths(), 'The frames come from the push snapshot and clip; there is no recording.');

        $detailsUri = self::getContainer()->get(UrlGeneratorInterface::class)->generate('deep_link', ['camera' => 'garden', 't' => 1793945800], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertEquals([[
            'service' => 'mobile_app_phone',
            'payload' => [
                'title' => 'Person in the garden',
                'message' => 'Person at 07:16:40. A man in a dark hood looks into the shed.',
                'data' => [
                    'video' => '/media/local/motion/garden/2026-11-06_07-16-40_person.mp4',
                    'group' => 'reolink-garden',
                    'tag' => 'garden-2026-11-06_07-16-40-person',
                    'url' => '/lovelace/cameras',
                    'push' => ['interruption-level' => 'active'],
                    'actions' => [
                        ['action' => 'URI', 'title' => 'Analysis', 'uri' => $detailsUri],
                        ['action' => 'CAMERA_MUTE_1H', 'title' => "That's us, mute 1 h"],
                    ],
                ],
            ],
        ]], $homeAssistant->notifications(), 'The same push, with the verdict and a sound for an unusual event.');

        self::getContainer()->get(FakePlatform::class)->respondWith(
            '{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"person","summary":"A man looks into the shed.","unusual":true}',
        );
        new CommandTester($this->application->find('app:describe'))->execute(['id' => (string) $event->getId()]);
        self::assertCount(1, $homeAssistant->notifications(), 'A push is replaced only once.');
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

    private function snapshot(): string
    {
        $image = imagecreatetruecolor(64, 36);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
