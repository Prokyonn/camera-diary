<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Command;

use App\Domain\Repository\CameraRepositoryInterface;
use App\Tests\Support\ResetsStorage;
use App\UserInterface\Command\CameraCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CameraCommand::class)]
final class CameraCommandTest extends KernelTestCase
{
    use ResetsStorage;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->tester = new CommandTester(new Application($kernel)->find('app:camera'));
    }

    public function testCreatesRenamesAndListsACamera(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute(['slug' => 'porch', '--scene' => 'The porch; the street starts at the gate']));
        self::assertSame(Command::SUCCESS, $this->tester->execute(['slug' => 'porch', '--name' => 'Front porch']));

        self::assertStringContainsString('Front porch', $this->tester->getDisplay());
        $camera = self::getContainer()->get(CameraRepositoryInterface::class)->getOneBy(['slug' => 'porch']);
        self::assertSame('Front porch', $camera->getName());
        self::assertSame('The porch; the street starts at the gate', $camera->getScene(), 'A rename keeps the scene.');

        $this->tester->execute(['slug' => 'porch', '--scene' => '']);
        self::assertNull($camera->getScene(), 'An empty scene removes it.');
    }

    public function testRejectsAnEmptyName(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['slug' => 'porch', '--name' => ' ']));
        self::assertStringContainsString('must not be empty', $this->tester->getDisplay());
    }
}
