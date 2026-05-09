<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NumberGenerationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see NumberGenerationService} format helper.
 *
 * The atomic allocation path (allocateSequence) is exercised against MySQL
 * and is out of scope for unit tests. Verify it manually with the migration
 * applied and concurrent writes (e.g., webhook + bot creation).
 */
#[CoversClass(NumberGenerationService::class)]
final class NumberGenerationServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function formatExamples(): iterable
    {
        yield 'first ticket of year' => [2026, 1, 'TKT-2026-00001'];
        yield 'mid year' => [2026, 1234, 'TKT-2026-01234'];
        yield 'last 5-digit' => [2026, 99999, 'TKT-2026-99999'];
        yield 'overflow keeps full sequence' => [2026, 100000, 'TKT-2026-100000'];
        yield 'arbitrary year' => [2030, 7, 'TKT-2030-00007'];
    }

    #[DataProvider('formatExamples')]
    public function testFormatNumberPadsAndPrefixes(int $year, int $sequence, string $expected): void
    {
        $this->assertSame($expected, NumberGenerationService::formatNumber($year, $sequence));
    }

    public function testFormatNumberAllowsLargeSequencesPastFiveDigits(): void
    {
        // The DB column is INT UNSIGNED so values can exceed 99,999.
        // The format helper must NOT truncate; padding only kicks in when seq < 5 digits.
        $this->assertSame(
            'TKT-2026-1000000',
            NumberGenerationService::formatNumber(2026, 1_000_000),
        );
    }
}
