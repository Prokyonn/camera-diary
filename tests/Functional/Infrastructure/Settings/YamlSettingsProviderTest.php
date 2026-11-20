<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Settings;

use App\Application\Settings\CallPool;
use App\Application\Settings\InvalidSettingsException;
use App\Infrastructure\Settings\YamlSettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(YamlSettingsProvider::class)]
final class YamlSettingsProviderTest extends TestCase
{
    use ProphecyTrait;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/camera-diary-settings-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->path);
    }

    public function testReadsTheExampleFile(): void
    {
        $settings = new YamlSettingsProvider(\dirname(__DIR__, 4).'/camera-diary.example.yaml', new NullLogger())->settings();

        self::assertSame(150, $settings->ai->dailyCallLimit);
        self::assertSame(['people_and_vehicles', 'animals'], array_map(static fn (CallPool $pool): string => $pool->name, $settings->ai->callPools));
        self::assertTrue($settings->subjects->has('fox'));
    }

    public function testResolvesPhotosRelativeToTheFile(): void
    {
        $fixtures = \dirname(__DIR__, 3).'/Fixtures';
        $settings = new YamlSettingsProvider($fixtures.'/camera-diary.yaml', new NullLogger())->settings();

        self::assertSame([$fixtures.'/individuals/luna.jpg'], $settings->individual('luna')?->photos);
    }

    public function testUsesTheDefaultsWithoutAFile(): void
    {
        self::assertSame(500, new YamlSettingsProvider($this->path, new NullLogger())->settings()->ai->dailyCallLimit);
        self::assertSame(500, new YamlSettingsProvider('', new NullLogger())->settings()->ai->dailyCallLimit);
    }

    public function testReadsTheFileAgainWhenItChanges(): void
    {
        $provider = new YamlSettingsProvider($this->path, new NullLogger());
        new Filesystem()->dumpFile($this->path, "ai:\n    daily_call_limit: 10\n");
        self::assertSame(10, $provider->settings()->ai->dailyCallLimit);

        new Filesystem()->dumpFile($this->path, "ai:\n    daily_call_limit: 20\n");
        touch($this->path, time() + 5);

        self::assertSame(20, $provider->settings()->ai->dailyCallLimit);
    }

    public function testKeepsTheLastValidSettingsWhenAnEditBreaksTheFile(): void
    {
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->error(Argument::containingString('Keeping the last valid settings'), Argument::type('array'))->shouldBeCalledOnce();
        $provider = new YamlSettingsProvider($this->path, $logger->reveal());
        new Filesystem()->dumpFile($this->path, "ai:\n    daily_call_limit: 10\n");
        touch($this->path, time() - 10);
        self::assertSame(10, $provider->settings()->ai->dailyCallLimit);

        new Filesystem()->dumpFile($this->path, "ai: [\n");
        touch($this->path, time() - 5);

        self::assertSame(10, $provider->settings()->ai->dailyCallLimit);
        self::assertSame(10, $provider->settings()->ai->dailyCallLimit, 'The broken file is not read and logged again until it changes.');

        new Filesystem()->dumpFile($this->path, "ai:\n    daily_call_limit: 30\n");
        touch($this->path, time());
        self::assertSame(30, $provider->settings()->ai->dailyCallLimit);
    }

    public function testNamesTheFileOfInvalidSettings(): void
    {
        new Filesystem()->dumpFile($this->path, "ai: [\n");

        try {
            new YamlSettingsProvider($this->path, new NullLogger())->settings();
            self::fail('Invalid YAML was accepted.');
        } catch (InvalidSettingsException $exception) {
            self::assertSame(1, substr_count($exception->getMessage(), \sprintf('Invalid settings in "%s"', $this->path)), 'The file is named once.');
        }
    }
}
