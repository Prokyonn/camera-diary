<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(Kernel::class)]
final class KernelTest extends KernelTestCase
{
    public function testStoresTimesInUtcWhateverThePhpIniSays(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            self::bootKernel();

            self::assertSame('UTC', date_default_timezone_get());
        } finally {
            date_default_timezone_set($default);
        }
    }
}
