<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Command;

use App\Application\Digest\DayDigests;
use App\Application\Question\QuestionHistory;
use App\Domain\Model\Confidence;
use App\Domain\Model\DayDigest;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Support\ResetsStorage;
use App\UserInterface\Command\DemoCommand;
use App\UserInterface\Command\DemoManifest;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DemoCommand::class)]
#[CoversClass(DemoManifest::class)]
final class DemoCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetsStorage;

    private string $dir;

    protected function setUp(): void
    {
        self::mockTime('2026-11-08 12:00:00');
        $this->dir = sys_get_temp_dir().'/camera-diary-demo-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->dir);
        $image = imagecreatetruecolor(16, 9);
        self::assertNotFalse($image);
        imagejpeg($image, $this->dir.'/flap.jpg');
        file_put_contents($this->dir.'/demo.yaml', <<<'YAML'
            cameras:
                terrace: {name: Terrace, scene: The terrace with the cat flap.}
            events:
                demo-flap:
                    camera: terrace
                    clip: flap.jpg
                    time: '21:40'
                    days_ago: 2
                    detections: [animal]
                    recorded:
                        model: gemini-flash-latest
                        verdict: real
                        side: our_property
                        subject: cat
                        individual: luna
                        also_seen: [anna]
                        summary: Luna comes in through the cat flap.
                        confidence: medium
                    correction:
                        label: Luna after the rain
            questions:
                - question: When did Luna come home?
                  answer: At 21:40, through the cat flap.
                  events: [demo-flap]
            digests:
                - days_ago: 2
                  text: A quiet day; Luna came home through the cat flap at 21:40.
                  model: gemini-flash-latest
            YAML);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    public function testLoadsTheRecordedAnswersWithoutTheAi(): void
    {
        $application = new Application(self::bootKernel());
        $this->resetStorage(self::getContainer());
        $tester = new CommandTester($application->find('app:demo'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--manifest' => $this->dir.'/demo.yaml', '--recorded' => true]));
        $tester->execute(['--manifest' => $this->dir.'/demo.yaml', '--recorded' => true]);
        self::assertStringContainsString('already stored', $tester->getDisplay(), 'Loading again is a no-op.');

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['externalId' => 'demo-flap']);
        self::assertSame(EventStatus::Described, $event->getStatus());
        self::assertSame('2026-11-06 21:40', $event->getStartedAt()->setTimezone(new DateTimeZone('Europe/Vienna'))->format('Y-m-d H:i'), 'Two calendar days before today, at 21:40.');
        self::assertSame('luna', $event->getEffectiveIndividual());
        self::assertSame(['anna'], $event->getAlsoSeen());
        self::assertSame(Confidence::Medium, $event->getConfidence());
        self::assertSame(Verdict::Real, $event->getEffectiveVerdict());
        self::assertSame('Luna after the rain', $event->getLabel());
        self::assertSame('gemini-flash-latest (recorded)', $event->getModel());

        $questions = self::getContainer()->get(QuestionHistory::class)->recent(10);
        self::assertCount(1, $questions, 'Each question is stored once.');
        self::assertSame([$event->getId()], $questions[0]->getEventIds());

        $digest = self::getContainer()->get(DayDigests::class)->of($event->getStartedAt());
        self::assertInstanceOf(DayDigest::class, $digest, 'The recorded summary of that day.');
        self::assertSame('A quiet day; Luna came home through the cat flap at 21:40.', $digest->getText());
        self::assertSame(1, $digest->getEventCount());
        self::assertSame('gemini-flash-latest (recorded)', $digest->getModel());
    }

    public function testLeavesTheEventsToTheAiByDefault(): void
    {
        $application = new Application(self::bootKernel());
        $this->resetStorage(self::getContainer());

        new CommandTester($application->find('app:demo'))->execute(['--manifest' => $this->dir.'/demo.yaml']);

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['externalId' => 'demo-flap']);
        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertSame([], self::getContainer()->get(QuestionHistory::class)->recent(10));
    }

    public function testFilesARecordedRepeatWithoutTheAi(): void
    {
        $application = new Application(self::bootKernel());
        $this->resetStorage(self::getContainer());
        file_put_contents($this->dir.'/repeats.yaml', <<<'YAML'
            cameras:
                shed: {name: Shed, scene: The shed door.}
            events:
                demo-web:
                    camera: shed
                    clip: flap.jpg
                    time: '01:15'
                    recorded: {model: gemini-flash-latest, verdict: false_alarm, false_alarm_cause: spider_web, side: our_property, subject: nothing, summary: A spider in front of the lens.}
                demo-web-again:
                    camera: shed
                    clip: flap.jpg
                    time: '01:25'
                    repeat_of: demo-web
                demo-web-lost:
                    camera: shed
                    clip: flap.jpg
                    time: '01:35'
                    repeat_of: demo-nothing
            YAML);
        $tester = new CommandTester($application->find('app:demo'));

        self::assertSame(Command::FAILURE, $tester->execute(['--manifest' => $this->dir.'/repeats.yaml', '--recorded' => true]));
        self::assertStringContainsString('"demo-web-lost" repeats "demo-nothing"', $tester->getDisplay());

        $events = self::getContainer()->get(CameraEventRepositoryInterface::class);
        $repeat = $events->getOneBy(['externalId' => 'demo-web-again']);
        self::assertSame($events->getOneBy(['externalId' => 'demo-web'])->getId(), $repeat->getRepeatOf());
        self::assertSame('spider_web', $repeat->getEffectiveFalseAlarmCause());
    }

    public function testRejectsAnInvalidManifest(): void
    {
        $application = new Application(self::bootKernel());
        file_put_contents($this->dir.'/broken.yaml', "events:\n    x: {camera: terrace, clip: a.jpg, time: '25:00'}\n");
        $tester = new CommandTester($application->find('app:demo'));

        self::assertSame(Command::INVALID, $tester->execute(['--manifest' => $this->dir.'/broken.yaml']));
        self::assertStringContainsString('Use HH:MM', $tester->getDisplay());
    }
}
