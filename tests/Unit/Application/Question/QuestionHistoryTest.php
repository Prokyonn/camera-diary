<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Question;

use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Question\QuestionHistory;
use App\Application\Question\QuestionResult;
use App\Domain\Model\AskedQuestion;
use App\Domain\Repository\AskedQuestionRepositoryInterface;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Clock\MockClock;

#[CoversClass(QuestionHistory::class)]
final class QuestionHistoryTest extends TestCase
{
    use ProphecyTrait;

    public function testKeepsTheAnswerAndReadsTheEventsBackInOrder(): void
    {
        $cited = CameraEventFactory::described('Luna on the wall.', id: 7);
        $other = CameraEventFactory::described('A grey cat.', id: 3);
        $questions = $this->prophesize(AskedQuestionRepositoryInterface::class);
        $questions->add(Argument::type(AskedQuestion::class))->shouldBeCalledOnce();
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy(['ids' => [7, 3, 9]])->willReturn([$other, $cited]);
        $history = new QuestionHistory($questions->reveal(), $events->reveal(), $this->prophesize(UnitOfWorkInterface::class)->reveal(), new MockClock('2026-11-08 08:00:00'));

        $asked = $history->record('Was Luna out?', new QuestionResult('Yes, at 07:16.', [$cited, $other, CameraEventFactory::described('Gone.', id: 9)]));
        $result = $history->result($asked);

        self::assertSame('Was Luna out?', $asked->getQuestion());
        self::assertSame([7, 3, 9], $asked->getEventIds());
        self::assertSame('Yes, at 07:16.', $result->answer);
        self::assertSame([$cited, $other], $result->events, 'In the stored order; a deleted event is left out.');
    }
}
