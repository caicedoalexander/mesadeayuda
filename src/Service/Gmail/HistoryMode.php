<?php
declare(strict_types=1);

namespace App\Service\Gmail;

/**
 * Modes reported by GmailImportService::run() in GmailImportResult::historyMode.
 *
 * - BOOTSTRAP: no checkpoint existed; full sync ran and checkpoint was written.
 * - DELTA: checkpoint present; users.history.list returned the delta.
 * - FULL_SYNC_FALLBACK: checkpoint present but Gmail returned 404; full sync re-ran.
 * - MANUAL_OVERRIDE: CLI override supplied a query string; checkpoint untouched.
 */
final class HistoryMode
{
    public const BOOTSTRAP = 'bootstrap';
    public const DELTA = 'delta';
    public const FULL_SYNC_FALLBACK = 'full_sync_fallback';
    public const MANUAL_OVERRIDE = 'manual_override';

    /**
     * Prevent instantiation: this class is a constant namespace, not a value object.
     */
    private function __construct()
    {
    }
}
