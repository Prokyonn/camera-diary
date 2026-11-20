<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ai;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiUsage;
use App\Application\Settings\CallPool;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(AiUsage::class)]
final class AiUsageTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    public function testCountsTodaysCallsInTotalAndPerPool(): void
    {
        $budget = $this->prophesize(AiCallBudgetInterface::class);
        $budget->usedToday(null)->willReturn(12);
        $budget->usedToday(Argument::that(static fn (?CallPool $pool): bool => 'animals' === $pool?->name))->willReturn(9);
        $settings = $this->settingsProvider(['ai' => ['daily_call_limit' => 150, 'call_pools' => [
            'animals' => ['detection_types' => ['animal'], 'daily_call_limit' => 75],
        ]]]);

        self::assertSame([
            ['label' => 'AI calls today', 'used' => 12, 'limit' => 150],
            ['label' => 'Animals', 'used' => 9, 'limit' => 75],
        ], new AiUsage($budget->reveal(), $settings)->today());
    }
}
