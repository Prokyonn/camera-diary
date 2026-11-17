<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Description\HintCollector;
use App\Infrastructure\Ai\AiAnswerWriter;
use App\Infrastructure\Ai\Client\PlatformStructuredClient;
use App\Infrastructure\Media\FilesystemFrameStorage;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\FakePlatform;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(AiAnswerWriter::class)]
final class AiAnswerWriterTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    private string $framesDir;

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->framesDir = sys_get_temp_dir().'/camera-diary-answer-'.bin2hex(random_bytes(4));
        $image = imagecreatetruecolor(4, 4);
        self::assertNotFalse($image);
        new Filesystem()->mkdir($this->framesDir.'/terrace');
        foreach (['1781000000', '1781000060'] as $time) {
            imagejpeg($image, $this->framesDir.'/terrace/frame-'.$time.'-0.jpg');
        }
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->framesDir);
    }

    public function testReadsAllEventsAsTextAndTheFramesOfTheFirst(): void
    {
        $platform = new FakePlatform();
        $platform->respondWith('{"text":"Anna was on the terrace twice.","eventIds":[1,2]}');
        $first = CameraEventFactory::described('Anna carries a bucket.', '@1781000000', subject: 'person', id: 1, individual: 'anna');
        $second = CameraEventFactory::described('A cat walks by.', '@1781000060', id: 2);
        $homeTimeZone = new HomeTimeZone('Europe/Vienna');
        $writer = new AiAnswerWriter(
            new PlatformStructuredClient($platform, 'test-model'),
            new HintCollector([]),
            new FilesystemFrameStorage($this->framesDir, $homeTimeZone),
            $homeTimeZone,
            $this->settingsProvider([
                'questions' => ['answer_events' => 1],
                'individuals' => ['anna' => ['subject' => 'person'], 'luna' => ['subject' => 'cat']],
            ]),
        );

        $answer = $writer->answer('How often was Anna on the terrace?', [$first, $second], 5);

        self::assertSame([1, 2], $answer->eventIds);
        $input = json_encode(($platform->calls()[0] ?? self::fail('The AI was not called.'))['input'], \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('5 events match; here are the 2 most relevant.', $input);
        self::assertStringContainsString('Subject: Anna', $input);
        self::assertStringContainsString('Subject: Other cat', $input);
        self::assertStringContainsString('A cat walks by.', $input, 'Events beyond answer_events are read as text.');
        self::assertSame(1, substr_count($input, '"type":"image_url"'), 'Only the first event comes with its frame.');
    }
}
