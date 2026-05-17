<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use App\Service\Exception\GmailApiException;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

/**
 * Maps Gmail API failures to a small set of categories used for
 * retry decisions, logging, and counters in GmailImportResult.
 *
 * Not a PHP enum because callers serialize the value into JSON
 * and read it back from arrays without unwrapping cases.
 */
final class GmailErrorCategory
{
    public const AUTH = 'auth';
    public const RATE = 'rate';
    public const TRANSIENT = 'transient';
    public const PERMANENT = 'permanent';
    public const UNKNOWN = 'unknown';

    /**
     * Return the category string for any throwable surfacing from a Gmail
     * API call. Unwraps GmailApiException (already categorized) and reads
     * Google\Service\Exception's HTTP code; anything else is UNKNOWN.
     */
    public static function categorize(Throwable $e): string
    {
        if ($e instanceof GmailApiException) {
            return $e->getCategory();
        }
        if ($e instanceof GoogleServiceException) {
            return self::fromHttpCode($e->getCode());
        }

        return self::UNKNOWN;
    }

    /**
     * Map a Gmail API HTTP status code to a category. Retriable codes
     * (RATE, TRANSIENT) drive H-2 backoff decisions; AUTH calls for
     * operator intervention; PERMANENT signals a caller bug.
     */
    public static function fromHttpCode(int $code): string
    {
        return match (true) {
            $code === 401, $code === 403 => self::AUTH,
            $code === 429 => self::RATE,
            in_array($code, [500, 502, 503, 504], true) => self::TRANSIENT,
            $code >= 400 && $code < 500 => self::PERMANENT,
            default => self::UNKNOWN,
        };
    }
}
