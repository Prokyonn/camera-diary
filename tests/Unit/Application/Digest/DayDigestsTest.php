<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Digest;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Digest\DayDigests;
use App\Application\Digest\DigestNotWrittenException;
use App\Application\Digest\DigestWriterInterface;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\DayDigest;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\DayDigestRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DayDigests::class)]
final class DayDigestsTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DayDigestRepositoryInterface> */
    private ObjectProphecy $digests;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    /** @var ObjectProphecy<DigestWriterInterface> */
    private ObjectProphecy $writer;

    /** @var ObjectProphecy<AiCallBudgetInterface> */
    private ObjectProphecy $budget;

    protected function setUp(): void
    {
        $this->digests = $this->prophesize(DayDigestRepositoryInterface::class);
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->writer = $this->prophesize(DigestWriterInterface::class);
        $this->writer->modelName()->willReturn('test-model');
        $this->budget = $this->prophesize(AiCallBudgetInterface::class);
        $this->budget->tryConsume()->willReturn(true);
    }

    public function testRewritesAnOutdatedDigestOfTheLocalDay(): void
    {
        $existing = new DayDigest('2026-11-07', 'Old.', 1, 'old-model', new DateTimeImmutable('2026-11-07 10:00:00'));
        $this->digests->findOneBy(['day' => '2026-11-07'])->willReturn($existing);
        $this->digests->add(Argument::any())->shouldNotBeCalled();
        $this->events->findBy(Argument::cetera())->willReturn([CameraEventFactory::described('A cat.'), CameraEventFactory::described('A person.')]);
        $this->writer->summarize(Argument::that(static fn (DateTimeImmutable $day): bool => '2026-11-07 00:00 Europe/Vienna' === $day->format('Y-m-d H:i e')), Argument::size(2))->willReturn('Two visits.');

        $digest = $this->digests()->write(new DateTimeImmutable('2026-11-07 23:30:00', new DateTimeZone('Europe/Vienna')));

        self::assertSame($existing, $digest);
        self::assertSame('Two visits.', $digest->getText());
        self::assertSame(2, $digest->getEventCount());
    }

    public function testNeedsDescribedEvents(): void
    {
        $this->events->findBy(Argument::cetera())->willReturn([]);
        $this->budget->tryConsume()->shouldNotBeCalled();

        $this->expectException(DigestNotWrittenException::class);

        $this->digests()->write(new DateTimeImmutable('2026-11-07'));
    }

    public function testStopsAtTheDailyLimitAndOnAiFailures(): void
    {
        $this->events->findBy(Argument::cetera())->willReturn([CameraEventFactory::described('A cat.')]);
        $this->writer->summarize(Argument::cetera())->willThrow(new AiRequestFailedException('down'));

        try {
            $this->digests()->write(new DateTimeImmutable('2026-11-07'));
            self::fail('The AI failure was not reported.');
        } catch (DigestNotWrittenException $exception) {
            self::assertSame('The AI could not write the summary right now.', $exception->getMessage());
        }

        $this->budget->tryConsume()->willReturn(false);
        $this->expectExceptionMessageIsOrContains('The daily AI limit is reached');
        $this->digests()->write(new DateTimeImmutable('2026-11-07'));
    }

    private function digests(): DayDigests
    {
        return new DayDigests(
            $this->digests->reveal(),
            $this->events->reveal(),
            $this->writer->reveal(),
            $this->budget->reveal(),
            $this->prophesize(UnitOfWorkInterface::class)->reveal(),
            new HomeTimeZone('Europe/Vienna'),
            new MockClock('2026-11-08 08:00:00'),
            new NullLogger(),
        );
    }
}
