<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Ai;

use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Application\Description\PastCorrections;
use App\Application\Description\SceneBaselines;
use App\Application\Individual\ReferencePhotos;
use App\Application\Individual\ReferencePhotoStoreInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\Confidence;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Infrastructure\Ai\AiEventDescriber;
use App\Infrastructure\Ai\Client\PlatformStructuredClient;
use App\Infrastructure\Ai\CorrectionNotes;
use App\Infrastructure\Ai\HomeContext;
use App\Infrastructure\Ai\Output\VocabularyResponseFormatFactory;
use App\Infrastructure\Media\FilesystemFrameStorage;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\FakePlatform;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(AiEventDescriber::class)]
#[CoversClass(PlatformStructuredClient::class)]
#[CoversClass(HomeContext::class)]
#[CoversClass(CorrectionNotes::class)]
#[CoversClass(PastCorrections::class)]
#[CoversClass(SceneBaselines::class)]
final class AiEventDescriberTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    private string $framesDir;

    private FakePlatform $platform;

    /** @var ObjectProphecy<CameraEventRepositoryInterface> */
    private ObjectProphecy $events;

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->framesDir = sys_get_temp_dir().'/camera-diary-frames-'.bin2hex(random_bytes(4));
        $image = imagecreatetruecolor(4, 4);
        self::assertNotFalse($image);
        new Filesystem()->mkdir($this->framesDir.'/terrace');
        imagejpeg($image, $this->framesDir.'/terrace/frame-0.jpg');
        imagejpeg($image, $this->framesDir.'/luna.jpg');
        $this->events = $this->prophesize(CameraEventRepositoryInterface::class);
        $this->events->findBy(Argument::cetera())->willReturn([]);
        $this->platform = new FakePlatform(new VocabularyResponseFormatFactory(new ResponseFormatFactory(), $this->settings()));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->framesDir);
    }

    public function testSendsTheFramesAndReadsTheStructuredAnswer(): void
    {
        $this->platform->respondWith('{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"cat","individual":"luna","summary":"Luna drags the doormat.","unusual":true}');
        CameraFactory::get('terrace', 'Terrace with the cat flap on the left.');
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        $description = $this->describer()->describe($event, ['07:17:02 Luna came in through the cat flap']);

        self::assertSame(Verdict::Real, $description->verdict);
        self::assertNull($description->falseAlarmCause);
        self::assertSame(Side::OurProperty, $description->side);
        self::assertSame('cat', $description->subject);
        self::assertSame('luna', $description->individual);
        self::assertTrue($description->unusual);
        self::assertSame('test-model', $description->model);

        $call = $this->platform->calls()[0] ?? self::fail('The AI was not called.');
        self::assertSame('test-model', $call['model']);
        $input = json_encode($call['input'], \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('07:16:40', $input, 'The prompt names the local time.');
        self::assertStringContainsString('Luna came in', $input);
        self::assertStringContainsString('cat flap on the left', $input, 'The prompt describes the scene.');
        self::assertStringContainsString('Our cat is called Luna.', $input, 'The prompt carries the home context.');
        self::assertStringContainsString('Unusual events: A stranger on our property.', $input, 'The prompt carries the rule for unusual events.');
        $options = json_encode($call['options'], \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"enum":["none","spider_web","insect","rain","light","nothing","other","snail"]', $options, 'The JSON schema lists the false-alarm causes.');
        self::assertStringContainsString('fox: a red fox', $options, 'The JSON schema lists configured subjects with their hints.');
        self::assertStringContainsString('a spider, its web or a single thread', $input, 'The prompt names the configured false-alarm causes.');
        self::assertStringContainsString('Reference photos of the known individual \\"luna\\" (Luna, a cat): grey-brown tabby. Not part of the event.', $input, 'The reference photos come with the event.');
        self::assertStringContainsString('"enum":["unknown","luna"]', $options, 'The JSON schema lists the known individuals.');
        self::assertSame(2, substr_count($input, '"type":"image_url"'), 'The reference photo and the frame.');
        self::assertLessThan(strpos($input, 'The camera reported'), strpos($input, 'Reference photos'), 'The reference photos come before the event.');
    }

    public function testComparesWithTheUsualViewAndLearnsFromCorrections(): void
    {
        $this->platform->respondWith('{"verdict":"false_alarm","falseAlarmCause":"other","side":"our_property","subject":"nothing","individual":"unknown","summary":"The hedge moves in the wind.","unusual":false,"confidence":"medium"}');
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);
        $empty = CameraEventFactory::described('The empty terrace.', '2026-11-05 22:00:00', verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'nothing', id: 2);
        $empty->recordFrames(['terrace/frame-0.jpg'], 4, 4);
        $hedge = CameraEventFactory::described('A person in dark clothes at the hedge.', '2026-11-04 21:10:00', subject: 'person', id: 3);
        $hedge->relabel('the hedge in the wind', Verdict::FalseAlarm, null, 'other');
        $hedge->markReviewed(CameraEventFactory::utc('2026-11-05 08:00:00'));
        $this->events->findBy(Argument::withEntry('falseAlarm', true), Argument::cetera())->willReturn([$event, $empty]);
        $this->events->findBy(Argument::withEntry('reviewed', true), Argument::cetera())->willReturn([$hedge]);

        $description = $this->describer()->describe($event, []);

        self::assertSame(Confidence::Medium, $description->confidence);
        $input = json_encode($this->platform->calls()[0]['input'] ?? self::fail('The AI was not called.'), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('usual view with nothing happening, from 5 November, 23:00', $input);
        self::assertSame(3, substr_count($input, '"type":"image_url"'), 'The reference photo, the usual view and the frame.');
        self::assertStringContainsString('- Wed 4 Nov, 22:10: you said real, person: \\"A person in dark clothes at the hedge.\\" The owner: false alarm; cause: other; labelled it \\"the hedge in the wind\\".', $input);
    }

    public function testDropsAnIndividualOfAnotherSubject(): void
    {
        $this->platform->respondWith('{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"dog","individual":"luna","summary":"A dog.","unusual":false}');
        $event = CameraEventFactory::create(id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        self::assertNull($this->describer()->describe($event, [])->individual);
    }

    public function testAcceptsAConfiguredSubjectAndCause(): void
    {
        $this->platform->respondWith('{"verdict":"false_alarm","falseAlarmCause":"snail","side":"our_property","subject":"nothing","individual":"unknown","summary":"A snail crosses the lens.","unusual":false}');
        $event = CameraEventFactory::create(id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        $description = $this->describer()->describe($event, []);

        self::assertSame('snail', $description->falseAlarmCause);
    }

    public function testRejectsASubjectOutsideTheVocabulary(): void
    {
        $this->platform->respondWith('{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"unicorn","individual":"unknown","summary":"A unicorn.","unusual":false}');
        $event = CameraEventFactory::create(id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        $this->expectException(AiRequestFailedException::class);

        $this->describer()->describe($event, []);
    }

    public function testRejectsAnIndividualOutsideTheSettings(): void
    {
        $this->platform->respondWith('{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"cat","individual":"garfield","summary":"A cat.","unusual":false}');
        $event = CameraEventFactory::create(id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        $this->expectException(AiRequestFailedException::class);

        $this->describer()->describe($event, []);
    }

    public function testWrapsPlatformErrors(): void
    {
        $this->platform->respondWith(new RateLimitExceededException());
        $event = CameraEventFactory::create(id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        $this->expectException(AiRequestFailedException::class);

        $this->describer()->describe($event, []);
    }

    private function describer(): AiEventDescriber
    {
        $homeTimeZone = new HomeTimeZone('Europe/Vienna');
        $frameStorage = new FilesystemFrameStorage($this->framesDir, $homeTimeZone);
        $store = $this->prophesize(ReferencePhotoStoreInterface::class);
        $store->findBy('luna')->willReturn([]);

        return new AiEventDescriber(
            new PlatformStructuredClient($this->platform, 'test-model'),
            $frameStorage,
            $homeTimeZone,
            new HomeContext($this->prophesize(CameraRepositoryInterface::class)->reveal(), $this->settings()),
            $this->settings(),
            new ReferencePhotos($this->settings(), $store->reveal(), $frameStorage),
            new PastCorrections($this->events->reveal(), $this->settings()),
            new SceneBaselines($this->events->reveal(), $frameStorage, $this->settings()),
            new CorrectionNotes($homeTimeZone, $this->settings()),
        );
    }

    private function settings(): SettingsProviderInterface
    {
        return $this->settingsProvider([
            'ai' => ['home_context' => 'Our cat is called Luna.', 'unusual_rule' => 'A stranger on our property.'],
            'subjects' => ['fox' => ['description' => 'a red fox']],
            'false_alarm_causes' => ['snail' => null],
            'individuals' => ['luna' => ['subject' => 'cat', 'description' => 'grey-brown tabby', 'photos' => [$this->framesDir.'/luna.jpg']]],
        ]);
    }
}
