<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Ai;

use App\Application\Ai\AiRequestFailedException;
use App\Application\Clock\HomeTimeZone;
use App\Domain\Model\FalseAlarmCause;
use App\Domain\Model\Side;
use App\Domain\Model\Subject;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Infrastructure\Ai\AiEventDescriber;
use App\Infrastructure\Ai\Client\PlatformStructuredClient;
use App\Infrastructure\Ai\HomeContext;
use App\Infrastructure\Media\FilesystemFrameStorage;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\FakePlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(AiEventDescriber::class)]
#[CoversClass(PlatformStructuredClient::class)]
#[CoversClass(HomeContext::class)]
final class AiEventDescriberTest extends TestCase
{
    use ProphecyTrait;

    private string $framesDir;

    private FakePlatform $platform;

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->framesDir = sys_get_temp_dir().'/camera-diary-frames-'.bin2hex(random_bytes(4));
        $image = imagecreatetruecolor(4, 4);
        self::assertNotFalse($image);
        new Filesystem()->mkdir($this->framesDir.'/terrace');
        imagejpeg($image, $this->framesDir.'/terrace/frame-0.jpg');
        $this->platform = new FakePlatform();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->framesDir);
    }

    public function testSendsTheFramesAndReadsTheStructuredAnswer(): void
    {
        $this->platform->respondWith('{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"cat","summary":"A grey cat drags the doormat.","unusual":true}');
        CameraFactory::get('terrace', 'Terrace with the cat flap on the left.');
        $event = CameraEventFactory::create('2026-11-06 06:16:40', id: 1);
        $event->recordFrames(['terrace/frame-0.jpg'], 4, 4);

        $description = $this->describer()->describe($event, ['07:17:02 Luna came in through the cat flap']);

        self::assertSame(Verdict::Real, $description->verdict);
        self::assertSame(FalseAlarmCause::None, $description->falseAlarmCause);
        self::assertSame(Side::OurProperty, $description->side);
        self::assertSame(Subject::Cat, $description->subject);
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
        self::assertStringContainsString('spider_web', json_encode($call['options'], \JSON_THROW_ON_ERROR), 'The JSON schema lists the false-alarm causes.');
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

        return new AiEventDescriber(
            new PlatformStructuredClient($this->platform, 'test-model'),
            new FilesystemFrameStorage($this->framesDir, $homeTimeZone),
            $homeTimeZone,
            new HomeContext($this->prophesize(CameraRepositoryInterface::class)->reveal(), 'Our cat is called Luna.', 'A stranger on our property.'),
        );
    }
}
