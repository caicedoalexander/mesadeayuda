<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Resilience;

use App\Service\Resilience\CircuitBreaker;
use App\Service\Resilience\CircuitOpenException;
use App\Service\Resilience\ResilientHttpClient;
use App\Service\Resilience\RetryPolicy;
use Cake\Cache\Cache;
use PHPUnit\Framework\TestCase;

final class ResilientHttpClientTest extends TestCase
{
    private const CACHE_KEY = 'cb_test_client';
    private array $sleepLog = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig(self::CACHE_KEY, [
            'className' => 'Array',
            'prefix' => 'cb_test_client_',
            'duration' => '+1 hour',
        ]);
        Cache::clear(self::CACHE_KEY);
        $this->sleepLog = [];
    }

    protected function tearDown(): void
    {
        Cache::drop(self::CACHE_KEY);
        parent::tearDown();
    }

    private function makeClient(int $failureThreshold = 5): ResilientHttpClient
    {
        return new ResilientHttpClient(
            new CircuitBreaker(self::CACHE_KEY, failureThreshold: $failureThreshold, cooldownSeconds: 30),
            new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, backoffMultiplier: 1.0, jitterMs: 0),
            function (int $micros): void {
                $this->sleepLog[] = $micros;
            },
        );
    }

    public function testSuccessOnFirstTryReturnsImmediately(): void
    {
        $client = $this->makeClient();
        $executor = $this->executorReturning([
            ['success' => true, 'http_code' => 200, 'response' => 'ok', 'error' => null, 'curl_errno' => 0],
        ]);

        $result = $client->send('https://api.example.com/x', $executor);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame([], $this->sleepLog);
    }

    public function testRetriesOn500ThenSucceeds(): void
    {
        $client = $this->makeClient();
        $calls = 0;
        $executor = function () use (&$calls): array {
            $calls++;
            if ($calls < 3) {
                return ['success' => false, 'http_code' => 500, 'response' => null, 'error' => 'HTTP 500', 'curl_errno' => 0];
            }

            return ['success' => true, 'http_code' => 200, 'response' => 'ok', 'error' => null, 'curl_errno' => 0];
        };

        $result = $client->send('https://api.example.com/x', $executor);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $calls);
        $this->assertCount(2, $this->sleepLog);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $client = $this->makeClient();
        $calls = 0;
        $executor = function () use (&$calls): array {
            $calls++;

            return ['success' => false, 'http_code' => 503, 'response' => null, 'error' => 'HTTP 503', 'curl_errno' => 0];
        };

        $result = $client->send('https://api.example.com/x', $executor);

        $this->assertFalse($result['success']);
        $this->assertSame(3, $calls);
    }

    public function testDoesNotRetryOn4xxNon429(): void
    {
        $client = $this->makeClient();
        $calls = 0;
        $executor = function () use (&$calls): array {
            $calls++;

            return ['success' => false, 'http_code' => 400, 'response' => null, 'error' => 'HTTP 400', 'curl_errno' => 0];
        };

        $result = $client->send('https://api.example.com/x', $executor);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $calls);
    }

    public function testRetriesOn429(): void
    {
        $client = $this->makeClient();
        $calls = 0;
        $executor = function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return ['success' => false, 'http_code' => 429, 'response' => null, 'error' => 'HTTP 429', 'curl_errno' => 0];
            }

            return ['success' => true, 'http_code' => 200, 'response' => 'ok', 'error' => null, 'curl_errno' => 0];
        };

        $result = $client->send('https://api.example.com/x', $executor);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $calls);
    }

    public function testNon429ClientErrorDoesNotIncrementBreakerFailures(): void
    {
        $client = $this->makeClient(failureThreshold: 2);
        $executor = $this->executorReturning(array_fill(0, 10, [
            'success' => false, 'http_code' => 400, 'response' => null, 'error' => 'HTTP 400', 'curl_errno' => 0,
        ]));

        $client->send('https://api.example.com/x', $executor);
        $client->send('https://api.example.com/x', $executor);
        $client->send('https://api.example.com/x', $executor);

        $result = $client->send('https://api.example.com/x', $executor);
        $this->assertSame(400, $result['http_code']);
    }

    public function testServerErrorsOpenBreakerAndSubsequentCallThrows(): void
    {
        $client = $this->makeClient(failureThreshold: 1);
        $executor = $this->executorReturning(array_fill(0, 10, [
            'success' => false, 'http_code' => 503, 'response' => null, 'error' => 'HTTP 503', 'curl_errno' => 0,
        ]));

        $client->send('https://api.example.com/x', $executor);

        $this->expectException(CircuitOpenException::class);
        $client->send('https://api.example.com/x', $executor);
    }

    /**
     * @param list<array{success: bool, http_code: int, response: ?string, error: ?string, curl_errno: int}> $responses
     */
    private function executorReturning(array $responses): callable
    {
        $i = 0;

        return function () use ($responses, &$i): array {
            $r = $responses[$i] ?? $responses[count($responses) - 1];
            $i++;

            return $r;
        };
    }
}
