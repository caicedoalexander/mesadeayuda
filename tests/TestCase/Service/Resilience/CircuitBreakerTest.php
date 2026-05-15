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

    public function testOpenBreakerRejectsBeforeCooldown(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 1, cooldownSeconds: 30);
        $breaker->recordFailure('api.example.com');
        $this->assertFalse($breaker->isAvailable('api.example.com'));
        $this->assertGreaterThanOrEqual(0, $breaker->secondsOpen('api.example.com'));
    }

    public function testOpenBreakerPromotesToHalfOpenAfterCooldown(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 1, cooldownSeconds: 30);
        $breaker->recordFailure('api.example.com');

        Cache::write('cb_api.example.com', [
            'state' => 'open',
            'failures' => 1,
            'openedAt' => time() - 60,
        ], self::CACHE_KEY);

        $this->assertTrue($breaker->isAvailable('api.example.com'));

        $stored = Cache::read('cb_api.example.com', self::CACHE_KEY);
        $this->assertSame('half_open', $stored['state']);
    }

    public function testHalfOpenSuccessClosesBreaker(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 1, cooldownSeconds: 30);
        Cache::write('cb_api.example.com', [
            'state' => 'half_open',
            'failures' => 1,
            'openedAt' => time() - 60,
        ], self::CACHE_KEY);

        $breaker->recordSuccess('api.example.com');

        $stored = Cache::read('cb_api.example.com', self::CACHE_KEY);
        $this->assertSame('closed', $stored['state']);
        $this->assertSame(0, $stored['failures']);
    }

    public function testHalfOpenFailureReopensBreaker(): void
    {
        $breaker = new CircuitBreaker(self::CACHE_KEY, failureThreshold: 99, cooldownSeconds: 30);
        Cache::write('cb_api.example.com', [
            'state' => 'half_open',
            'failures' => 1,
            'openedAt' => time() - 60,
        ], self::CACHE_KEY);

        $breaker->recordFailure('api.example.com');

        $stored = Cache::read('cb_api.example.com', self::CACHE_KEY);
        $this->assertSame('open', $stored['state']);
        $this->assertGreaterThanOrEqual(time() - 2, (int)$stored['openedAt']);
    }
}
