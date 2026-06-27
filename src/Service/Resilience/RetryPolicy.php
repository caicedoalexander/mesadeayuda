<?php
declare(strict_types=1);

namespace App\Service\Resilience;

/**
 * Retry policy with exponential backoff + jitter.
 *
 * Immutable value object. Encapsulates which errors are considered transient
 * and how long to wait before each retry attempt.
 */
final readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts Maximum number of attempts.
     * @param int $baseDelayMs Base delay before the first retry, in ms.
     * @param float $backoffMultiplier Exponential backoff multiplier.
     * @param int $jitterMs Maximum random jitter added per retry, in ms.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 200,
        public float $backoffMultiplier = 2.5,
        public int $jitterMs = 100,
    ) {
    }

    /**
     * Whether the given outcome should trigger a retry.
     *
     * Transient: 5xx, 429, and cURL operation timeout. Everything else is
     * either a success or a definitive client error.
     */
    public function shouldRetry(int $httpCode, int $curlErrno): bool
    {
        if ($curlErrno === CURLE_OPERATION_TIMEOUTED) {
            return true;
        }
        if ($httpCode === 429) {
            return true;
        }
        if ($httpCode >= 500 && $httpCode <= 599) {
            return true;
        }

        return false;
    }

    /**
     * Delay in milliseconds before the next attempt.
     *
     * @param int $attempt 1-based attempt index that just completed (1, 2, ...).
     */
    public function delayForAttempt(int $attempt): int
    {
        $base = (int)($this->baseDelayMs * ($this->backoffMultiplier ** ($attempt - 1)));

        return $base + random_int(0, $this->jitterMs);
    }
}
