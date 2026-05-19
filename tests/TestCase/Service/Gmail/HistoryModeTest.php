<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Gmail\HistoryMode;
use PHPUnit\Framework\TestCase;

final class HistoryModeTest extends TestCase
{
    public function testFourDistinctConstants(): void
    {
        $values = [
            HistoryMode::BOOTSTRAP,
            HistoryMode::DELTA,
            HistoryMode::FULL_SYNC_FALLBACK,
            HistoryMode::MANUAL_OVERRIDE,
        ];

        $this->assertSame($values, array_unique($values));
        $this->assertCount(4, $values);
    }

    public function testConstantsAreShortLowerSnake(): void
    {
        // Used directly in GmailImportResult::toArray()['history_mode'], so
        // they should be readable in JSON without further mapping.
        $this->assertSame('bootstrap', HistoryMode::BOOTSTRAP);
        $this->assertSame('delta', HistoryMode::DELTA);
        $this->assertSame('full_sync_fallback', HistoryMode::FULL_SYNC_FALLBACK);
        $this->assertSame('manual_override', HistoryMode::MANUAL_OVERRIDE);
    }
}
