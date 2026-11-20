<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Command;

use App\Application\Description\PendingEventRunner;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Command\DescribeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

#[CoversClass(DescribeCommand::class)]
final class DescribeCommandTest extends KernelTestCase
{
    use ResetsStorage;
    use StoresEvents;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->tester = new CommandTester(new Application($kernel)->find('app:describe'));
    }

    public function testFailsForAnUnknownEvent(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['id' => 99]));
        self::assertStringContainsString('Event 99 does not exist.', $this->tester->getDisplay());
    }

    public function testDoesNotDescribeAnOpenEvent(): void
    {
        $event = CameraEventFactory::create(open: true);
        $this->storeEvents(self::getContainer(), $event);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['id' => $event->getId()]));
        self::assertMatchesRegularExpression('/Outcome\s+open/', $this->tester->getDisplay());
    }

    public function testWaitsForTheWorkersLock(): void
    {
        $event = CameraEventFactory::withFrames();
        $this->storeEvents(self::getContainer(), $event);
        $lock = self::getContainer()->get(LockFactory::class)->createLock(PendingEventRunner::LOCK_KEY);
        $lock->acquire();

        try {
            self::assertSame(Command::FAILURE, $this->tester->execute(['id' => $event->getId()]));
            self::assertStringContainsString('Another description run is in progress', $this->tester->getDisplay());
        } finally {
            $lock->release();
        }
    }
}
