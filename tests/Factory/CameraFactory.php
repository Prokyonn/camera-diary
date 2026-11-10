<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Model\Camera;

/**
 * One instance per slug, so events of the same camera share it like Doctrine would; reset per test.
 */
final class CameraFactory
{
    /** @var array<string, Camera> */
    private static array $cameras = [];

    public static function get(string $slug = 'terrace', ?string $scene = null): Camera
    {
        if (!isset(self::$cameras[$slug])) {
            self::$cameras[$slug] = new Camera($slug);
            self::$cameras[$slug]->describeScene($scene);
        }

        return self::$cameras[$slug];
    }

    public static function reset(): void
    {
        self::$cameras = [];
    }
}
