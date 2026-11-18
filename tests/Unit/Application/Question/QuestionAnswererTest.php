<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Question;

use App\Application\Ai\AiCallBudgetInterface;
use App\Application\Ai\AiRequestFailedException;
use App\Application\Question\Answer;
use App\Application\Question\AnswerWriterInterface;
use App\Application\Question\QuestionAnswerer;
use App\Application\Question\QuestionPlannerInterface;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Application\Search\SearchResult;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;

#[CoversClass(QuestionAnswerer::class)]
final class QuestionAnswererTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    /** @var ObjectProphecy<QuestionPlannerInterface> */
    private ObjectProphecy $planner;

    /** @var ObjectProphecy<AnswerWriterInterface> */
    private ObjectProphecy $writer;

    /** @var ObjectProphecy<EventSearchInterface> */
    private ObjectProphecy $search;

    /** @var ObjectProphecy<AiCallBudgetInterface> */
    private ObjectProphecy $budget;

    protected function setUp(): void
    {
        $this->planner = $this->prophesize(QuestionPlannerInterface::class);
        $this->writer = $this->prophesize(AnswerWriterInterface::class);
        $this->search = $this->prophesize(EventSearchInterface::class);
        $this->budget = $this->prophesize(AiCallBudgetInterface::class);
        $this->budget->tryConsume()->willReturn(true);
    }

    public function testAnswersWithTheCitedEventsFirst(): void
    {
        $criteria = new SearchCriteria(text: 'grey cat');
        $first = CameraEventFactory::described('A black cat.', id: 1);
        $second = CameraEventFactory::described('A grey cat drags the doormat.', id: 2);
        $this->planner->plan('When was the grey cat here?', 12)->willReturn($criteria);
        $this->search->search($criteria)->willReturn(new SearchResult([$first, $second], 2));
        $this->writer->answer('When was the grey cat here?', [$first, $second], 2, false)->willReturn(new Answer('On 6 November at 07:16.', [2]));

        $result = $this->answerer()->answer('When was the grey cat here?');

        self::assertSame('On 6 November at 07:16.', $result->answer);
        self::assertSame([$second, $first], $result->events);
    }

    public function testSaysSoWhenNothingMatches(): void
    {
        $this->planner->plan(Argument::cetera())->willReturn(new SearchCriteria(text: 'dog'));
        $this->search->search(Argument::any())->willReturn(new SearchResult([], 0));
        $this->writer->answer(Argument::cetera())->shouldNotBeCalled();

        self::assertSame('No matching events found.', $this->answerer()->answer('Was a dog here?')->answer);
    }

    public function testSearchesTheQuestionTextWhenPlanningFails(): void
    {
        $this->planner->plan(Argument::cetera())->willThrow(new AiRequestFailedException('timeout'));
        $this->search->search(Argument::that(static fn (SearchCriteria $criteria): bool => 'grey cat' === $criteria->text))->willReturn(new SearchResult([], 0))->shouldBeCalledOnce();
        $this->search->search(Argument::that(static fn (SearchCriteria $criteria): bool => $criteria->isBroad()))->willReturn(new SearchResult([], 0));

        $this->answerer()->answer('grey cat');
    }

    public function testWidensASearchThatFoundNothing(): void
    {
        $from = CameraEventFactory::utc('2026-11-16 00:00:00');
        $fox = CameraEventFactory::described('A fox on the patio.', '2026-11-16 23:10:00', 'patio', subject: 'fox', id: 3);
        $this->planner->plan(Argument::cetera())->willReturn(new SearchCriteria(cameras: ['garden'], from: $from, subjects: ['fox']));
        $this->search->search(Argument::that(static fn (SearchCriteria $criteria): bool => ['garden'] === $criteria->cameras))->willReturn(new SearchResult([], 0));
        $this->search->search(Argument::that(static fn (SearchCriteria $criteria): bool => $criteria->isBroad() && $from === $criteria->from))->willReturn(new SearchResult([$fox], 1));
        $this->writer->answer('Which animals were in the garden this week?', [$fox], 1, true)->willReturn(new Answer('Not on the Garden camera, but a fox was on the patio.', [3]))->shouldBeCalledOnce();

        $result = $this->answerer()->answer('Which animals were in the garden this week?');

        self::assertSame('Not on the Garden camera, but a fox was on the patio.', $result->answer);
        self::assertSame([$fox], $result->events);
    }

    public function testListsTheMatchesWhenTheBudgetIsUsedUp(): void
    {
        $event = CameraEventFactory::described('A person behind the fence.', id: 4);
        $this->budget->tryConsume()->willReturn(false);
        $this->planner->plan(Argument::cetera())->shouldNotBeCalled();
        $this->search->search(Argument::any())->willReturn(new SearchResult([$event], 1));

        $result = $this->answerer()->answer('person behind the fence');

        self::assertStringContainsString('daily AI limit', $result->answer);
        self::assertSame([$event], $result->events);
    }

    public function testListsTheMatchesWhenAnsweringFails(): void
    {
        $event = CameraEventFactory::described('A person behind the fence.', id: 4);
        $this->planner->plan(Argument::cetera())->willReturn(new SearchCriteria());
        $this->search->search(Argument::any())->willReturn(new SearchResult([$event], 1));
        $this->writer->answer(Argument::cetera())->willThrow(new AiRequestFailedException('429'));

        $result = $this->answerer()->answer('Who was behind the fence?');

        self::assertStringContainsString('could not answer', $result->answer);
        self::assertSame([$event], $result->events);
    }

    public function testReadsAllEventsOfATimeRangeWithTheKeywordMatchesFirst(): void
    {
        $criteria = new SearchCriteria(text: 'orange bucket', from: CameraEventFactory::utc('2026-11-17 00:00:00'), subjects: ['person']);
        $walk = CameraEventFactory::described('Sam walks across the lawn.', id: 1);
        $bucket = CameraEventFactory::described('Sam carries an orange bucket.', id: 2);
        $this->planner->plan(Argument::cetera())->willReturn($criteria);
        $this->search->search($criteria->withText('')->withSubjects([])->withLimit(200))->willReturn(new SearchResult([$walk, $bucket], 2));
        $this->search->search($criteria->withLimit(6))->willReturn(new SearchResult([$bucket], 1));
        $this->writer->answer('How often was Sam outside today?', [$bucket, $walk], 2, false)->willReturn(new Answer('Twice.', []))->shouldBeCalledOnce();

        self::assertSame('Twice.', $this->answerer()->answer('How often was Sam outside today?')->answer);
    }

    public function testASubjectOnlyRanksTheEventsOfATimeRange(): void
    {
        $criteria = new SearchCriteria(from: CameraEventFactory::utc('2026-11-12 00:00:00'), subjects: ['other_animal']);
        $squirrel = CameraEventFactory::described('A grey squirrel on the patio.', subject: 'other_animal', id: 1);
        $marten = CameraEventFactory::described('A marten at the feeding box.', subject: 'marten', id: 2);
        $this->planner->plan(Argument::cetera())->willReturn($criteria);
        $this->search->search($criteria->withSubjects([])->withLimit(200))->willReturn(new SearchResult([$marten, $squirrel], 2));
        $this->search->search($criteria->withLimit(6))->willReturn(new SearchResult([$squirrel], 1));
        $this->writer->answer('Which wild animals came by?', [$squirrel, $marten], 2, false)->willReturn(new Answer('A squirrel and a marten.', [1, 2]))->shouldBeCalledOnce();

        self::assertSame('A squirrel and a marten.', $this->answerer()->answer('Which wild animals came by?')->answer, 'The marten is not hidden by a subject picked too narrowly.');
    }

    public function testFallsBackToTheKeywordMatchesForABusyTimeRange(): void
    {
        $criteria = new SearchCriteria(text: 'spider', from: CameraEventFactory::utc('2026-11-06 00:00:00'), includeFalseAlarms: true, limit: 12);
        $web = CameraEventFactory::described('A spider web.', id: 3);
        $this->planner->plan(Argument::cetera())->willReturn($criteria);
        $this->search->search($criteria->withText('')->withSubjects([])->withLimit(200))->willReturn(new SearchResult([], 800));
        $this->search->search($criteria)->willReturn(new SearchResult([$web], 40));
        $this->writer->answer(Argument::any(), [$web], 40, false)->willReturn(new Answer('A spider web.', [3]))->shouldBeCalledOnce();

        $this->answerer()->answer('Spider webs last week?');
    }

    private function answerer(): QuestionAnswerer
    {
        return new QuestionAnswerer($this->planner->reveal(), $this->writer->reveal(), $this->search->reveal(), $this->budget->reveal(), $this->settingsProvider(), new NullLogger());
    }
}
