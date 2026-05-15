<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use Cake\Log\Log;

/**
 * HTTP orchestrator that wraps a caller-supplied executor with a circuit
 * breaker and a retry policy.
 *
 * The executor returns the standard SecureHttpTrait response shape
 * (success/http_code/response/error) plus a curl_errno field used to decide
 * retries.
 *
 * The CircuitOpenException thrown when the breaker is open is intended to be
 * caught by the calling trait and converted into a normal response shape so
 * service callers never see it.
 */
final class ResilientHttpClient
{
    /**
     * @var callable(int): void
     */
    private $sleepFn;

    /**
     * @param (callable(int): void)|null $sleepFn Optional sleep override for tests. Receives microseconds.
     */
    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly RetryPolicy $retryPolicy,
        ?callable $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn ?? static fn(int $micros) => usleep($micros);
    }

    /**
     * @param callable(): array{success: bool, http_code: int, response: ?string, error: ?string, curl_errno: int} $executor
     * @return array{success: bool, http_code: int, response: ?string, error: ?string, curl_errno?: int}
     * @throws \App\Service\Resilience\CircuitOpenException When the breaker is open for this host.
     */
    public function send(string $url, callable $executor): array
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');

        if ($host !== '' && !$this->breaker->isAvailable($host)) {
            $seconds = $this->breaker->secondsOpen($host);
            Log::warning('circuit_breaker.rejected', ['host' => $host, 'seconds_open' => $seconds]);

            throw new CircuitOpenException($host, $seconds);
        }

        $result = ['success' => false, 'http_code' => 0, 'response' => null, 'error' => 'no attempt', 'curl_errno' => 0];
        for ($attempt = 1; $attempt <= $this->retryPolicy->maxAttempts; $attempt++) {
            $result = $executor();

            if ($result['success']) {
                if ($host !== '') {
                    $this->breaker->recordSuccess($host);
                }

                return $result;
            }

            $httpCode = (int)($result['http_code'] ?? 0);
            $errno = (int)($result['curl_errno'] ?? 0);

            $shouldRetry = $this->retryPolicy->shouldRetry($httpCode, $errno);

            if ($attempt < $this->retryPolicy->maxAttempts && $shouldRetry) {
                $delayMs = $this->retryPolicy->delayForAttempt($attempt);
                Log::warning('http.retry', [
                    'host' => $host,
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'curl_errno' => $errno,
                    'delay_ms' => $delayMs,
                ]);
                ($this->sleepFn)($delayMs * 1000);
                continue;
            }

            if ($host !== '' && $shouldRetry) {
                $this->breaker->recordFailure($host);
            }

            return $result;
        }

        return $result;
    }
}
