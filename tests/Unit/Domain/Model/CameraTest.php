<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\Camera;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Camera::class)]
final class CameraTest extends TestCase
{
    public function testDerivesTheNameFromTheSlug(): void
    {
        self::assertSame('Front door', new Camera('front_door')->getName());
        self::assertSame('Garden', new Camera('garden', 'Garden')->getName());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function slugs(): iterable
    {
        yield 'lower case' => ['garden', true];
        yield 'digits, dash and underscore' => ['cam-2_back', true];
        yield 'upper case' => ['Garden', false];
        yield 'space' => ['front door', false];
        yield 'path' => ['../etc', false];
        yield 'empty' => ['', false];
        yield '33 characters' => [str_repeat('a', 33), false];
    }

    #[DataProvider('slugs')]
    public function testValidatesTheSlug(string $slug, bool $valid): void
    {
        self::assertSame($valid, Camera::isValidSlug($slug));
    }

    public function testRejectsAnInvalidSlug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Camera('../garden');
    }

    public function testRenamesAndDescribesTheScene(): void
    {
        $camera = new Camera('garden');
        $camera->rename(' Back garden ');
        $camera->describeScene('  Lawn with a fence at the back.  ');

        self::assertSame('Back garden', $camera->getName());
        self::assertSame('Lawn with a fence at the back.', $camera->getScene());

        $camera->describeScene('');
        self::assertNull($camera->getScene());
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Camera('garden')->rename('  ');
    }
}
