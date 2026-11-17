<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Command;

use App\UserInterface\Command\SettingsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SettingsCommand::class)]
final class SettingsCommandTest extends KernelTestCase
{
    public function testShowsTheSettingsInEffect(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:settings'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $output = $tester->getDisplay();
        self::assertStringContainsString('tests/Fixtures/camera-diary.yaml', $output);
        self::assertStringContainsString('person, animal, vehicle, motion', $output);
        self::assertStringContainsString('Luna, a cat, 1 reference photos in the settings', $output);
        self::assertStringContainsString('The settings are valid.', $output);
    }
}
