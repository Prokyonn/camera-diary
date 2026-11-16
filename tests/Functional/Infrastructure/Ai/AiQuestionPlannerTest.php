<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Domain\Model\Side;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Infrastructure\Ai\AiQuestionPlanner;
use App\Infrastructure\Ai\Client\PlatformStructuredClient;
use App\Infrastructure\Ai\HomeContext;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\FakePlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AiQuestionPlanner::class)]
final class AiQuestionPlannerTest extends TestCase
{
    use ProphecyTrait;

    public function testTurnsThePlanIntoSearchCriteria(): void
    {
        CameraFactory::reset();
        $cameras = $this->prophesize(CameraRepositoryInterface::class);
        $cameras->findBy(Argument::cetera())->willReturn([CameraFactory::get('garden', 'Garden with a fence at the back.'), CameraFactory::get('terrace')]);
        $platform = new FakePlatform();
        $platform->respondWith('{"keywords":"person","cameras":["garden","attic"],"from":"2026-11-07T18:00:00+01:00","to":"","side":"outside","includeFalseAlarms":false}');
        $planner = new AiQuestionPlanner(
            new PlatformStructuredClient($platform, 'test-model'),
            new HomeTimeZone('Europe/Vienna'),
            new HomeContext($cameras->reveal(), '', ''),
            new MockClock('2026-11-08 10:00:00', 'UTC'),
        );

        $criteria = $planner->plan('Who was behind the fence last night?', 12);

        self::assertSame('person', $criteria->text);
        self::assertSame(['garden'], $criteria->cameras, 'Unknown cameras are dropped.');
        self::assertSame('2026-11-07 17:00:00', $criteria->from?->format('Y-m-d H:i:s'), 'Times are converted to UTC.');
        self::assertNull($criteria->to);
        self::assertSame(Side::Outside, $criteria->side);
        self::assertSame(12, $criteria->limit);
        $input = json_encode(($platform->calls()[0] ?? self::fail('The AI was not called.'))['input'], \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Sunday, 8 November 2026, 11:00', $input);
        self::assertStringContainsString('Garden (garden). Garden with a fence at the back.', $input);
    }
}
