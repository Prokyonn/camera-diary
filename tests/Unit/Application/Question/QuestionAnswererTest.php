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
        $this->search->search($criteria)->willReturn([$first, $second]);
        $this->writer->answer('When was the grey cat here?', [$first, $second])->willReturn(new Answer('On 6 November at 07:16.', [2]));

        $result = $this->answerer()->answer('When was the grey cat here?');

        self::assertSame('On 6 November at 07:16.', $result->answer);
        self::assertSame([$second, $first], $result->events);
    }

    public function testSaysSoWhenNothingMatches(): void
    {
        $this->planner->plan(Argument::cetera())->willReturn(new SearchCriteria(text: 'dog'));
        $this->search->search(Argument::any())->willReturn([]);
        $this->writer->answer(Argument::cetera())->shouldNotBeCalled();

        self::assertSame('No matching events found.', $this->answerer()->answer('Was a dog here?')->answer);
    }

    public function testSearchesTheQuestionTextWhenPlanningFails(): void
    {
        $this->planner->plan(Argument::cetera())->willThrow(new AiRequestFailedException('timeout'));
        $this->search->search(Argument::that(static fn (SearchCriteria $criteria): bool => 'grey cat' === $criteria->text))->willReturn([])->shouldBeCalledOnce();

        $this->answerer()->answer('grey cat');
    }

    public function testListsTheMatchesWhenTheBudgetIsUsedUp(): void
    {
        $event = CameraEventFactory::described('A person behind the fence.', id: 4);
        $this->budget->tryConsume()->willReturn(false);
        $this->planner->plan(Argument::cetera())->shouldNotBeCalled();
        $this->search->search(Argument::any())->willReturn([$event]);

        $result = $this->answerer()->answer('person behind the fence');

        self::assertStringContainsString('daily AI limit', $result->answer);
        self::assertSame([$event], $result->events);
    }

    public function testListsTheMatchesWhenAnsweringFails(): void
    {
        $event = CameraEventFactory::described('A person behind the fence.', id: 4);
        $this->planner->plan(Argument::cetera())->willReturn(new SearchCriteria());
        $this->search->search(Argument::any())->willReturn([$event]);
        $this->writer->answer(Argument::cetera())->willThrow(new AiRequestFailedException('429'));

        $result = $this->answerer()->answer('Who was behind the fence?');

        self::assertStringContainsString('could not answer', $result->answer);
        self::assertSame([$event], $result->events);
    }

    private function answerer(): QuestionAnswerer
    {
        return new QuestionAnswerer($this->planner->reveal(), $this->writer->reveal(), $this->search->reveal(), $this->budget->reveal(), $this->settingsProvider(), new NullLogger());
    }
}
