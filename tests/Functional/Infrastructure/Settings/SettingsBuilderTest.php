<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Settings;

use App\Application\Settings\CallPool;
use App\Application\Settings\InvalidSettingsException;
use App\Application\Settings\VocabularyKind;
use App\Infrastructure\Settings\SettingsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsBuilder::class)]
final class SettingsBuilderTest extends TestCase
{
    public function testFillsInTheDefaults(): void
    {
        $settings = new SettingsBuilder()->build(null);

        self::assertSame(['person', 'animal', 'vehicle', 'motion'], $settings->detectionTypes->names());
        self::assertSame(['person', 'cat', 'dog', 'bird', 'other_animal', 'vehicle', 'nothing', 'other'], $settings->subjects->names());
        self::assertSame(['spider_web', 'insect', 'rain', 'light', 'nothing', 'other'], $settings->falseAlarmCauses->names());
        self::assertSame(500, $settings->ai->dailyCallLimit);
        self::assertSame([], $settings->ai->callPools);
        self::assertSame([1, 2, 3], $settings->description->frameOffsets);
        self::assertSame([3, 30], [$settings->maintenance->nightlyHour, $settings->maintenance->nightlyMinute]);
    }

    public function testMergesTheVocabulariesWithTheDefaults(): void
    {
        $settings = new SettingsBuilder()->build([
            'subjects' => [
                'fox' => ['label' => 'Fox', 'description' => 'a red fox'],
                'cat' => ['label' => 'Our cat'],
                'dog' => false,
                'hedgehog' => null,
            ],
        ]);

        self::assertSame(['person', 'cat', 'bird', 'other_animal', 'vehicle', 'nothing', 'other', 'fox', 'hedgehog'], $settings->subjects->names());
        self::assertSame('Our cat', $settings->subjects->label('cat'));
        self::assertSame('Hedgehog', $settings->subjects->label('hedgehog'));
        self::assertSame('Other animal', $settings->subjects->label('other_animal'));
        self::assertSame('Gone type', $settings->subjects->label('gone_type'), 'Names no longer configured read as words.');
    }

    public function testPicksTheFirstCallPoolWithOneOfTheDetectionTypes(): void
    {
        $ai = new SettingsBuilder()->build([
            'ai' => [
                'daily_call_limit' => 150,
                'call_pools' => [
                    'people_and_vehicles' => ['detection_types' => ['person', 'vehicle'], 'daily_call_limit' => 75],
                    'animals' => ['detection_types' => ['animal'], 'daily_call_limit' => 75],
                ],
            ],
        ])->ai;

        self::assertEquals(new CallPool('animals', ['animal'], 75), $ai->callPoolFor(['animal', 'motion']));
        self::assertSame('people_and_vehicles', $ai->callPoolFor(['animal', 'person'])?->name);
        self::assertNull($ai->callPoolFor(['motion']));
        self::assertSame(['People and vehicles', 'Animals'], array_map(static fn (CallPool $pool): string => $pool->label(), $ai->callPools));
    }

    public function testReadsKnownIndividualsWithPhotosRelativeToTheSettingsFile(): void
    {
        $fixtures = \dirname(__DIR__, 3).'/Fixtures';
        $settings = new SettingsBuilder()->build([
            'individuals' => ['luna' => ['subject' => 'cat', 'description' => 'grey-brown tabby', 'photos' => ['individuals/luna.jpg']]],
        ], $fixtures);

        $luna = $settings->individual('luna') ?? self::fail('Luna is missing.');
        self::assertSame('Luna', $luna->label);
        self::assertSame([$fixtures.'/individuals/luna.jpg'], $luna->photos);
        self::assertSame(['luna'], $settings->vocabulary(VocabularyKind::Individuals)->names());
        self::assertSame('Luna', $settings->individualLabel('luna'));
        self::assertSame('Gone cat', $settings->individualLabel('gone_cat'));
        self::assertSame('Luna', $settings->subjectLabel('cat', 'luna'));
        self::assertSame('Other cat', $settings->subjectLabel('cat', null));
        self::assertSame('Dog', $settings->subjectLabel('dog', null));
        self::assertSame('Gone cat', $settings->subjectLabel('cat', 'gone_cat'), 'Individuals no longer configured read as words.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'unknown key' => [['ai' => ['dayly_call_limit' => 5]]];
        yield 'invalid name' => [['subjects' => ['Red Fox' => null]]];
        yield 'reserved cause' => [['false_alarm_causes' => ['none' => null]]];
        yield 'empty vocabulary' => [['detection_types' => ['person' => false, 'animal' => false, 'vehicle' => false, 'motion' => false]]];
        yield 'pool with an unknown type' => [['ai' => ['call_pools' => ['ghosts' => ['detection_types' => ['ghost'], 'daily_call_limit' => 1]]]]];
        yield 'nightly time' => [['maintenance' => ['nightly_at' => '25:00']]];
        yield 'negative limit' => [['ai' => ['daily_call_limit' => -1]]];
        yield 'individual of an unknown subject' => [['individuals' => ['luna' => ['subject' => 'unicorn', 'photos' => [__FILE__]]]]];
        yield 'reserved individual' => [['individuals' => ['unknown' => ['subject' => 'cat', 'photos' => [__FILE__]]]]];
        yield 'individual for an unknown detection type' => [['individuals' => ['luna' => ['subject' => 'cat', 'detection_types' => ['ghost']]]]];
        yield 'missing photo' => [['individuals' => ['luna' => ['subject' => 'cat', 'photos' => ['/nowhere/luna.jpg']]]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $config): void
    {
        $this->expectException(InvalidSettingsException::class);

        new SettingsBuilder()->build($config);
    }
}
