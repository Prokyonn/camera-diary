<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Command;

use App\Application\Description\PendingEventRunner;
use App\Tests\Support\ResetsStorage;
use App\UserInterface\Command\ProcessCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

#[CoversClass(ProcessCommand::class)]
final class ProcessCommandTest extends KernelTestCase
{
    use ResetsStorage;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->tester = new CommandTester(new Application($kernel)->find('app:process'));
    }

    public function testReportsThatThereIsNothingToDescribe(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('Nothing to describe.', $this->tester->getDisplay());
    }

    public function testStopsWhenAnotherRunHoldsTheLock(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock(PendingEventRunner::LOCK_KEY);
        $lock->acquire();

        try {
            self::assertSame(Command::FAILURE, $this->tester->execute([]));
            self::assertStringContainsString('Another run holds the lock.', $this->tester->getDisplay());
        } finally {
            $lock->release();
        }
    }
}
