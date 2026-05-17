<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use Cake\Log\Log;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Stateless factory exposing a Guzzle retry decider + delay callable
 * tuned for the Gmail API. Implements exponential backoff with full
 * jitter per Google's recommendation:
 *
 *   delay = min(2^n * BASE_DELAY_MS + rand(0, JITTER_MS), MAX_BACKOFF_MS)
 *
 * If the response carries Retry-After (RFC 7231), that value overrides
 * the computed backoff (still capped at MAX_BACKOFF_MS).
 */
final class RetryHandler
{
    public const MAX_RETRIES = 5;
    public const BASE_DELAY_MS = 250;
    public const MAX_BACKOFF_MS = 32_000;
    public const JITTER_MS = 1_000;

    /** @var list<int> */
    private const RETRIABLE_STATUS = [429, 500, 502, 503, 504];

    /**
     * Build the decider callable passed to GuzzleHttp\Middleware::retry.
     * Returns true when Guzzle should retry the request.
     */
    public static function decider(): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?Throwable $exception = null,
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }
            if ($exception instanceof ConnectException) {
                return true;
            }
            if ($response !== null && in_array($response->getStatusCode(), self::RETRIABLE_STATUS, true)) {
                return true;
            }

            return false;
        };
    }

    /**
     * Build the delay callable passed to GuzzleHttp\Middleware::retry.
     * Returns the delay in milliseconds before the next attempt.
     */
    public static function delay(): callable
    {
        return static function (int $retries, ?ResponseInterface $response = null): int {
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $retryAfter = self::parseRetryAfter($response->getHeaderLine('Retry-After'));
                if ($retryAfter !== null) {
                    return min($retryAfter, self::MAX_BACKOFF_MS);
                }
            }

            $base = min((2 ** $retries) * self::BASE_DELAY_MS, self::MAX_BACKOFF_MS);
            $jitter = random_int(0, self::JITTER_MS);
            $delay = min($base + $jitter, self::MAX_BACKOFF_MS);

            Log::warning('Gmail API retry', [
                'attempt' => $retries + 1,
                'status' => $response?->getStatusCode(),
                'delay_ms' => $delay,
            ]);

            return $delay;
        };
    }

    /**
     * Parse an RFC 7231 Retry-After value (delta-seconds or HTTP-date)
     * into milliseconds. Returns null when the value is unparseable.
     */
    private static function parseRetryAfter(string $value): ?int
    {
        if (ctype_digit($value)) {
            return (int)$value * 1000;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return max(0, ($timestamp - time()) * 1000);
    }
}
