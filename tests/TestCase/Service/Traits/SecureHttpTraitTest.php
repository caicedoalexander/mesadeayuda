<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Traits;

use App\Service\Resilience\CircuitBreaker;
use App\Service\Resilience\ResilientHttpClient;
use App\Service\Resilience\RetryPolicy;
use App\Service\Traits\SecureHttpTrait;
use Cake\Cache\Cache;
use PHPUnit\Framework\TestCase;

final class SecureHttpTraitTest extends TestCase
{
    private const CACHE_KEY = 'cb_trait_test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig(self::CACHE_KEY, [
            'className' => 'Array',
            'prefix' => 'cb_trait_test_',
            'duration' => '+1 hour',
        ]);
        Cache::clear(self::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::drop(self::CACHE_KEY);
        parent::tearDown();
    }

    public function testCircuitOpenIsConvertedToErrorShape(): void
    {
        $host = 'example.com';
        Cache::write('cb_' . $host, [
            'state' => 'open',
            'failures' => 99,
            'openedAt' => time(),
        ], self::CACHE_KEY);

        $client = new ResilientHttpClient(
            new CircuitBreaker(self::CACHE_KEY, failureThreshold: 1, cooldownSeconds: 999),
            new RetryPolicy(),
        );

        $subject = $this->makeSubject();
        $subject->setResilientHttpClientForTesting($client);

        $result = $subject->postExternal('https://example.com/path', '{}');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('circuit_breaker', $result);
        $this->assertTrue($result['circuit_breaker']);
        $this->assertSame(0, $result['http_code']);
    }

    private function makeSubject(): object
    {
        return new class {
            use SecureHttpTrait;

            public function postExternal(string $url, string $payload): array
            {
                return $this->secureCurlPost($url, $payload);
            }
        };
    }
}
