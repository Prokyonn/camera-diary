<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Settings;

use App\Application\Settings\CallPool;
use App\Application\Settings\InvalidSettingsException;
use App\Infrastructure\Settings\YamlSettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(YamlSettingsProvider::class)]
final class YamlSettingsProviderTest extends TestCase
{
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
        $settings = new YamlSettingsProvider(\dirname(__DIR__, 4).'/camera-diary.example.yaml')->settings();

        self::assertSame(150, $settings->ai->dailyCallLimit);
        self::assertSame(['people_and_vehicles', 'animals'], array_map(static fn (CallPool $pool): string => $pool->name, $settings->ai->callPools));
        self::assertTrue($settings->subjects->has('fox'));
    }

    public function testUsesTheDefaultsWithoutAFile(): void
    {
        self::assertSame(500, new YamlSettingsProvider($this->path)->settings()->ai->dailyCallLimit);
        self::assertSame(500, new YamlSettingsProvider('')->settings()->ai->dailyCallLimit);
    }

    public function testReadsTheFileAgainWhenItChanges(): void
    {
        $provider = new YamlSettingsProvider($this->path);
        new Filesystem()->dumpFile($this->path, "ai:\n    daily_call_limit: 10\n");
        self::assertSame(10, $provider->settings()->ai->dailyCallLimit);

        new Filesystem()->dumpFile($this->path, "ai:\n    daily_call_limit: 20\n");
        touch($this->path, time() + 5);

        self::assertSame(20, $provider->settings()->ai->dailyCallLimit);
    }

    public function testNamesTheFileOfInvalidSettings(): void
    {
        new Filesystem()->dumpFile($this->path, "ai: [\n");

        $this->expectException(InvalidSettingsException::class);
        $this->expectExceptionMessageIsOrContains($this->path);

        new YamlSettingsProvider($this->path)->settings();
    }
}
