<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Resilience;

use App\Service\Resilience\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testShouldRetryReturnsTrueForServerErrors(): void
    {
        $policy = new RetryPolicy();
        $this->assertTrue($policy->shouldRetry(500, 0));
        $this->assertTrue($policy->shouldRetry(502, 0));
        $this->assertTrue($policy->shouldRetry(503, 0));
        $this->assertTrue($policy->shouldRetry(504, 0));
    }

    public function testShouldRetryReturnsTrueFor429(): void
    {
        $this->assertTrue((new RetryPolicy())->shouldRetry(429, 0));
    }

    public function testShouldRetryReturnsTrueForCurlTimeout(): void
    {
        $this->assertTrue((new RetryPolicy())->shouldRetry(0, CURLE_OPERATION_TIMEOUTED));
    }

    public function testShouldRetryReturnsFalseForClientErrors(): void
    {
        $policy = new RetryPolicy();
        $this->assertFalse($policy->shouldRetry(400, 0));
        $this->assertFalse($policy->shouldRetry(401, 0));
        $this->assertFalse($policy->shouldRetry(403, 0));
        $this->assertFalse($policy->shouldRetry(404, 0));
    }

    public function testShouldRetryReturnsFalseForSuccess(): void
    {
        $this->assertFalse((new RetryPolicy())->shouldRetry(200, 0));
        $this->assertFalse((new RetryPolicy())->shouldRetry(201, 0));
    }

    public function testShouldRetryReturnsFalseForNonTimeoutCurlError(): void
    {
        $this->assertFalse((new RetryPolicy())->shouldRetry(0, CURLE_COULDNT_RESOLVE_HOST));
    }

    public function testDelayForAttemptGrowsExponentiallyWithinJitter(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 200, backoffMultiplier: 2.5, jitterMs: 100);

        $delay1 = $policy->delayForAttempt(1);
        $this->assertGreaterThanOrEqual(200, $delay1);
        $this->assertLessThanOrEqual(300, $delay1);

        $delay2 = $policy->delayForAttempt(2);
        $this->assertGreaterThanOrEqual(500, $delay2);
        $this->assertLessThanOrEqual(600, $delay2);

        $delay3 = $policy->delayForAttempt(3);
        $this->assertGreaterThanOrEqual(1250, $delay3);
        $this->assertLessThanOrEqual(1350, $delay3);
    }
}
