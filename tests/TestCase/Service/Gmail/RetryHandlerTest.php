<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Gmail\RetryHandler;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RetryHandlerTest extends TestCase
{
    public function testDeciderRetriesOn429(): void
    {
        $decider = RetryHandler::decider();
        $this->assertTrue($decider(0, new Request('GET', '/'), new Response(429)));
    }

    public function testDeciderRetriesOn500(): void
    {
        $decider = RetryHandler::decider();
        $this->assertTrue($decider(0, new Request('GET', '/'), new Response(500)));
    }

    public function testDeciderDoesNotRetryOn200(): void
    {
        $decider = RetryHandler::decider();
        $this->assertFalse($decider(0, new Request('GET', '/'), new Response(200)));
    }

    public function testDeciderDoesNotRetryOn401(): void
    {
        $decider = RetryHandler::decider();
        $this->assertFalse($decider(0, new Request('GET', '/'), new Response(401)));
    }

    public function testDeciderDoesNotRetryOn404(): void
    {
        $decider = RetryHandler::decider();
        $this->assertFalse($decider(0, new Request('GET', '/'), new Response(404)));
    }

    public function testDeciderRetriesOnConnectException(): void
    {
        $decider = RetryHandler::decider();
        $exception = new ConnectException('dns failure', new Request('GET', '/'));
        $this->assertTrue($decider(0, new Request('GET', '/'), null, $exception));
    }

    public function testDeciderStopsAtMaxRetries(): void
    {
        $decider = RetryHandler::decider();
        $this->assertFalse($decider(RetryHandler::MAX_RETRIES, new Request('GET', '/'), new Response(429)));
    }

    public function testDelayProducesValueWithinJitterWindow(): void
    {
        $delay = RetryHandler::delay();
        $value = $delay(2, new Response(500));
        // base for n=2: min(4 * 250, 32000) = 1000; jitter [0, 1000] -> [1000, 2000]
        $this->assertGreaterThanOrEqual(1000, $value);
        $this->assertLessThanOrEqual(2000, $value);
    }

    public function testDelayCapsAtMaxBackoff(): void
    {
        $delay = RetryHandler::delay();
        $value = $delay(10, new Response(503));
        // n=10: base = min(1024*250, 32000) = 32000; +jitter clamped back to 32000
        $this->assertSame(RetryHandler::MAX_BACKOFF_MS, $value);
    }

    public function testDelayHonorsRetryAfterHeaderInSeconds(): void
    {
        $delay = RetryHandler::delay();
        $response = new Response(429, ['Retry-After' => '5']);
        $this->assertSame(5000, $delay(0, $response));
    }
}
