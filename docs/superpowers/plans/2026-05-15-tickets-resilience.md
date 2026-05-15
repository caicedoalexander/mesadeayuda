# Tickets Module Resilience (CRIT-1 + CRIT-2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Circuit Breaker + Retry to all outbound HTTP POSTs that flow through `SecureHttpTrait::secureCurlPost`, eliminating worker stalls when external providers degrade and recovering transparently from transient 5xx/429/timeouts.

**Architecture:** Three new classes in `src/Service/Resilience/` (`RetryPolicy`, `CircuitBreaker`, `ResilientHttpClient`) plus `CircuitOpenException`. The trait is refactored so its existing curl logic moves into a private `executeRawCurlPost()` method, and `secureCurlPost()` delegates the call through `ResilientHttpClient`. Public signatures do not change; consumers (`WhatsappService`, `N8nService`, `GmailService`) are not touched.

**Tech Stack:** PHP 8.5, CakePHP 5.x, `Cake\Cache\Cache` (existing), PHPUnit, no new composer deps.

**Spec:** `docs/superpowers/specs/2026-05-15-tickets-resilience-design.md`

---

## File Structure

| File | Responsibility | Status |
|---|---|---|
| `src/Service/Resilience/RetryPolicy.php` | Immutable value object. `shouldRetry()` predicate + `delayForAttempt()` backoff calc. | Create |
| `src/Service/Resilience/CircuitOpenException.php` | Control-flow exception thrown when the breaker is open for a host. | Create |
| `src/Service/Resilience/CircuitBreaker.php` | State machine (CLOSED/OPEN/HALF_OPEN) persisted in `Cake\Cache\Cache`. One key per host. | Create |
| `src/Service/Resilience/ResilientHttpClient.php` | Orchestrator. Combines breaker + retry around a caller-supplied executor closure. | Create |
| `src/Constants/CacheConstants.php` | Add `CACHE_RESILIENCE` constant for the new cache config. | Modify |
| `config/app.php` | Add `Resilience` config block + new `Cache.resilience` engine config. | Modify |
| `src/Service/Traits/SecureHttpTrait.php` | Extract raw curl logic into `executeRawCurlPost()`; route `secureCurlPost()` through `ResilientHttpClient`. | Modify |
| `tests/TestCase/Service/Resilience/RetryPolicyTest.php` | Unit tests for retry predicate + delay calc. | Create |
| `tests/TestCase/Service/Resilience/CircuitBreakerTest.php` | Unit tests for the state machine using `ArrayEngine`. | Create |
| `tests/TestCase/Service/Resilience/ResilientHttpClientTest.php` | Unit tests for orchestration (mocked executor + `sleepFn`). | Create |
| `tests/TestCase/Service/Traits/SecureHttpTraitTest.php` | Add tests for resilient path (mocked client) without breaking existing tests. | Modify or Create |
| `docs/audits/2026-05-14-tickets-module-audit.md` | Bitácora entry closing CRIT-1 + CRIT-2; matrix + scorecards updated. | Modify |

---

## Task 1: Create `RetryPolicy` value object with TDD

**Files:**
- Create: `src/Service/Resilience/RetryPolicy.php`
- Test: `tests/TestCase/Service/Resilience/RetryPolicyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Service/Resilience/RetryPolicyTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/RetryPolicyTest.php`
Expected: FAIL with "Class 'App\Service\Resilience\RetryPolicy' not found".

- [ ] **Step 3: Write minimal implementation**

Create `src/Service/Resilience/RetryPolicy.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/RetryPolicyTest.php`
Expected: PASS, 7 tests, 0 failures.

- [ ] **Step 5: Run code style**

Run: `composer cs-fix && composer cs-check`
Expected: any reported errors are pre-existing (not in the two new files).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Resilience/RetryPolicy.php tests/TestCase/Service/Resilience/RetryPolicyTest.php
git commit -m "feat(resilience): add RetryPolicy value object"
```

---

## Task 2: Create `CircuitOpenException`

**Files:**
- Create: `src/Service/Resilience/CircuitOpenException.php`

- [ ] **Step 1: Write the file**

Create `src/Service/Resilience/CircuitOpenException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use RuntimeException;

/**
 * Thrown by ResilientHttpClient when the circuit breaker is OPEN for the
 * target host and the cooldown has not elapsed. Captured inside
 * SecureHttpTrait::secureCurlPost — does NOT escape to service callers.
 */
final class CircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $host,
        public readonly int $secondsOpen,
    ) {
        parent::__construct(sprintf(
            'Circuit breaker open for host "%s" (%d seconds since open).',
            $host,
            $secondsOpen,
        ));
    }
}
```

- [ ] **Step 2: Verify autoload picks it up**

Run: `composer dump-autoload -o`
Expected: no errors.

- [ ] **Step 3: Code style**

Run: `composer cs-check src/Service/Resilience/CircuitOpenException.php`
Expected: no errors on this file.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Resilience/CircuitOpenException.php
git commit -m "feat(resilience): add CircuitOpenException"
```

---

## Task 3: Add `CACHE_RESILIENCE` constant and cache engine config

**Files:**
- Modify: `src/Constants/CacheConstants.php`
- Modify: `config/app.php` (add `Cache.resilience` entry)

- [ ] **Step 1: Read existing CacheConstants**

Run: `cat src/Constants/CacheConstants.php`
Note the existing constants and pick a placement for the new one alongside `CACHE_SETTINGS`.

- [ ] **Step 2: Add the constant**

In `src/Constants/CacheConstants.php`, add (preserving file style):

```php
    public const CACHE_RESILIENCE = 'resilience';
```

- [ ] **Step 3: Add cache engine config**

In `config/app.php`, locate the `'Cache' => [...]` array and add a new entry alongside the existing engines:

```php
        'resilience' => [
            'className' => FileEngine::class,
            'prefix' => 'mda_resilience_',
            'path' => CACHE,
            'serialize' => true,
            'duration' => '+1 hour',
            'url' => env('CACHE_RESILIENCE_URL', null),
        ],
```

> If `app.php` already uses Redis for other caches (check `'default'` or `'_cake_core_'`), mirror that engine class instead of `FileEngine`. The TTL of 1 hour is a safety net — breaker entries are rewritten on every attempt.

- [ ] **Step 4: Verify config loads without error**

Run: `bin/cake cache list_prefixes`
Expected: `resilience` appears in the list (or at minimum: command does not error).

- [ ] **Step 5: Commit**

```bash
git add src/Constants/CacheConstants.php config/app.php
git commit -m "feat(resilience): add resilience cache engine + constant"
```

---

## Task 4: Create `CircuitBreaker` with TDD — happy path (CLOSED → OPEN)

**Files:**
- Create: `src/Service/Resilience/CircuitBreaker.php`
- Test: `tests/TestCase/Service/Resilience/CircuitBreakerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Service/Resilience/CircuitBreakerTest.php`:

```php
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
        // 2 failures after reset → still below threshold of 3
        $this->assertTrue($breaker->isAvailable('api.example.com'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/CircuitBreakerTest.php`
Expected: FAIL with "Class 'App\Service\Resilience\CircuitBreaker' not found".

- [ ] **Step 3: Write minimal implementation**

Create `src/Service/Resilience/CircuitBreaker.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use Cake\Cache\Cache;
use Cake\Log\Log;

/**
 * Circuit breaker per remote host.
 *
 * States: closed (normal), open (rejecting), half_open (probing).
 * State is persisted in CakePHP cache so it is shared across PHP-FPM workers.
 *
 * Two workers may race on read/write; the loss is bounded (one extra request
 * may slip through before the breaker opens). No distributed locking — the
 * cost would exceed the value for this use case.
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly string $cacheConfig,
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 30,
    ) {
    }

    public function isAvailable(string $host): bool
    {
        $state = $this->readState($host);

        if ($state['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($state['state'] === self::STATE_OPEN) {
            $elapsed = time() - (int)$state['openedAt'];
            if ($elapsed < $this->cooldownSeconds) {
                return false;
            }
            // Cooldown elapsed → promote to half_open, allow one probe through.
            $this->writeState($host, [
                'state' => self::STATE_HALF_OPEN,
                'failures' => $state['failures'],
                'openedAt' => $state['openedAt'],
            ]);
            Log::info('circuit_breaker.half_open', ['host' => $host, 'opened_at' => $state['openedAt']]);

            return true;
        }

        // HALF_OPEN: caller is the probe.
        return true;
    }

    public function recordSuccess(string $host): void
    {
        $state = $this->readState($host);
        if ($state['state'] !== self::STATE_CLOSED || $state['failures'] > 0) {
            if ($state['state'] !== self::STATE_CLOSED) {
                Log::info('circuit_breaker.closed', ['host' => $host]);
            }
            $this->writeState($host, [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'openedAt' => null,
            ]);
        }
    }

    public function recordFailure(string $host): void
    {
        $state = $this->readState($host);
        $failures = $state['failures'] + 1;

        if ($state['state'] === self::STATE_HALF_OPEN || $failures >= $this->failureThreshold) {
            $this->writeState($host, [
                'state' => self::STATE_OPEN,
                'failures' => $failures,
                'openedAt' => time(),
            ]);
            Log::error('circuit_breaker.opened', ['host' => $host, 'failures' => $failures]);

            return;
        }

        $this->writeState($host, [
            'state' => self::STATE_CLOSED,
            'failures' => $failures,
            'openedAt' => null,
        ]);
    }

    /**
     * @return int Seconds since this host's breaker opened (0 if not open).
     */
    public function secondsOpen(string $host): int
    {
        $state = $this->readState($host);
        if ($state['state'] !== self::STATE_OPEN || $state['openedAt'] === null) {
            return 0;
        }

        return max(0, time() - (int)$state['openedAt']);
    }

    /**
     * @return array{state: string, failures: int, openedAt: int|null}
     */
    private function readState(string $host): array
    {
        $data = Cache::read($this->keyFor($host), $this->cacheConfig);
        if (!is_array($data) || !isset($data['state'])) {
            return ['state' => self::STATE_CLOSED, 'failures' => 0, 'openedAt' => null];
        }

        return [
            'state' => (string)$data['state'],
            'failures' => (int)($data['failures'] ?? 0),
            'openedAt' => isset($data['openedAt']) ? (int)$data['openedAt'] : null,
        ];
    }

    /**
     * @param array{state: string, failures: int, openedAt: int|null} $state
     */
    private function writeState(string $host, array $state): void
    {
        Cache::write($this->keyFor($host), $state, $this->cacheConfig);
    }

    private function keyFor(string $host): string
    {
        return 'cb_' . preg_replace('/[^a-z0-9_.-]/i', '_', $host);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/CircuitBreakerTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Resilience/CircuitBreaker.php tests/TestCase/Service/Resilience/CircuitBreakerTest.php
git commit -m "feat(resilience): add CircuitBreaker state machine (closed/open path)"
```

---

## Task 5: Extend `CircuitBreaker` tests with OPEN → HALF_OPEN → CLOSED transitions

**Files:**
- Modify: `tests/TestCase/Service/Resilience/CircuitBreakerTest.php`

- [ ] **Step 1: Add tests for cooldown and half-open behavior**

Append to `tests/TestCase/Service/Resilience/CircuitBreakerTest.php` (inside the class):

```php
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

        // Force openedAt into the past by rewriting cache directly.
        Cache::write('cb_api.example.com', [
            'state' => 'open',
            'failures' => 1,
            'openedAt' => time() - 60,
        ], self::CACHE_KEY);

        $this->assertTrue($breaker->isAvailable('api.example.com'));

        // Second read should now see HALF_OPEN.
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
```

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/CircuitBreakerTest.php`
Expected: PASS, 8 tests (4 from Task 4 + 4 new).

- [ ] **Step 3: Commit**

```bash
git add tests/TestCase/Service/Resilience/CircuitBreakerTest.php
git commit -m "test(resilience): cover half-open transitions in CircuitBreaker"
```

---

## Task 6: Create `ResilientHttpClient` with TDD

**Files:**
- Create: `src/Service/Resilience/ResilientHttpClient.php`
- Test: `tests/TestCase/Service/Resilience/ResilientHttpClientTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Service/Resilience/ResilientHttpClientTest.php`:

```php
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
        $this->assertCount(2, $this->sleepLog); // two backoffs between three attempts
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

        // Three 400s in a row should NOT open the breaker.
        $client->send('https://api.example.com/x', $executor);
        $client->send('https://api.example.com/x', $executor);
        $client->send('https://api.example.com/x', $executor);

        // Next call still goes through executor (does not throw CircuitOpenException).
        $result = $client->send('https://api.example.com/x', $executor);
        $this->assertSame(400, $result['http_code']);
    }

    public function testServerErrorsOpenBreakerAndSubsequentCallThrows(): void
    {
        $client = $this->makeClient(failureThreshold: 1);
        $executor = $this->executorReturning(array_fill(0, 10, [
            'success' => false, 'http_code' => 503, 'response' => null, 'error' => 'HTTP 503', 'curl_errno' => 0,
        ]));

        $client->send('https://api.example.com/x', $executor); // 3 attempts, all 503 → breaker opens

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/ResilientHttpClientTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Service/Resilience/ResilientHttpClient.php`:

```php
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
    /** @var callable(int): void */
    private $sleepFn;

    /**
     * @param (callable(int): void)|null $sleepFn Optional sleep override for tests. Receives microseconds.
     */
    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly RetryPolicy $retryPolicy,
        ?callable $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn ?? static fn (int $micros) => usleep($micros);
    }

    /**
     * @param callable(): array{success: bool, http_code: int, response: ?string, error: ?string, curl_errno: int} $executor
     * @return array{success: bool, http_code: int, response: ?string, error: ?string, curl_errno?: int}
     * @throws CircuitOpenException When the breaker is open for this host.
     */
    public function send(string $url, callable $executor): array
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');

        if ($host !== '' && !$this->breaker->isAvailable($host)) {
            $seconds = $this->breaker->secondsOpen($host);
            Log::warning('circuit_breaker.rejected', ['host' => $host, 'seconds_open' => $seconds]);
            throw new CircuitOpenException($host, $seconds);
        }

        $result = ['success' => false, 'http_code' => 0, 'response' => null, 'error' => 'no attempt'];
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

            // No more retries. If the failure counts as transient, the breaker hears about it.
            if ($host !== '' && $shouldRetry) {
                $this->breaker->recordFailure($host);
            }
            return $result;
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/ResilientHttpClientTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Run the full resilience suite**

Run: `vendor/bin/phpunit tests/TestCase/Service/Resilience/`
Expected: PASS, all tests across the three files.

- [ ] **Step 6: Code style**

Run: `composer cs-fix && composer cs-check`
Expected: only pre-existing errors (none in new files).

- [ ] **Step 7: Commit**

```bash
git add src/Service/Resilience/ResilientHttpClient.php tests/TestCase/Service/Resilience/ResilientHttpClientTest.php
git commit -m "feat(resilience): add ResilientHttpClient orchestrator"
```

---

## Task 7: Add `Resilience` config block

**Files:**
- Modify: `config/app.php`

- [ ] **Step 1: Add the config block**

In `config/app.php`, at the top level of the returned array (alongside `Cache`, `Email`, etc.), add:

```php
    'Resilience' => [
        'circuitBreaker' => [
            'failureThreshold' => (int)env('RESILIENCE_CB_THRESHOLD', 5),
            'cooldownSeconds' => (int)env('RESILIENCE_CB_COOLDOWN', 30),
        ],
        'retry' => [
            'maxAttempts' => (int)env('RESILIENCE_RETRY_ATTEMPTS', 3),
            'baseDelayMs' => (int)env('RESILIENCE_RETRY_BASE_MS', 200),
            'backoffMultiplier' => 2.5,
            'jitterMs' => 100,
        ],
    ],
```

- [ ] **Step 2: Verify config loads**

Run: `bin/cake server -p 0` then immediately `Ctrl+C` (just confirms bootstrap succeeds), OR run a quick CakePHP CLI command that reads config:

Run: `bin/cake migrations status`
Expected: succeeds (no fatal on bootstrap).

- [ ] **Step 3: Commit**

```bash
git add config/app.php
git commit -m "feat(resilience): add Resilience config block with env overrides"
```

---

## Task 8: Refactor `SecureHttpTrait` to extract `executeRawCurlPost`

**Files:**
- Modify: `src/Service/Traits/SecureHttpTrait.php`

This task is a **pure structural refactor** — no behavior change yet. Confidence is built by running existing tests after the move.

- [ ] **Step 1: Inspect existing tests that exercise the trait**

Run: `vendor/bin/phpunit --list-tests tests/TestCase/Service/ | grep -i secure` (if any). If none, note: there are no existing tests for the trait — we'll add them in Task 10.

- [ ] **Step 2: Move the curl logic into a private helper**

In `src/Service/Traits/SecureHttpTrait.php`, replace the body of `secureCurlPost()` from line 132 down to the `return` (lines 132-169 in the current file) so it now calls a new private method. The full updated method pair looks like:

```php
    private function secureCurlPost(string $url, string $jsonPayload, array $headers = [], int $timeout = 10): array
    {
        $resolution = $this->resolveAndValidateUrl($url);
        if (!$resolution['ok']) {
            Log::warning('SSRF protection blocked request', ['url' => $url, 'reason' => $resolution['error']]);

            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $resolution['error'],
                'curl_errno' => 0,
            ];
        }

        return $this->executeRawCurlPost($url, $jsonPayload, $headers, $timeout, $resolution);
    }

    /**
     * @param array{ok: bool, error: ?string, host: ?string, port: ?int, ip: ?string} $resolution
     * @return array{success: bool, http_code: int, response: string|null, error: string|null, curl_errno: int}
     */
    private function executeRawCurlPost(string $url, string $jsonPayload, array $headers, int $timeout, array $resolution): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, min($timeout, 30));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);

        if ($resolution['ip'] !== null && $resolution['host'] !== null && $resolution['port'] !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [
                sprintf('%s:%d:%s', $resolution['host'], $resolution['port'], $resolution['ip']),
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        if ($error) {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $error,
                'curl_errno' => $errno,
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $response ?: null,
            'error' => $httpCode >= 300 ? 'HTTP ' . $httpCode : null,
            'curl_errno' => $errno,
        ];
    }
```

Note the addition of `curl_errno` to every response shape — it is required by `ResilientHttpClient` and is harmless to existing callers (extra array key).

- [ ] **Step 3: Run the full test suite**

Run: `composer test`
Expected: no regressions vs. baseline. If any test was asserting on the exact shape of `secureCurlPost` return without an extra key, fix that test minimally — the new key is `curl_errno` (an int).

- [ ] **Step 4: Commit**

```bash
git add src/Service/Traits/SecureHttpTrait.php
git commit -m "refactor(http): extract executeRawCurlPost from secureCurlPost"
```

---

## Task 9: Wire `ResilientHttpClient` into `SecureHttpTrait::secureCurlPost`

**Files:**
- Modify: `src/Service/Traits/SecureHttpTrait.php`

- [ ] **Step 1: Add the trait property and constructor-less lazy getter**

Inside the trait, near the top of the class body, add:

```php
    private ?\App\Service\Resilience\ResilientHttpClient $resilientHttp = null;

    private function resilientHttp(): \App\Service\Resilience\ResilientHttpClient
    {
        if ($this->resilientHttp === null) {
            $cb = \Cake\Core\Configure::read('Resilience.circuitBreaker') ?? [];
            $rt = \Cake\Core\Configure::read('Resilience.retry') ?? [];
            $this->resilientHttp = new \App\Service\Resilience\ResilientHttpClient(
                new \App\Service\Resilience\CircuitBreaker(
                    \App\Constants\CacheConstants::CACHE_RESILIENCE,
                    failureThreshold: (int)($cb['failureThreshold'] ?? 5),
                    cooldownSeconds: (int)($cb['cooldownSeconds'] ?? 30),
                ),
                new \App\Service\Resilience\RetryPolicy(
                    maxAttempts: (int)($rt['maxAttempts'] ?? 3),
                    baseDelayMs: (int)($rt['baseDelayMs'] ?? 200),
                    backoffMultiplier: (float)($rt['backoffMultiplier'] ?? 2.5),
                    jitterMs: (int)($rt['jitterMs'] ?? 100),
                ),
            );
        }

        return $this->resilientHttp;
    }

    /**
     * Test hook: override the resilient client. Used only by tests.
     */
    public function setResilientHttpClientForTesting(\App\Service\Resilience\ResilientHttpClient $client): void
    {
        $this->resilientHttp = $client;
    }
```

- [ ] **Step 2: Route `secureCurlPost` through the client**

Replace the entire body of `secureCurlPost` with:

```php
    private function secureCurlPost(string $url, string $jsonPayload, array $headers = [], int $timeout = 10): array
    {
        $resolution = $this->resolveAndValidateUrl($url);
        if (!$resolution['ok']) {
            Log::warning('SSRF protection blocked request', ['url' => $url, 'reason' => $resolution['error']]);

            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $resolution['error'],
                'curl_errno' => 0,
            ];
        }

        try {
            return $this->resilientHttp()->send(
                $url,
                fn (): array => $this->executeRawCurlPost($url, $jsonPayload, $headers, $timeout, $resolution),
            );
        } catch (\App\Service\Resilience\CircuitOpenException $e) {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $e->getMessage(),
                'curl_errno' => 0,
                'circuit_breaker' => true,
            ];
        }
    }
```

- [ ] **Step 3: Run the full test suite**

Run: `composer test`
Expected: PASS. No new failures.

- [ ] **Step 4: PHPStan**

Run: `vendor/bin/phpstan analyse src/Service/Resilience src/Service/Traits/SecureHttpTrait.php`
Expected: 0 errors (or only pre-existing errors unrelated to these files).

- [ ] **Step 5: Code style**

Run: `composer cs-fix && composer cs-check`
Expected: only pre-existing errors.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Traits/SecureHttpTrait.php
git commit -m "feat(http): route secureCurlPost through ResilientHttpClient"
```

---

## Task 10: Integration test of trait + ResilientHttpClient

**Files:**
- Create: `tests/TestCase/Service/Traits/SecureHttpTraitTest.php` (if missing). If a file with this exact name exists, append the new test methods instead.

- [ ] **Step 1: Check whether the test file exists**

Run: `test -f tests/TestCase/Service/Traits/SecureHttpTraitTest.php && echo EXISTS || echo MISSING`

- [ ] **Step 2: If MISSING, create the file**

Create `tests/TestCase/Service/Traits/SecureHttpTraitTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Traits;

use App\Service\Resilience\CircuitBreaker;
use App\Service\Resilience\CircuitOpenException;
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
        // Pre-open the breaker for this host.
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

        // postExternal is exposed by the test subject below; it just calls secureCurlPost.
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
```

- [ ] **Step 3: Run the test**

Run: `vendor/bin/phpunit tests/TestCase/Service/Traits/SecureHttpTraitTest.php`
Expected: PASS, 1 test.

- [ ] **Step 4: Run full suite to confirm no regressions**

Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/TestCase/Service/Traits/SecureHttpTraitTest.php
git commit -m "test(http): cover circuit-open path in SecureHttpTrait"
```

---

## Task 11: Document `.env` variables and cache-backend requirement

**Files:**
- Modify: `config/.env.example` (if it exists in the repo; otherwise skip step and just add a README note)
- Modify: `README.md` (Docker section)

- [ ] **Step 1: Locate env example file**

Run: `ls config/.env.example config/.env.default 2>/dev/null`

- [ ] **Step 2: If an env example exists, append**

Append to the discovered file:

```ini
# Resilience (Circuit Breaker + Retry over outbound HTTP).
# Override only if monitoring shows false positives or you need more aggressive retry.
RESILIENCE_CB_THRESHOLD=5
RESILIENCE_CB_COOLDOWN=30
RESILIENCE_RETRY_ATTEMPTS=3
RESILIENCE_RETRY_BASE_MS=200
```

- [ ] **Step 3: Add README note**

In `README.md`, under the Docker / configuration section, add a short paragraph:

```markdown
### Resiliencia HTTP

Llamadas HTTP salientes (WhatsApp, n8n, Gmail webhooks) usan Circuit Breaker
+ Retry vía `App\Service\Resilience\ResilientHttpClient`. El estado del
breaker se persiste en el cache config `CacheConstants::CACHE_RESILIENCE`
(`resilience`). En producción este cache debe usar un backend compartido
entre workers (File o Redis), no `Array`. Ver
`docs/superpowers/specs/2026-05-15-tickets-resilience-design.md`.

Variables de entorno opcionales: `RESILIENCE_CB_THRESHOLD`,
`RESILIENCE_CB_COOLDOWN`, `RESILIENCE_RETRY_ATTEMPTS`,
`RESILIENCE_RETRY_BASE_MS`. Rollback de emergencia:
`RESILIENCE_CB_THRESHOLD=999999` deshabilita el breaker sin redeploy.
```

- [ ] **Step 4: Commit**

```bash
git add config/.env.example README.md
git commit -m "docs(resilience): document env vars + cache backend requirement"
```

---

## Task 12: Update the audit log

**Files:**
- Modify: `docs/audits/2026-05-14-tickets-module-audit.md`

- [ ] **Step 1: Update §1 (executive summary)**

In the table at §1, update the row "Salud arquitectónica global" to reflect a higher score (78-80% — bump from 72% by ~6-8 points for closing two criticals). Append "— CRIT-1 y CRIT-2 cerrados" to the current state cell.

Also update "Hallazgos Críticos (rojo)" from `3` to `1` in the "Estado actual" column.

- [ ] **Step 2: Update §2 (pattern matrix)**

Change rows:
- "Circuit Breaker" — Detectado `Sí`, Cumplimiento `Alto`, Severidad `Verde`.
- "Retry / Backoff" — Detectado `Sí`, Cumplimiento `Alto`, Severidad `Verde`.

- [ ] **Step 3: Mark CRIT-1 and CRIT-2 closed in §3**

After each finding's body, add a line like §11 entries already do:

```
**Cerrado 2026-05-15** — Implementado vía `App\Service\Resilience\ResilientHttpClient`
sobre `SecureHttpTrait::secureCurlPost`. Cubre WhatsApp/n8n/Gmail webhook POSTs.
Detalle en §11.
```

- [ ] **Step 4: Update §9 acciones priorizadas**

Mark row #1 (Circuit Breaker + Retry) as **Completado 2026-05-15**.

- [ ] **Step 5: Append §11 entry**

Add a new entry at the bottom of §11:

```markdown
### 2026-05-15 — CRIT-1 + CRIT-2 cerrados: Circuit Breaker + Retry sobre `SecureHttpTrait`

**Hallazgos cubiertos:** CRIT-1 (sin Circuit Breaker en APIs externas) y CRIT-2 (sin Retry/Backoff para errores transitorios).

**Decisiones de diseño:**
- Intervención única sobre `SecureHttpTrait::secureCurlPost` cubre WhatsApp, n8n y Gmail webhooks. Llamadas a Gmail API vía `Google\Client` quedan fuera de scope (no usan curl directo).
- Estado del Circuit Breaker persiste en cache compartido (`CacheConstants::CACHE_RESILIENCE`) — clave por host del URL.
- Política de Retry conservadora: 3 intentos para 5xx/429/`CURLE_OPERATION_TIMEOUTED`, backoff exponencial ~200ms/500ms/1.25s + jitter.
- 4xx no-429 NO cuentan como fallo del breaker (son errores del cliente).

**Cambios:**
- `src/Service/Resilience/` (nuevo): `RetryPolicy`, `CircuitBreaker`, `ResilientHttpClient`, `CircuitOpenException`.
- `src/Service/Traits/SecureHttpTrait.php`: curl extraído a `executeRawCurlPost()`; `secureCurlPost()` ahora delega al cliente resiliente y traduce `CircuitOpenException` al shape de error estándar (+ clave `circuit_breaker`).
- `src/Constants/CacheConstants.php`: nueva constante `CACHE_RESILIENCE`.
- `config/app.php`: bloque `Resilience.*` + cache engine `resilience`.
- README + `.env.example`: documentadas variables de override y requisito de backend de cache compartido.

**Despliegue:** sin migraciones, sin cambios de firma. Rollback de emergencia: `RESILIENCE_CB_THRESHOLD=999999` en `.env`.

**Validaciones:**
- `composer test`: PASS, suite de resiliencia + tests del trait verdes.
- `composer cs-check`: solo errores pre-existentes.
- `phpstan analyse src/Service/Resilience src/Service/Traits/SecureHttpTrait.php`: 0 errores.

**Hallazgos derivados pendientes:**
- Llamadas a Gmail API vía `Google\Client` siguen sin protección — requiere Guzzle middleware o decorator de `Google\Http\REST`.
- CRIT-3 (Outbox) sigue abierto — la resiliencia reduce p&eacute;rdida pero no elimina el riesgo de mensaje perdido entre `save()` y `dispatch()`.
```

- [ ] **Step 6: Commit**

```bash
git add docs/audits/2026-05-14-tickets-module-audit.md
git commit -m "docs(audit): close CRIT-1 + CRIT-2; update matrix and bitácora"
```

---

## Final Verification

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Coverage on new code**

Run: `vendor/bin/phpunit --filter Resilience`
Expected: 19+ tests across 3 files, all green.

- [ ] **Step 3: PHPStan**

Run: `vendor/bin/phpstan analyse src/Service/Resilience src/Service/Traits/SecureHttpTrait.php`
Expected: 0 errors.

- [ ] **Step 4: CS**

Run: `composer cs-check`
Expected: only pre-existing errors (no new ones in `src/Service/Resilience/` or the trait).

- [ ] **Step 5: Smoke test the cache config**

Run: `bin/cake migrations status` (just to confirm bootstrap doesn't fail)
Expected: succeeds.

- [ ] **Step 6: Confirm WhatsApp/n8n/Gmail services still function**

Manually exercise one outbound path. For example, in dev:

Run: `bin/cake import_gmail --max 1`
Expected: completes without error (or fails with a normal credentials error, not a resilience-related fatal).

---

## Notes for the implementer

- **Do not touch** `WhatsappService`, `N8nService`, or `GmailService`. Their public surface is unchanged and so is their integration. If you find yourself editing them, you've gone off-plan — re-read the spec.
- **Cache backend matters.** In dev the cache may be `File`, which works. If tests stub `CacheConstants::CACHE_RESILIENCE` to `Array`, that's fine because `Array` is process-local — irrelevant for tests but **dangerous in prod** where FPM workers are separate processes.
- **`curl_errno` is the only new key** in the response shape. Existing callers ignore unknown keys (PHP arrays). If a caller asserts on exact equality with `assertEquals($expected, $actual)` and the expected array doesn't include `curl_errno`, that test needs a minimal update — but no production caller does this.
- **Test against `Array` cache engine** for unit tests. Do NOT depend on the actual `resilience` cache config from `config/app.php` in tests — set up a dedicated `Cache::setConfig()` in each test's `setUp`.
