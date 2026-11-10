<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Doctrine;

use App\Application\Clock\HomeTimeZone;
use App\Infrastructure\Doctrine\DbalAiCallBudget;
use App\Tests\Support\ResetsStorage;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DbalAiCallBudget::class)]
final class DbalAiCallBudgetTest extends KernelTestCase
{
    use ResetsStorage;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetStorage(self::getContainer());
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testStopsAtTheDailyLimit(): void
    {
        $budget = $this->budget(new MockClock('2026-11-08 12:00:00', 'UTC'), 2);

        self::assertTrue($budget->tryConsume());
        self::assertTrue($budget->tryConsume());
        self::assertFalse($budget->tryConsume());
        self::assertSame(2, $budget->usedToday());
    }

    public function testStartsAgainAtLocalMidnight(): void
    {
        $clock = new MockClock('2026-11-08 22:59:00', 'UTC');
        $budget = $this->budget($clock, 1);
        self::assertTrue($budget->tryConsume());
        self::assertFalse($budget->tryConsume());

        $clock->modify('+1 minute');

        self::assertTrue($budget->tryConsume(), 'Midnight in Vienna is 23:00 UTC in November.');
    }

    public function testAZeroLimitAllowsNothing(): void
    {
        self::assertFalse($this->budget(new MockClock(), 0)->tryConsume());
    }

    private function budget(MockClock $clock, int $limit): DbalAiCallBudget
    {
        return new DbalAiCallBudget($this->connection, $clock, new HomeTimeZone('Europe/Vienna'), $limit);
    }
}
