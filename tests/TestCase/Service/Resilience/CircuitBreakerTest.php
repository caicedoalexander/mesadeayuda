<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Resilience;

use App\Service\Resilience\CircuitBreaker;
use Cake\Cache\Cache;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private const CACHE_KEY = 'cb_test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig(self::CACHE_KEY, [
            'className' => 'Array',
            'prefix' => 'cb_test_',
            'duration' => '+1 hour',
        ]);
        Cache::clear(self::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::drop(self::CACHE_KEY);
        parent::tearDown();
    }

    public function testNewHostIsAvailable(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 3, cooldownSeconds: 30);
        $this->assertTrue($breaker->isAvailable('api.example.com'));
    }

    public function testRecordingFailuresBelowThresholdKeepsClosed(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 3, cooldownSeconds: 30);
        $breaker->recordFailure('api.example.com');
        $breaker->recordFailure('api.example.com');
        $this->assertTrue($breaker->isAvailable('api.example.com'));
    }

    public function testReachingThresholdOpensBreaker(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 3, cooldownSeconds: 30);
        $breaker->recordFailure('api.example.com');
        $breaker->recordFailure('api.example.com');
        $breaker->recordFailure('api.example.com');
        $this->assertFalse($breaker->isAvailable('api.example.com'));
    }

    public function testSuccessResetsFailureCount(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 3, cooldownSeconds: 30);
        $breaker->recordFailure('api.example.com');
        $breaker->recordFailure('api.example.com');
        $breaker->recordSuccess('api.example.com');
        $breaker->recordFailure('api.example.com');
        $breaker->recordFailure('api.example.com');
        $this->assertTrue($breaker->isAvailable('api.example.com'));
    }
}
