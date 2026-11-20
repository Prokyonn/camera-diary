<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Demo;

use App\Application\Camera\CameraRegistry;
use App\Application\Camera\CameraSettings;
use App\Application\Clock\HomeTimeZone;
use App\Application\Demo\DemoLoader;
use App\Application\Demo\InvalidDemoException;
use App\Application\Demo\LoadedDemoEvent;
use App\Application\Description\DescriptionReplay;
use App\Application\Event\CameraEventLabeler;
use App\Application\Frame\FrameExtractionFailedException;
use App\Application\Frame\FrameExtractorInterface;
use App\Application\Frame\FrameStorageInterface;
use App\Application\Ingest\EventIngester;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Application\Question\QuestionHistory;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\AskedQuestion;
use App\Domain\Model\Camera;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Confidence;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Verdict;
use App\Domain\Repository\AskedQuestionRepositoryInterface;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\ProvidesSettings;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * @phpstan-import-type Manifest from DemoLoader
 */
#[CoversClass(DemoLoader::class)]
final class DemoLoaderTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    /** @var ObjectProphecy<FrameExtractorInterface> */
    private ObjectProphecy $extractor;

    /** @var ObjectProphecy<AskedQuestionRepositoryInterface> */
    private ObjectProphecy $askedQuestions;

    /** @var array<string, CameraEvent> the events the loader stored, by external id */
    private array $stored = [];

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->stored = [];
        $this->extractor = $this->prophesize(FrameExtractorInterface::class);
        $this->askedQuestions = $this->prophesize(AskedQuestionRepositoryInterface::class);
        $this->askedQuestions->findBy(Argument::cetera())->willReturn([]);
        $this->askedQuestions->add(Argument::type(AskedQuestion::class));
    }

    public function testStoresTheRecordedAnswersCorrectionsAndQuestions(): void
    {
        $this->askedQuestions->add(Argument::that(static fn (AskedQuestion $asked): bool => 'Who came by?' === $asked->getQuestion() && [1] === $asked->getEventIds()))->shouldBeCalledOnce();

        $loaded = $this->loader()->load($this->manifest(), '/demo', true);

        self::assertCount(2, $loaded);
        self::assertSame(['demo-flap', 'terrace', true, EventStatus::Described], [$loaded[0]->key, $loaded[0]->camera, $loaded[0]->created, $loaded[0]->status]);
    }

    public function testTurnsTheAiWordsForNothingIntoNull(): void
    {
        $this->loader()->load($this->manifest(), '/demo', true);

        $flap = $this->stored['demo-flap'] ?? self::fail('demo-flap was not stored.');
        self::assertNull($flap->getFalseAlarmCause());
        self::assertSame('luna', $flap->getIndividual());
        self::assertSame(['anna'], $flap->getAlsoSeen());
        self::assertSame(Confidence::Medium, $flap->getConfidence());
        self::assertSame('Luna after the rain', $flap->getLabel());
        self::assertSame('gemini (recorded)', $flap->getModel());

        $web = $this->stored['demo-web'] ?? self::fail('demo-web was not stored.');
        self::assertSame('spider_web', $web->getFalseAlarmCause());
        self::assertNull($web->getIndividual());
        self::assertSame(Verdict::FalseAlarm, $web->getVerdict());
    }

    public function testLeavesTheEventsPendingWithoutTheRecordedFlag(): void
    {
        $this->askedQuestions->add(Argument::any())->shouldNotBeCalled();

        $loaded = $this->loader()->load($this->manifest(), '/demo', false);

        self::assertSame([EventStatus::Pending, EventStatus::Pending], array_map(static fn (LoadedDemoEvent $event): EventStatus => $event->status, $loaded));
    }

    public function testRejectsARepeatOfAnEventThatDoesNotComeBefore(): void
    {
        $manifest = $this->manifest(repeatOf: 'demo-later');

        $this->expectException(InvalidDemoException::class);
        $this->expectExceptionMessageIsOrContains('Event "demo-web" repeats "demo-later", which is no earlier event.');

        $this->loader()->load($manifest, '/demo', true);
    }

    public function testNamesTheEventWhoseMediaCannotBeRead(): void
    {
        $this->extractor->extract(Argument::cetera())->willThrow(new FrameExtractionFailedException('Invalid data'));

        $this->expectException(InvalidDemoException::class);
        $this->expectExceptionMessageIsOrContains('Event "demo-flap": The media could not be read: Invalid data');

        $this->loader()->load($this->manifest(), '/demo', false);
    }

    /**
     * @return Manifest
     */
    private function manifest(?string $repeatOf = null): array
    {
        return [
            'cameras' => ['terrace' => ['name' => 'Terrace', 'scene' => 'The terrace.'], 'shed' => ['name' => 'Shed', 'scene' => 'The shed door.']],
            'events' => [
                'demo-flap' => [
                    'camera' => 'terrace',
                    'clip' => 'flap.jpg',
                    'time' => '21:40',
                    'days_ago' => 2,
                    'detections' => ['animal'],
                    'hints' => [],
                    'recorded' => ['model' => 'gemini', 'verdict' => 'real', 'false_alarm_cause' => 'none', 'side' => 'our_property', 'subject' => 'cat', 'individual' => 'luna', 'also_seen' => ['anna'], 'summary' => 'Luna comes in.', 'unusual' => false, 'confidence' => 'medium'],
                    'correction' => ['verdict' => null, 'individual' => null, 'label' => 'Luna after the rain', 'false_alarm_cause' => null],
                ],
                'demo-web' => [
                    'camera' => 'shed',
                    'clip' => 'web.jpg',
                    'time' => '01:15',
                    'days_ago' => 0,
                    'detections' => [],
                    'hints' => [],
                    'recorded' => ['model' => 'gemini', 'verdict' => 'false_alarm', 'false_alarm_cause' => 'spider_web', 'side' => 'our_property', 'subject' => 'nothing', 'individual' => 'unknown', 'also_seen' => [], 'summary' => 'A spider.', 'unusual' => false, 'confidence' => 'high'],
                    ...(null === $repeatOf ? [] : ['repeat_of' => $repeatOf]),
                ],
            ],
            'questions' => [['question' => 'Who came by?', 'answer' => 'Luna.', 'events' => ['demo-flap']]],
        ];
    }

    private function loader(): DemoLoader
    {
        $stored = &$this->stored;
        $settings = $this->settingsProvider(['individuals' => ['luna' => ['subject' => 'cat']]]);
        $clock = new MockClock('2026-11-08 12:00:00', 'UTC');
        $uow = $this->prophesize(UnitOfWorkInterface::class)->reveal();
        $indexer = $this->prophesize(EventIndexerInterface::class)->reveal();

        $cameraRepository = $this->prophesize(CameraRepositoryInterface::class);
        $cameraRepository->findOneBy(Argument::cetera())->will(static function (array $arguments): Camera {
            $slug = \is_array($arguments[0] ?? null) ? ($arguments[0]['slug'] ?? null) : null;

            return CameraFactory::get(\is_string($slug) ? $slug : throw new LogicException('findOneBy() expects a slug.'));
        });
        $registry = new CameraRegistry($cameraRepository->reveal(), $uow, new LockFactory(new InMemoryStore()));

        $eventRepository = $this->prophesize(CameraEventRepositoryInterface::class);
        $eventRepository->findOneBy(Argument::cetera())->willReturn(null);
        $eventRepository->remove(Argument::type(CameraEvent::class));
        $eventRepository->add(Argument::type(CameraEvent::class))->will(static function (array $arguments) use (&$stored): void {
            $event = $arguments[0] ?? null;
            if (!$event instanceof CameraEvent) {
                throw new LogicException('add() expects an event.');
            }

            new ReflectionProperty(CameraEvent::class, 'id')->setValue($event, \count($stored) + 1);
            $stored[(string) $event->getExternalId()] = $event;
        });

        $storage = $this->prophesize(FrameStorageInterface::class);
        $storage->relativePathFor(Argument::cetera())->willReturn('terrace/frame.jpg');
        $storage->prepare(Argument::any())->willReturn('/frames/terrace/frame.jpg');
        $storage->imageSize(Argument::any())->willReturn([640, 360]);
        $storage->remove(Argument::type('array'));

        $ingester = new EventIngester($registry, $eventRepository->reveal(), $this->extractor->reveal(), $storage->reveal(), $indexer, $uow, $settings, new LockFactory(new InMemoryStore()));
        $questions = new QuestionHistory($this->askedQuestions->reveal(), $eventRepository->reveal(), $uow, $clock);

        return new DemoLoader(
            new CameraSettings($registry, $uow),
            $ingester,
            new DescriptionReplay($uow, $indexer, $clock),
            new CameraEventLabeler($uow, $indexer, $clock, $settings),
            $questions,
            $clock,
            new HomeTimeZone('Europe/Vienna'),
        );
    }
}
