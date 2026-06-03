<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Traits;

use App\Service\Resilience\CircuitBreaker;
use App\Service\Resilience\ResilientHttpClient;
use App\Service\Resilience\RetryPolicy;
use App\Service\Traits\SecureHttpTrait;
use Cake\Cache\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Unsafe URLs that the SSRF guard must reject. Each maps the input URL to a
     * distinctive fragment of the expected rejection message, so the test pins
     * down *which* guard fired (not merely that something failed).
     *
     * All of these are rejected by resolveAndValidateUrl() before any network
     * call is made: malformed/blocked/non-http URLs short-circuit, and private
     * IP literals resolve to themselves via gethostbyname() without DNS.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            // Loopback / unspecified hosts in the explicit blocklist.
            'localhost' => ['http://localhost/admin', 'localhost'],
            'loopback IPv4' => ['http://127.0.0.1/secret', 'localhost'],
            'loopback IPv6' => ['http://[::1]/secret', 'localhost'],
            'unspecified 0.0.0.0' => ['http://0.0.0.0/secret', 'localhost'],
            // Private RFC1918 ranges resolved via IP literal.
            'private 10/8' => ['http://10.0.0.1/x', 'privadas'],
            'private 172.16/12' => ['http://172.16.0.1/x', 'privadas'],
            'private 192.168/16' => ['http://192.168.1.1/x', 'privadas'],
            // Link-local / cloud metadata endpoint (169.254.0.0/16).
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', 'privadas'],
            // Non-http(s) schemes.
            'gopher scheme' => ['gopher://example.com/x', 'esquema'],
            'ftp scheme' => ['ftp://example.com/x', 'esquema'],
            // Malformed / hostless URLs.
            'not a url' => ['not-a-url', 'inválida'],
            'scheme without host' => ['http:///no-host', 'inválida'],
        ];
    }

    #[DataProvider('unsafeUrlProvider')]
    public function testSecureCurlPostRejectsUnsafeUrl(string $url, string $expectedErrorFragment): void
    {
        $result = $this->makeSubject()->postExternal($url, '{}');

        self::assertFalse($result['success']);
        self::assertSame(0, $result['http_code']);
        self::assertNull($result['response']);
        self::assertStringContainsString($expectedErrorFragment, (string)$result['error']);
        // A pre-flight rejection never reaches the resilient client / breaker.
        self::assertArrayNotHasKey('circuit_breaker', $result);
    }

    public function testSecureCurlPostFailsClosedWhenHostnameCannotResolve(): void
    {
        // RFC 6761 reserves the .invalid TLD as guaranteed non-resolvable, so
        // gethostbyname() returns the input unchanged and the guard must fail
        // closed rather than letting the request through unvalidated.
        $result = $this->makeSubject()->postExternal('http://host.invalid/x', '{}');

        self::assertFalse($result['success']);
        self::assertStringContainsString('resolver', (string)$result['error']);
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
