<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Tests\Unit\Domain\Model;

use App\Tests\Factory\CameraEventFactory;
use CameraDiary\HomeAssistant\Domain\Model\ImportCursor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportCursor::class)]
final class ImportCursorTest extends TestCase
{
    public function testOnlyMovesForward(): void
    {
        $cursor = new ImportCursor(ImportCursor::HOME_ASSISTANT, CameraEventFactory::utc('2026-11-08 12:00:00'));

        $cursor->advanceTo(CameraEventFactory::utc('2026-11-01 00:00:00'));
        self::assertEquals(CameraEventFactory::utc('2026-11-08 12:00:00'), $cursor->getImportedUntil());

        $cursor->advanceTo(CameraEventFactory::utc('2026-11-08 12:01:00'));
        self::assertEquals(CameraEventFactory::utc('2026-11-08 12:01:00'), $cursor->getImportedUntil());
    }
}
