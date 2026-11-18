<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Description\SceneChange;
use App\Application\Frame\BrightnessGrid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SceneChange::class)]
final class SceneChangeTest extends TestCase
{
    public function testTheSameSceneShowsNothingNew(): void
    {
        self::assertFalse(new SceneChange()->showsSomethingNew([$this->grid(), $this->grid()], [$this->grid()]));
    }

    public function testAChangeWhereTheReferencesChangedTooIsNothingNew(): void
    {
        $thread = [[1, 2, 250.0]];
        $swung = [[2, 3, 250.0]];

        self::assertFalse(
            new SceneChange()->showsSomethingNew([$this->grid($thread), $this->grid($swung)], [$this->grid([[3, 4, 250.0]])]),
            'A spider thread that swings a little further is still the spider thread.',
        );
    }

    public function testAChangeElsewhereIsSomethingNew(): void
    {
        $thread = [[1, 2, 250.0]];
        $swung = [[2, 3, 250.0]];

        self::assertTrue(
            new SceneChange()->showsSomethingNew([$this->grid($thread), $this->grid($swung)], [$this->grid([[6, 14, 20.0]])]),
            'A dark cat in the far corner is new.',
        );
    }

    public function testABrighterExposureIsNothingNew(): void
    {
        self::assertFalse(new SceneChange()->showsSomethingNew([$this->grid(), $this->grid()], [$this->grid(base: 160.0)]));
    }

    public function testFramesOfAnotherShapeAreSomethingNew(): void
    {
        self::assertTrue(new SceneChange()->showsSomethingNew([$this->grid(), $this->grid()], [new BrightnessGrid(2, [100.0, 100.0])]));
    }

    /**
     * @param list<array{int, int, float}> $cells row, column and brightness of cells that differ in a grid of 16 by 8
     */
    private function grid(array $cells = [], float $base = 100.0): BrightnessGrid
    {
        $changed = [];
        foreach ($cells as [$row, $column, $value]) {
            $changed[$row * 16 + $column] = $value;
        }

        return new BrightnessGrid(16, array_map(static fn (int $index): float => $changed[$index] ?? $base, range(0, 16 * 8 - 1)));
    }
}
