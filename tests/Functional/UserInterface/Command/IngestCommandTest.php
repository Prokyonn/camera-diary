<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Command;

use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Support\ResetsStorage;
use App\UserInterface\Command\IngestCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(IngestCommand::class)]
final class IngestCommandTest extends KernelTestCase
{
    use ResetsStorage;

    private CommandTester $tester;

    private string $image;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->tester = new CommandTester(new Application($kernel)->find('app:ingest'));
        $this->image = sys_get_temp_dir().'/camera-diary-ingest-'.bin2hex(random_bytes(4)).'.jpg';
        $image = imagecreatetruecolor(16, 9);
        self::assertNotFalse($image);
        imagejpeg($image, $this->image);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->image);
        parent::tearDown();
    }

    public function testStoresAnEventAndPrintsItsAddress(): void
    {
        $status = $this->tester->execute([
            'camera' => 'garden',
            'files' => [$this->image],
            '--at' => '2026-11-10 07:16:20',
            '--detection' => ['person'],
            '--hint' => ['Doorbell rang'],
            '--external-id' => 'abc',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertMatchesRegularExpression('#Stored event \d+: http://\S+/events/\d+#', $this->tester->getDisplay());
        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['externalId' => 'abc']);
        self::assertSame('cli', $event->getSource());
        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertSame(['person'], $event->getDetectionTypes());
        self::assertSame(['Doorbell rang'], $event->getHints());
        self::assertSame('2026-11-10 06:16:20', $event->getStartedAt()->format('Y-m-d H:i:s'), 'The time is local (Europe/Vienna) and stored in UTC.');
    }

    public function testReturnsTheStoredEventForTheSameExternalId(): void
    {
        $arguments = ['camera' => 'garden', 'files' => [$this->image], '--external-id' => 'abc'];
        $this->tester->execute($arguments);

        self::assertSame(Command::SUCCESS, $this->tester->execute($arguments));
        self::assertStringContainsString('Already stored event', $this->tester->getDisplay());
    }

    public function testRejectsAFileThatCannotBeRead(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['camera' => 'garden', 'files' => ['/does/not/exist.jpg']]));
        self::assertStringContainsString('is no readable file', $this->tester->getDisplay());
    }

    public function testRejectsAnInvalidTime(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['camera' => 'garden', 'files' => [$this->image], '--at' => 'someday']));
    }

    public function testRejectsAnUnknownDetectionType(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['camera' => 'garden', 'files' => [$this->image], '--detection' => ['unicorn']]));
        self::assertStringContainsString('Unknown detection type "unicorn"', $this->tester->getDisplay());
    }
}
