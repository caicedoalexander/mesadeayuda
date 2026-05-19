<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Dto;

use App\Service\Dto\GmailImportResult;
use App\Service\Gmail\HistoryMode;
use PHPUnit\Framework\TestCase;

final class GmailImportResultTest extends TestCase
{
    public function testToArrayIncludesCategoryCounters(): void
    {
        $result = new GmailImportResult(
            fetched: 10,
            created: 4,
            comments: 2,
            skipped: 1,
            errors: 3,
            durationSeconds: 1.234,
            errorMessages: ['msg-1: boom'],
            authErrors: 1,
            rateErrors: 1,
            transientErrors: 0,
            permanentErrors: 1,
            unknownErrors: 0,
        );

        $array = $result->toArray();

        $this->assertSame(1, $array['auth_errors']);
        $this->assertSame(1, $array['rate_errors']);
        $this->assertSame(0, $array['transient_errors']);
        $this->assertSame(1, $array['permanent_errors']);
        $this->assertSame(0, $array['unknown_errors']);
        $this->assertSame(3, $array['errors']);
    }

    public function testCounterSumMatchesTotalErrors(): void
    {
        $result = new GmailImportResult(
            fetched: 5,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 5,
            durationSeconds: 0.5,
            errorMessages: [],
            authErrors: 2,
            rateErrors: 1,
            transientErrors: 1,
            permanentErrors: 0,
            unknownErrors: 1,
        );

        $sum = $result->authErrors
             + $result->rateErrors
             + $result->transientErrors
             + $result->permanentErrors
             + $result->unknownErrors;

        $this->assertSame($result->errors, $sum);
    }

    public function testBackwardCompatibleConstructorDefaultsCountersToZero(): void
    {
        $result = new GmailImportResult(
            fetched: 0,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.0,
        );

        $this->assertSame(0, $result->authErrors);
        $this->assertSame(0, $result->rateErrors);
        $this->assertSame(0, $result->transientErrors);
        $this->assertSame(0, $result->permanentErrors);
        $this->assertSame(0, $result->unknownErrors);
    }

    public function testToArrayIncludesMarkReadCounters(): void
    {
        $result = new GmailImportResult(
            fetched: 1,
            created: 1,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.1,
            markReadRetried: 2,
            markReadDropped: 1,
            markReadEnqueued: 3,
        );

        $array = $result->toArray();

        $this->assertSame(2, $array['mark_read_retried']);
        $this->assertSame(1, $array['mark_read_dropped']);
        $this->assertSame(3, $array['mark_read_enqueued']);
    }

    public function testMarkReadCountersDefaultToZero(): void
    {
        $result = new GmailImportResult(
            fetched: 0,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.0,
        );

        $this->assertSame(0, $result->markReadRetried);
        $this->assertSame(0, $result->markReadDropped);
        $this->assertSame(0, $result->markReadEnqueued);
    }

    public function testToArrayIncludesHistoryModeAndFallbacks(): void
    {
        $result = new GmailImportResult(
            fetched: 0,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.0,
            historyMode: HistoryMode::DELTA,
            historyFallbacks: 0,
        );

        $array = $result->toArray();

        $this->assertSame('delta', $array['history_mode']);
        $this->assertSame(0, $array['history_fallbacks']);
    }

    public function testHistoryModeDefaultsToBootstrap(): void
    {
        $result = new GmailImportResult(
            fetched: 0,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.0,
        );

        $this->assertSame('bootstrap', $result->historyMode);
        $this->assertSame(0, $result->historyFallbacks);
    }
}
