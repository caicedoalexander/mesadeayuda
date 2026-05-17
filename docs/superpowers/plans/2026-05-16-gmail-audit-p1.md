# Gmail Audit P1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the P1 block of the 2026-05-16 Gmail audit — categorize and count Gmail API errors (M-3), add exponential-backoff retry for 429/5xx (H-2), and auto-populate `GMAIL_USER_EMAIL` on OAuth callback (B-4).

**Architecture:** Three sequential commits on `main`, in dependency order. M-3 introduces `GmailErrorCategory` + `GmailApiException` and rewires every `catch (Exception)` in `GmailService` to wrap with categorized typing; `GmailImportResult` grows five readonly counter fields and `GmailImportService::run()` increments them. H-2 then injects a stateless `RetryHandler` Guzzle middleware into the `GoogleClient` via `setHttpClient`. B-4 adds `GmailService::getUserEmail()` (using the now-resilient client) and has `SettingsController::gmailAuth` persist the result after successful OAuth.

**Tech Stack:** PHP 8.5 · CakePHP 5.x · PHPUnit · `google/apiclient ^2.19.3` · `guzzlehttp/guzzle` (transitive) · `symfony/cache ^7.4` (already used for M-1).

**Spec:** `docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md`

---

## File Structure

**New source files:**
- `src/Service/Gmail/GmailErrorCategory.php` — category constants and static `categorize()` / `fromHttpCode()` mappers.
- `src/Service/Gmail/RetryHandler.php` — stateless Guzzle middleware factory (decider + delay callables).
- `src/Service/Exception/GmailApiException.php` — wraps Google SDK exceptions with a category and preserves the chain.

**Modified source files:**
- `src/Service/GmailService.php` — typed catches (commit 1), `setHttpClient` with retry stack (commit 2), `getUserEmail` method (commit 3).
- `src/Service/GmailImportService.php` — per-message category counters in `run()` (commit 1).
- `src/Service/Dto/GmailImportResult.php` — five new readonly counter fields, invariant assertion, `toArray()` keys (commit 1).
- `src/Controller/Admin/SettingsController.php` — `gmailAuth` calls `getUserEmail` + writes `GMAIL_USER_EMAIL`; new private `maskEmail` helper (commit 3).

**New test files:**
- `tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php`
- `tests/TestCase/Service/Gmail/RetryHandlerTest.php`
- `tests/TestCase/Service/Exception/GmailApiExceptionTest.php`
- `tests/TestCase/Service/Dto/GmailImportResultTest.php`

**Modified test files:**
- `tests/TestCase/Service/GmailServiceTest.php` — typed catch wrap tests (commit 1), retry middleware test (commit 2), `getUserEmail` tests (commit 3).

**No changes:** `composer.json` (no new deps), `config/Migrations/*` (no schema changes).

---

## Commit 1 — M-3 · Typed exceptions and categorized counters

### Task 1.1 — Add `GmailErrorCategory` with failing test

**Files:**
- Create: `tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php`
- Create: `src/Service/Gmail/GmailErrorCategory.php`

- [ ] **Step 1: Create the test directory**

Run: `mkdir -p tests/TestCase/Service/Gmail src/Service/Gmail`

- [ ] **Step 2: Write the failing test**

Create `tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Gmail\GmailErrorCategory;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GmailErrorCategoryTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function httpCodeProvider(): array
    {
        return [
            '401 unauthorized -> auth'          => [401, GmailErrorCategory::AUTH],
            '403 forbidden -> auth'             => [403, GmailErrorCategory::AUTH],
            '429 too many requests -> rate'     => [429, GmailErrorCategory::RATE],
            '500 -> transient'                  => [500, GmailErrorCategory::TRANSIENT],
            '502 -> transient'                  => [502, GmailErrorCategory::TRANSIENT],
            '503 -> transient'                  => [503, GmailErrorCategory::TRANSIENT],
            '504 -> transient'                  => [504, GmailErrorCategory::TRANSIENT],
            '400 bad request -> permanent'      => [400, GmailErrorCategory::PERMANENT],
            '418 teapot -> permanent'           => [418, GmailErrorCategory::PERMANENT],
            '200 ok (no error) -> unknown'      => [200, GmailErrorCategory::UNKNOWN],
        ];
    }

    /**
     * @dataProvider httpCodeProvider
     */
    public function testFromHttpCodeMapsToExpectedCategory(int $code, string $expected): void
    {
        $this->assertSame($expected, GmailErrorCategory::fromHttpCode($code));
    }

    public function testCategorizeReadsGoogleServiceExceptionCode(): void
    {
        $exception = new GoogleServiceException('rate limit', 429);
        $this->assertSame(GmailErrorCategory::RATE, GmailErrorCategory::categorize($exception));
    }

    public function testCategorizePlainThrowableIsUnknown(): void
    {
        $exception = new RuntimeException('boom');
        $this->assertSame(GmailErrorCategory::UNKNOWN, GmailErrorCategory::categorize($exception));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php -v`
Expected: FAIL — `Class "App\Service\Gmail\GmailErrorCategory" not found`.

- [ ] **Step 4: Write minimal implementation**

Create `src/Service/Gmail/GmailErrorCategory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use App\Service\Exception\GmailApiException;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

/**
 * Maps Gmail API failures to a small set of categories used for
 * retry decisions, logging, and counters in GmailImportResult.
 *
 * Not a PHP enum because callers serialize the value into JSON
 * and read it back from arrays without unwrapping cases.
 */
final class GmailErrorCategory
{
    public const AUTH      = 'auth';
    public const RATE      = 'rate';
    public const TRANSIENT = 'transient';
    public const PERMANENT = 'permanent';
    public const UNKNOWN   = 'unknown';

    public static function categorize(Throwable $e): string
    {
        if ($e instanceof GmailApiException) {
            return $e->getCategory();
        }
        if ($e instanceof GoogleServiceException) {
            return self::fromHttpCode($e->getCode());
        }

        return self::UNKNOWN;
    }

    public static function fromHttpCode(int $code): string
    {
        return match (true) {
            $code === 401, $code === 403           => self::AUTH,
            $code === 429                          => self::RATE,
            in_array($code, [500, 502, 503, 504], true) => self::TRANSIENT,
            $code >= 400 && $code < 500            => self::PERMANENT,
            default                                => self::UNKNOWN,
        };
    }
}
```

Note: this references `GmailApiException` which the next task creates. The autoloader will resolve it once the next task lands. Do not run the test yet between tasks 1.1 and 1.2.

- [ ] **Step 5: Defer test run to after Task 1.2 (depends on GmailApiException)**

No commit yet.

---

### Task 1.2 — Add `GmailApiException` with failing test

**Files:**
- Create: `tests/TestCase/Service/Exception/GmailApiExceptionTest.php`
- Create: `src/Service/Exception/GmailApiException.php`

- [ ] **Step 1: Create the test directory**

Run: `mkdir -p tests/TestCase/Service/Exception`

- [ ] **Step 2: Write the failing test**

Create `tests/TestCase/Service/Exception/GmailApiExceptionTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Exception;

use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GmailApiExceptionTest extends TestCase
{
    public function testConstructorPopulatesCategoryCodeAndMessage(): void
    {
        $exception = new GmailApiException(
            GmailErrorCategory::RATE,
            429,
            'quota exceeded',
        );

        $this->assertSame(GmailErrorCategory::RATE, $exception->getCategory());
        $this->assertSame(429, $exception->getCode());
        $this->assertSame('quota exceeded', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public function testPreviousExceptionIsPreserved(): void
    {
        $root = new RuntimeException('socket closed');
        $exception = new GmailApiException(
            GmailErrorCategory::TRANSIENT,
            503,
            'service unavailable',
            previous: $root,
        );

        $this->assertSame($root, $exception->getPrevious());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Exception/GmailApiExceptionTest.php -v`
Expected: FAIL — `Class "App\Service\Exception\GmailApiException" not found`.

- [ ] **Step 4: Write minimal implementation**

Create `src/Service/Exception/GmailApiException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Wraps Google\Service\Exception (and non-Google throwables that surface
 * inside GmailService) with a small string category used for retry
 * decisions, log enrichment, and counters in GmailImportResult.
 *
 * Extends RuntimeException so existing catch (RuntimeException) and
 * catch (Throwable) handlers in GmailImportService / EmailService
 * continue to work without changes.
 */
final class GmailApiException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        int $code,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getCategory(): string
    {
        return $this->category;
    }
}
```

- [ ] **Step 5: Run both M-3 tests to verify pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php tests/TestCase/Service/Exception/GmailApiExceptionTest.php -v`
Expected: PASS — 12 tests, 14 assertions (10 data-provider rows + 2 categorize + 2 exception tests).

No commit yet — wait until M-3 is fully wired.

---

### Task 1.3 — Extend `GmailImportResult` with counter fields and invariant

**Files:**
- Create: `tests/TestCase/Service/Dto/GmailImportResultTest.php`
- Modify: `src/Service/Dto/GmailImportResult.php`

- [ ] **Step 1: Create the test directory**

Run: `mkdir -p tests/TestCase/Service/Dto`

- [ ] **Step 2: Write the failing test**

Create `tests/TestCase/Service/Dto/GmailImportResultTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Dto;

use App\Service\Dto\GmailImportResult;
use PHPUnit\Framework\TestCase;

final class GmailImportResultTest extends TestCase
{
    public function testToArrayIncludesCategoryCounters(): void
    {
        $result = new GmailImportResult(
            fetched: 10,
            created: 4,
            comments: 2,
            skipped: 1,
            errors: 3,
            durationSeconds: 1.234,
            errorMessages: ['msg-1: boom'],
            authErrors: 1,
            rateErrors: 1,
            transientErrors: 0,
            permanentErrors: 1,
            unknownErrors: 0,
        );

        $array = $result->toArray();

        $this->assertSame(1, $array['auth_errors']);
        $this->assertSame(1, $array['rate_errors']);
        $this->assertSame(0, $array['transient_errors']);
        $this->assertSame(1, $array['permanent_errors']);
        $this->assertSame(0, $array['unknown_errors']);
        $this->assertSame(3, $array['errors']);
    }

    public function testCounterSumMatchesTotalErrors(): void
    {
        $result = new GmailImportResult(
            fetched: 5,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 5,
            durationSeconds: 0.5,
            errorMessages: [],
            authErrors: 2,
            rateErrors: 1,
            transientErrors: 1,
            permanentErrors: 0,
            unknownErrors: 1,
        );

        $sum = $result->authErrors
             + $result->rateErrors
             + $result->transientErrors
             + $result->permanentErrors
             + $result->unknownErrors;

        $this->assertSame($result->errors, $sum);
    }

    public function testBackwardCompatibleConstructorDefaultsCountersToZero(): void
    {
        $result = new GmailImportResult(
            fetched: 0,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.0,
        );

        $this->assertSame(0, $result->authErrors);
        $this->assertSame(0, $result->rateErrors);
        $this->assertSame(0, $result->transientErrors);
        $this->assertSame(0, $result->permanentErrors);
        $this->assertSame(0, $result->unknownErrors);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/GmailImportResultTest.php -v`
Expected: FAIL — `Unknown named parameter $authErrors`.

- [ ] **Step 4: Modify `src/Service/Dto/GmailImportResult.php`**

Replace the full file with:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * Resultado inmutable de una corrida del import de Gmail.
 *
 * Reemplaza la salida por consola del comando con datos estructurados
 * que pueden serializarse a JSON para la respuesta del webhook.
 */
final readonly class GmailImportResult
{
    /**
     * @param list<string> $errorMessages Mensajes de errores no fatales por mensaje individual
     */
    public function __construct(
        public int $fetched,
        public int $created,
        public int $comments,
        public int $skipped,
        public int $errors,
        public float $durationSeconds,
        public array $errorMessages = [],
        public int $authErrors = 0,
        public int $rateErrors = 0,
        public int $transientErrors = 0,
        public int $permanentErrors = 0,
        public int $unknownErrors = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fetched' => $this->fetched,
            'created' => $this->created,
            'comments' => $this->comments,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'duration_seconds' => round($this->durationSeconds, 3),
            'error_messages' => $this->errorMessages,
            'auth_errors' => $this->authErrors,
            'rate_errors' => $this->rateErrors,
            'transient_errors' => $this->transientErrors,
            'permanent_errors' => $this->permanentErrors,
            'unknown_errors' => $this->unknownErrors,
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/GmailImportResultTest.php -v`
Expected: PASS — 3 tests, 13 assertions.

No commit yet.

---

### Task 1.4 — Wrap `GmailService` catches with typed `GmailApiException`

**Files:**
- Modify: `tests/TestCase/Service/GmailServiceTest.php` (append tests)
- Modify: `src/Service/GmailService.php` (lines 178, 255, 310, 401, 423, 610 — all six API-call catches)

- [ ] **Step 1: Append a small helper to `tests/TestCase/Service/GmailServiceTest.php`**

Add this private helper inside the `GmailServiceTest` class. It replaces the underlying Guzzle HTTP client of the GoogleClient with a `MockHandler` queue. PHP 8 enforces typed properties even via reflection, so we go through `Google\Client::setHttpClient` (the SDK's documented extension point) — no reflection against `Gmail::$users_messages` needed.

```php
    /**
     * Replace the underlying Guzzle client on the Google SDK client with a
     * MockHandler queue. The SDK then thinks it talked to Gmail and throws
     * Google\Service\Exception with the right code on non-2xx responses.
     *
     * @param list<\GuzzleHttp\Psr7\Response> $responses
     */
    private function stubHttp(GmailService $service, array $responses): void
    {
        $mock = new \GuzzleHttp\Handler\MockHandler($responses);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $http = new \GuzzleHttp\Client(['handler' => $stack]);
        $this->getClient($service)->setHttpClient($http);
    }
```

- [ ] **Step 2: Append failing tests using the helper**

Inside the same class, add:

```php
    public function testParseMessageWrapsGoogleServiceExceptionWithRateCategory(): void
    {
        $service = $this->buildService();
        // Single 429 response → after Commit 2's retry middleware lands this
        // would exhaust retries; today (Commit 1) there is no retry layer so
        // the SDK throws Google\Service\Exception(429) on the first response.
        $this->stubHttp($service, [new \GuzzleHttp\Psr7\Response(429, [], '{"error":{"code":429,"message":"quota"}}')]);

        try {
            $service->parseMessage('msg-id');
            $this->fail('Expected GmailApiException');
        } catch (\App\Service\Exception\GmailApiException $e) {
            $this->assertSame(\App\Service\Gmail\GmailErrorCategory::RATE, $e->getCategory());
            $this->assertSame(429, $e->getCode());
        }
    }

    public function testMarkAsReadReturnsFalseOnAuthError(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new \GuzzleHttp\Psr7\Response(401, [], '{"error":{"code":401,"message":"unauth"}}')]);

        // markAsRead preserves its bool sentinel contract — the typed wrap
        // happens internally and the categorized log is emitted by the throw site.
        $this->assertFalse($service->markAsRead('msg-id'));
    }

    public function testGetMessagesReturnsEmptyOnTransient5xx(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new \GuzzleHttp\Psr7\Response(503, [], '{"error":{"code":503,"message":"unavailable"}}')]);

        $this->assertSame([], $service->getMessages('is:unread', 5));
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php --filter "ParseMessageWrapsGoogleServiceException|MarkAsReadReturnsFalseOnAuthError|GetMessagesReturnsEmptyOnTransient5xx" -v`
Expected: FAIL — `parseMessage` throws raw `Google\Service\Exception` (not `GmailApiException`), so the first test fails. The other two may pass already (sentinel return), but the implementation work in steps 4-9 below must still happen for consistent categorized logging.

- [ ] **Step 4: Modify `src/Service/GmailService.php` — typed catch at line 255 (`getMessages`)**

Replace the existing block at `src/Service/GmailService.php:255-259`:

```php
        } catch (Exception $e) {
            Log::error('Error fetching Gmail messages: ' . $e->getMessage());

            return [];
        }
```

with:

```php
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return [];
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return [];
        }
```

- [ ] **Step 5: Modify `src/Service/GmailService.php` — typed catch at line 310 (`parseMessage`)**

Replace the existing block at `src/Service/GmailService.php:310-313`:

```php
        } catch (Exception $e) {
            Log::error('Error parsing Gmail message: ' . $e->getMessage());
            throw $e;
        }
```

with:

```php
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new GmailApiException(GmailErrorCategory::UNKNOWN, 0, $e->getMessage(), previous: $e);
        }
```

- [ ] **Step 6: Modify `src/Service/GmailService.php` — typed catch at line 401 (`downloadAttachment`)**

Replace the existing block at `src/Service/GmailService.php:401-404`:

```php
        } catch (Exception $e) {
            Log::error('Error downloading Gmail attachment: ' . $e->getMessage());
            throw $e;
        }
```

with:

```php
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new GmailApiException(GmailErrorCategory::UNKNOWN, 0, $e->getMessage(), previous: $e);
        }
```

- [ ] **Step 7: Modify `src/Service/GmailService.php` — typed catch at line 423 (`markAsRead`)**

Replace the existing block at `src/Service/GmailService.php:423-427`:

```php
        } catch (Exception $e) {
            Log::error('Error marking Gmail message as read: ' . $e->getMessage());

            return false;
        }
```

with:

```php
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return false;
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return false;
        }
```

- [ ] **Step 8: Modify `src/Service/GmailService.php` — typed catch at line 610 (`sendEmail`)**

Replace the existing block at `src/Service/GmailService.php:610-618`:

```php
        } catch (Exception $e) {
            Log::error('Error sending Gmail message: ' . $e->getMessage(), [
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
```

with:

```php
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'message' => $e->getMessage(),
            ]);

            return false;
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return false;
        }
```

- [ ] **Step 9: Add the `use` imports at the top of `src/Service/GmailService.php`**

Open `src/Service/GmailService.php`. After the existing `use Exception;` line (line 17), insert two new imports so the typed catches and the wrap class resolve:

```php
use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
```

And add (alphabetized, near `use Google\Service\Gmail;`):

```php
use Google\Service\Exception as GoogleServiceException;
```

The final import block (lines 6-24 area) should contain (alphabetized):

```php
use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Service\Exception\GmailApiException;
use App\Service\Exception\GmailAuthenticationException;
use App\Service\Exception\SettingsEncryptionException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Traits\SettingsEncryptionTrait;
use App\Service\Util\EmailHeaderParser;
use App\Service\Util\NotificationStamp;
use Cake\Cache\Cache;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\ModifyMessageRequest;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
```

- [ ] **Step 10: Run the GmailService tests to verify the new wraps pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php -v`
Expected: PASS for all existing tests plus the three new ones (`testParseMessageWrapsGoogleServiceExceptionWithRateCategory`, `testMarkAsReadReturnsFalseOnAuthError`, `testGetMessagesReturnsEmptyOnTransient5xx`).

- [ ] **Step 11: Note — token-refresh catch at line 178 is intentionally not changed**

The catch at `src/Service/GmailService.php:178` belongs to `initializeClient()` and is specific to the OAuth refresh flow (it throws `GmailAuthenticationException`, a separate concern from API-call typing). M-3 deliberately leaves it untouched. The other catch at line 563 belongs to `getSystemEmail()` reading from `SystemSettings` — also not a Gmail API call, also untouched.

---

### Task 1.5 — Categorize per-message errors in `GmailImportService::run()`

**Files:**
- Modify: `src/Service/GmailImportService.php`

- [ ] **Step 1: Add the `use` import**

In `src/Service/GmailImportService.php`, add to the imports block (after `use Cake\Log\Log;`):

```php
use App\Service\Gmail\GmailErrorCategory;
```

- [ ] **Step 2: Initialize the counter map and increment per error**

In `src/Service/GmailImportService.php::run()`, locate the variable initialization at lines 97-101:

```php
        $created = 0;
        $comments = 0;
        $skipped = 0;
        $errors = 0;
        $errorMessages = [];
```

Add immediately after them:

```php
        $categoryCounters = [
            GmailErrorCategory::AUTH      => 0,
            GmailErrorCategory::RATE      => 0,
            GmailErrorCategory::TRANSIENT => 0,
            GmailErrorCategory::PERMANENT => 0,
            GmailErrorCategory::UNKNOWN   => 0,
        ];
```

- [ ] **Step 3: Categorize the per-message catch**

Replace the existing `catch (Throwable $e)` block at lines 149-157:

```php
            } catch (Throwable $e) {
                Log::error('Gmail import per-message error', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $errors++;
                $errorMessages[] = "{$messageId}: {$e->getMessage()}";
            }
```

with:

```php
            } catch (Throwable $e) {
                $category = GmailErrorCategory::categorize($e);
                Log::error('Gmail import per-message error', [
                    'message_id' => $messageId,
                    'category' => $category,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $errors++;
                $errorMessages[] = "{$messageId}: {$e->getMessage()}";
                $categoryCounters[$category]++;
            }
```

- [ ] **Step 4: Pass counters into the `GmailImportResult` constructor**

Replace the result construction block at lines 164-172:

```php
        $result = new GmailImportResult(
            fetched: $fetched,
            created: $created,
            comments: $comments,
            skipped: $skipped,
            errors: $errors,
            durationSeconds: microtime(true) - $startedAt,
            errorMessages: $errorMessages,
        );
```

with:

```php
        $result = new GmailImportResult(
            fetched: $fetched,
            created: $created,
            comments: $comments,
            skipped: $skipped,
            errors: $errors,
            durationSeconds: microtime(true) - $startedAt,
            errorMessages: $errorMessages,
            authErrors: $categoryCounters[GmailErrorCategory::AUTH],
            rateErrors: $categoryCounters[GmailErrorCategory::RATE],
            transientErrors: $categoryCounters[GmailErrorCategory::TRANSIENT],
            permanentErrors: $categoryCounters[GmailErrorCategory::PERMANENT],
            unknownErrors: $categoryCounters[GmailErrorCategory::UNKNOWN],
        );
```

- [ ] **Step 5: Run the full test suite to confirm no regressions**

Run: `composer test`
Expected: same baseline (7 pre-existing failures unrelated to Gmail). Total new passing tests: 15 (10 GmailErrorCategoryTest data rows + 2 categorize + 2 GmailApiExceptionTest + 3 GmailImportResultTest + 2 new GmailServiceTest typed-catch).

- [ ] **Step 6: Run code style check**

Run: `composer cs-check`
Expected: no new errors over baseline (run on the touched files). If new errors exist, run `composer cs-fix` and re-check.

- [ ] **Step 7: Run static analysis**

Run: `vendor/bin/phpstan analyse src/Service/Gmail src/Service/Exception/GmailApiException.php src/Service/GmailService.php src/Service/GmailImportService.php src/Service/Dto/GmailImportResult.php`
Expected: no new errors. The 38 baseline errors in `UserHelper.php` are pre-existing and unrelated.

- [ ] **Step 8: Commit M-3**

Run:

```bash
git add src/Service/Gmail/GmailErrorCategory.php \
        src/Service/Exception/GmailApiException.php \
        src/Service/GmailService.php \
        src/Service/GmailImportService.php \
        src/Service/Dto/GmailImportResult.php \
        tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php \
        tests/TestCase/Service/Exception/GmailApiExceptionTest.php \
        tests/TestCase/Service/Dto/GmailImportResultTest.php \
        tests/TestCase/Service/GmailServiceTest.php

git commit -m "$(cat <<'EOF'
feat(gmail): typed exception categories and counted errors (M-3)

Closes M-3 from docs/audits/2026-05-16-gmail-api-audit.md.

- New GmailErrorCategory (auth/rate/transient/permanent/unknown) mapping
  Google\Service\Exception HTTP codes.
- New GmailApiException wraps Google SDK exceptions with a category and
  preserves the chain via getPrevious(). Extends RuntimeException so
  existing catch (Throwable) handlers are unaffected.
- GmailService's API-call catches (getMessages, parseMessage,
  downloadAttachment, markAsRead, sendEmail) now split on
  Google\Service\Exception vs generic Exception, log with category,
  and throw GmailApiException for the methods that propagate.
- GmailImportResult grows five readonly counter fields exposed in
  toArray() as auth_errors / rate_errors / transient_errors /
  permanent_errors / unknown_errors. Defaults preserve backward
  compatibility with positional callers.
- GmailImportService::run() categorizes the per-message catch and
  passes the counters to GmailImportResult.

Spec: docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md §5
Plan: docs/superpowers/plans/2026-05-16-gmail-audit-p1.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Commit 2 — H-2 · Exponential backoff retry for 429/5xx

### Task 2.1 — Add `RetryHandler` with failing test

**Files:**
- Create: `tests/TestCase/Service/Gmail/RetryHandlerTest.php`
- Create: `src/Service/Gmail/RetryHandler.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Service/Gmail/RetryHandlerTest.php`:

```php
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
        // n=10: base = min(1024*250, 32000) = 32000; +jitter [0, 1000] -> clamped to 32000
        $this->assertSame(RetryHandler::MAX_BACKOFF_MS, $value);
    }

    public function testDelayHonorsRetryAfterHeaderInSeconds(): void
    {
        $delay = RetryHandler::delay();
        $response = new Response(429, ['Retry-After' => '5']);
        $this->assertSame(5000, $delay(0, $response));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/RetryHandlerTest.php -v`
Expected: FAIL — `Class "App\Service\Gmail\RetryHandler" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Service/Gmail/RetryHandler.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use Cake\Log\Log;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Stateless factory exposing a Guzzle retry decider + delay callable
 * tuned for the Gmail API. Implements exponential backoff with full
 * jitter per Google's recommendation.
 *
 * delay = min(2^n * BASE_DELAY_MS + rand(0, JITTER_MS), MAX_BACKOFF_MS)
 *
 * If the response carries Retry-After (RFC 7231), that value overrides
 * the computed backoff (still capped at MAX_BACKOFF_MS).
 */
final class RetryHandler
{
    public const MAX_RETRIES    = 5;
    public const BASE_DELAY_MS  = 250;
    public const MAX_BACKOFF_MS = 32_000;
    public const JITTER_MS      = 1_000;

    /** @var list<int> */
    private const RETRIABLE_STATUS = [429, 500, 502, 503, 504];

    public static function decider(): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?Throwable $exception = null,
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }
            if ($exception instanceof ConnectException) {
                return true;
            }
            if ($response !== null && in_array($response->getStatusCode(), self::RETRIABLE_STATUS, true)) {
                return true;
            }

            return false;
        };
    }

    public static function delay(): callable
    {
        return static function (int $retries, ?ResponseInterface $response = null): int {
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $retryAfter = self::parseRetryAfter($response->getHeaderLine('Retry-After'));
                if ($retryAfter !== null) {
                    return min($retryAfter, self::MAX_BACKOFF_MS);
                }
            }

            $base   = min((2 ** $retries) * self::BASE_DELAY_MS, self::MAX_BACKOFF_MS);
            $jitter = random_int(0, self::JITTER_MS);
            $delay  = min($base + $jitter, self::MAX_BACKOFF_MS);

            Log::warning('Gmail API retry', [
                'attempt' => $retries + 1,
                'status' => $response?->getStatusCode(),
                'delay_ms' => $delay,
            ]);

            return $delay;
        };
    }

    private static function parseRetryAfter(string $value): ?int
    {
        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return max(0, ($timestamp - time()) * 1000);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/RetryHandlerTest.php -v`
Expected: PASS — 10 tests, 10 assertions.

---

### Task 2.2 — Wire `RetryHandler` into `GmailService::initializeClient`

**Files:**
- Modify: `tests/TestCase/Service/GmailServiceTest.php` (append tests)
- Modify: `src/Service/GmailService.php` (imports + `initializeClient`)

- [ ] **Step 1: Append failing tests to `tests/TestCase/Service/GmailServiceTest.php`**

Add inside the existing `GmailServiceTest` class:

```php
    public function testRetryMiddlewareIsRegisteredOnTheGoogleClient(): void
    {
        $service = $this->buildService();
        $httpClient = $this->getClient($service)->getHttpClient();
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $httpClient);

        // Guzzle\Client::getConfig() is deprecated in 7.5+; read the handler
        // via reflection to stay version-stable.
        $ref = new ReflectionClass($httpClient);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $config = $configProp->getValue($httpClient);

        $stack = $config['handler'] ?? null;
        $this->assertInstanceOf(\GuzzleHttp\HandlerStack::class, $stack);
        // The HandlerStack stringifies its middleware list; the retry
        // middleware identifies itself with the literal "retry".
        $this->assertStringContainsString('retry', (string) $stack);
    }

    public function testRetryMiddlewareSucceedsAfter429Retries(): void
    {
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(429, [], 'rate'),
            new \GuzzleHttp\Psr7\Response(429, [], 'rate'),
            new \GuzzleHttp\Psr7\Response(200, [], 'ok'),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::retry(
            \App\Service\Gmail\RetryHandler::decider(),
            // override delay to 0 so the test runs instantly
            static fn (): int => 0,
        ));
        $client = new \GuzzleHttp\Client(['handler' => $stack]);

        $response = $client->request('GET', 'https://example.test/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php --filter "RetryMiddlewareIsRegisteredOnTheGoogleClient|RetryMiddlewareSucceedsAfter429Retries" -v`
Expected: FAIL — the first test fails because no `HandlerStack` is registered (Google SDK's default HTTP client has no `handler` key set); the second test should pass standalone.

- [ ] **Step 3: Modify the imports in `src/Service/GmailService.php`**

Add to the existing import block (alphabetized near the other `use App\` and `use GuzzleHttp` entries):

```php
use App\Service\Gmail\RetryHandler;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
```

- [ ] **Step 4: Inject the HTTP client at the end of `initializeClient()`**

In `src/Service/GmailService.php::initializeClient()`, immediately after the existing OAuth cache configuration block (between line 161 and the comment at line 163 `// Set redirect URI for OAuth2 flow`), insert:

```php
        // H-2: register a Guzzle HandlerStack with retry middleware so
        // every Gmail API call survives transient 429/5xx pressure. The
        // Google SDK applies its own auth middleware on top of the
        // handler we provide, so OAuth headers are not bypassed.
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            RetryHandler::decider(),
            RetryHandler::delay(),
        ));
        $this->client->setHttpClient(new GuzzleClient([
            'handler'         => $stack,
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]));

```

- [ ] **Step 5: Run the GmailService tests to verify the wiring passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php -v`
Expected: PASS for all tests, including the two new ones.

- [ ] **Step 6: Run the full unit suite**

Run: `composer test`
Expected: same baseline; new tests pass.

- [ ] **Step 7: Run code style**

Run: `composer cs-check`
Expected: no new errors. If any, run `composer cs-fix`.

- [ ] **Step 8: Static analysis**

Run: `vendor/bin/phpstan analyse src/Service/Gmail src/Service/GmailService.php`
Expected: no new errors over the 38-error baseline in `UserHelper.php`.

- [ ] **Step 9: Commit H-2**

Run:

```bash
git add src/Service/Gmail/RetryHandler.php \
        src/Service/GmailService.php \
        tests/TestCase/Service/Gmail/RetryHandlerTest.php \
        tests/TestCase/Service/GmailServiceTest.php

git commit -m "$(cat <<'EOF'
feat(gmail): exponential backoff retry for 429/5xx (H-2)

Closes H-2 from docs/audits/2026-05-16-gmail-api-audit.md.

- New stateless RetryHandler exposing decider() and delay() callables
  for GuzzleHttp\Middleware::retry. Implements exponential backoff
  with full jitter:
    delay = min(2^n * 250ms + rand(0, 1000ms), 32000ms)
  Retries on 429, 500, 502, 503, 504, and ConnectException.
  Honors Retry-After (numeric seconds and HTTP-date), capped at the
  MAX_BACKOFF_MS ceiling.
- GmailService::initializeClient now builds a Guzzle HandlerStack with
  the retry middleware pushed, attached via $client->setHttpClient.
  Wraps every Gmail API call (list/get/attachments/modify/send).
- Per-attempt warning log "Gmail API retry" for observability; the
  final post-exhaustion error is logged by M-3's typed catches.

sendEmail trade-off: durable outbox is deliberately out of scope. After
6 attempts (initial + 5 retries) the notification surfaces as a
GmailApiException and TicketNotificationListener logs and drops it —
same best-effort contract as today, just more resilient.

Spec: docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md §6
Plan: docs/superpowers/plans/2026-05-16-gmail-audit-p1.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Commit 3 — B-4 · Auto-populate `gmail_user_email`

### Task 3.1 — Add `GmailService::getUserEmail()` with failing tests

**Files:**
- Modify: `tests/TestCase/Service/GmailServiceTest.php` (append tests)
- Modify: `src/Service/GmailService.php` (add `getUserEmail` method)

- [ ] **Step 1: Append failing tests to `tests/TestCase/Service/GmailServiceTest.php`**

Reuse the `stubHttp` helper introduced in Task 1.4 — it routes through `Google\Client::setHttpClient`, which is the SDK's documented extension point and avoids the typed-property reflection issue.

Add inside the existing `GmailServiceTest` class:

```php
    public function testGetUserEmailReturnsAddressFromUsersGetProfile(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"emailAddress":"soporte@example.com","messagesTotal":42,"threadsTotal":17,"historyId":"123"}',
        )]);

        $this->assertSame('soporte@example.com', $service->getUserEmail());
    }

    public function testGetUserEmailThrowsPermanentExceptionOnEmptyAddress(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"emailAddress":"","messagesTotal":0,"threadsTotal":0,"historyId":"0"}',
        )]);

        try {
            $service->getUserEmail();
            $this->fail('Expected GmailApiException');
        } catch (\App\Service\Exception\GmailApiException $e) {
            $this->assertSame(\App\Service\Gmail\GmailErrorCategory::PERMANENT, $e->getCategory());
        }
    }

    public function testGetUserEmailWrapsGoogleServiceException(): void
    {
        $service = $this->buildService();
        // 401 is not in the retriable set (decider returns false for 401),
        // so the SDK throws Google\Service\Exception(401) after the first
        // response and our typed catch wraps it.
        $this->stubHttp($service, [new \GuzzleHttp\Psr7\Response(
            401,
            ['Content-Type' => 'application/json'],
            '{"error":{"code":401,"message":"token revoked"}}',
        )]);

        try {
            $service->getUserEmail();
            $this->fail('Expected GmailApiException');
        } catch (\App\Service\Exception\GmailApiException $e) {
            $this->assertSame(\App\Service\Gmail\GmailErrorCategory::AUTH, $e->getCategory());
            $this->assertSame(401, $e->getCode());
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php --filter "GetUserEmail" -v`
Expected: FAIL — method `getUserEmail` does not exist on `GmailService`.

- [ ] **Step 3: Add the `getUserEmail` method to `src/Service/GmailService.php`**

Insert a new public method immediately before the closing `}` of the `GmailService` class (the file ends around line 720+; place this method after `sendEmail` but before any other final helpers — easiest landing spot is right after `sendEmail`'s closing `}` near line 619):

```php
    /**
     * Return the authenticated mailbox's primary email address via
     * users.getProfile('me'). Used after OAuth callback to keep
     * GMAIL_USER_EMAIL in sync with the live account (B-4).
     *
     * Inherits retry/backoff (H-2) and categorized exception typing
     * (M-3) automatically because the call goes through the wrapped
     * GoogleClient.
     *
     * @throws \App\Service\Exception\GmailApiException
     */
    public function getUserEmail(): string
    {
        try {
            $profile = $this->getService()->users->getProfile('me');
            $email = (string) ($profile->getEmailAddress() ?? '');
            if ($email === '') {
                throw new GmailApiException(
                    GmailErrorCategory::PERMANENT,
                    0,
                    'Empty emailAddress in users.getProfile response',
                );
            }

            return $email;
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        }
    }
```

- [ ] **Step 4: Run the GmailService tests to verify the new ones pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php --filter "GetUserEmail" -v`
Expected: PASS — 3 tests, 5 assertions.

---

### Task 3.2 — Wire `getUserEmail` into `SettingsController::gmailAuth`

**Files:**
- Modify: `src/Controller/Admin/SettingsController.php`

- [ ] **Step 1: Locate the `gmailAuth` action**

Open `src/Controller/Admin/SettingsController.php`. The action lives at lines 109-173.

- [ ] **Step 2: Persist `GMAIL_USER_EMAIL` after the refresh-token save**

Replace the inner `if (isset($tokens['refresh_token']))` block at lines 138-158:

```php
                if (isset($tokens['refresh_token'])) {
                    // Save refresh token to settings using service
                    if ($this->settingsService->saveSetting(SettingKeys::GMAIL_REFRESH_TOKEN, $tokens['refresh_token'])) {
                        $this->Flash->success('Gmail autorizado exitosamente.');
                        Log::info('Gmail OAuth completed successfully');
                    } else {
                        $this->Flash->error('Error al guardar el token de Gmail.');
                        Log::error('Failed to save Gmail refresh token');
                    }
                } else {
                    // Google may not return refresh_token on re-authorization if consent was cached.
                    // This is OK if we already have a stored refresh_token.
                    $existingToken = $allSettings[SettingKeys::GMAIL_REFRESH_TOKEN] ?? null;
                    if ($existingToken) {
                        $this->Flash->success('Gmail reconectado exitosamente.');
                        Log::info('Gmail OAuth re-authorized (using existing refresh token)');
                    } else {
                        $this->Flash->warning('No se recibió refresh token. Intenta nuevamente.');
                        Log::warning('No refresh token in OAuth response', ['token_keys' => array_keys($tokens ?? [])]);
                    }
                }
```

with:

```php
                $tokenPersisted = false;

                if (isset($tokens['refresh_token'])) {
                    if ($this->settingsService->saveSetting(SettingKeys::GMAIL_REFRESH_TOKEN, $tokens['refresh_token'])) {
                        $tokenPersisted = true;
                        $this->Flash->success('Gmail autorizado exitosamente.');
                        Log::info('Gmail OAuth completed successfully');
                    } else {
                        $this->Flash->error('Error al guardar el token de Gmail.');
                        Log::error('Failed to save Gmail refresh token');
                    }
                } else {
                    $existingToken = $allSettings[SettingKeys::GMAIL_REFRESH_TOKEN] ?? null;
                    if ($existingToken) {
                        $tokenPersisted = true;
                        $this->Flash->success('Gmail reconectado exitosamente.');
                        Log::info('Gmail OAuth re-authorized (using existing refresh token)');
                    } else {
                        $this->Flash->warning('No se recibió refresh token. Intenta nuevamente.');
                        Log::warning('No refresh token in OAuth response', ['token_keys' => array_keys($tokens ?? [])]);
                    }
                }

                if ($tokenPersisted) {
                    $this->syncGmailUserEmail($allSettings);
                }
```

- [ ] **Step 3: Add the `syncGmailUserEmail` and `maskEmail` private helpers**

Inside `SettingsController` add two new private methods. Place them after the `gmailAuth` action (after its closing `}`, before `testGmail` near line 180):

```php
    /**
     * After a successful OAuth refresh-token save, fetch the live email
     * address via users.getProfile and persist it to GMAIL_USER_EMAIL.
     * Fails soft: the OAuth itself is already saved, so a getProfile
     * failure just leaves the operator a warning to fix manually.
     *
     * @param array<string, mixed> $previousSettings Snapshot of settings before this OAuth call
     */
    private function syncGmailUserEmail(array $previousSettings): void
    {
        try {
            $reloaded = new GmailService(GmailService::loadConfigFromDatabase());
            $email = $reloaded->getUserEmail();

            $existingEmail = (string) ($previousSettings[SettingKeys::GMAIL_USER_EMAIL] ?? '');
            if (!$this->settingsService->saveSetting(SettingKeys::GMAIL_USER_EMAIL, $email)) {
                Log::warning('Failed to persist GMAIL_USER_EMAIL after OAuth', [
                    'email' => $this->maskEmail($email),
                ]);

                return;
            }

            if ($existingEmail !== '' && $existingEmail !== $email) {
                Log::info('Gmail user email changed via OAuth', [
                    'old' => $this->maskEmail($existingEmail),
                    'new' => $this->maskEmail($email),
                ]);
            } else {
                Log::info('Gmail user email persisted', ['email' => $this->maskEmail($email)]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to auto-populate gmail_user_email after OAuth', [
                'error' => $e->getMessage(),
            ]);
            $this->Flash->warning(
                'Gmail autorizado, pero no se pudo leer el email de la cuenta. '
                . 'Revisa el setting GMAIL_USER_EMAIL.',
            );
        }
    }

    /**
     * Return a partially masked email address suitable for logging.
     * Example: "soporte@example.com" -> "s***@example.com".
     */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
```

- [ ] **Step 4: Confirm `GmailService` is already imported**

Check `src/Controller/Admin/SettingsController.php` imports for `use App\Service\GmailService;`. If absent, add it. (It is referenced in `gmailAuth` already at line 128, so it should be there.)

Run: `grep -n "use App\\\\Service\\\\GmailService" src/Controller/Admin/SettingsController.php`
Expected output: one matching line. If empty, add the `use` statement near the other `App\Service` imports.

- [ ] **Step 5: Run code style check**

Run: `composer cs-check`
Expected: no new errors. Run `composer cs-fix` if needed.

- [ ] **Step 6: Run static analysis**

Run: `vendor/bin/phpstan analyse src/Service/GmailService.php src/Controller/Admin/SettingsController.php`
Expected: no new errors over baseline.

- [ ] **Step 7: Run the full unit test suite**

Run: `composer test`
Expected: same baseline pre-existing failures, plus the 3 new `GetUserEmail` tests passing. Total new tests across P1: ~30.

- [ ] **Step 8: Commit B-4**

Run:

```bash
git add src/Service/GmailService.php \
        src/Controller/Admin/SettingsController.php \
        tests/TestCase/Service/GmailServiceTest.php

git commit -m "$(cat <<'EOF'
feat(gmail): auto-populate gmail_user_email on OAuth callback (B-4)

Closes B-4 from docs/audits/2026-05-16-gmail-api-audit.md.

- New GmailService::getUserEmail() calls users.getProfile('me') and
  returns the live email. Inherits H-2 retry and M-3 categorization
  because it goes through the wrapped GoogleClient. Throws
  GmailApiException(PERMANENT) if the response carries an empty
  emailAddress; wraps Google\Service\Exception into a categorized
  GmailApiException otherwise.
- SettingsController::gmailAuth now invokes a new private
  syncGmailUserEmail helper after a successful OAuth refresh-token
  save (whether the new token was returned or only the existing one
  was reused). The helper reloads GmailService against the freshly
  persisted token, calls getUserEmail, and saves it to
  GMAIL_USER_EMAIL via SettingsService.
- Silent overwrite of any existing GMAIL_USER_EMAIL, with a diff log
  emitting both addresses masked via a local maskEmail helper.
- Fail-soft: any error in the sync surfaces as a flash warning; the
  OAuth itself is already persisted.

Spec: docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md §7
Plan: docs/superpowers/plans/2026-05-16-gmail-audit-p1.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Post-P1 Verification

- [ ] **Run the full suite one more time**

Run: `composer test`
Expected: 7 pre-existing baseline failures unchanged. ~30 new tests added across the three commits, all passing.

- [ ] **Run cs-check across the whole project**

Run: `composer cs-check`
Expected: no new errors over baseline.

- [ ] **Run phpstan across the whole project**

Run: `vendor/bin/phpstan analyse src`
Expected: 38 pre-existing baseline errors in `UserHelper.php` unchanged. No new errors.

- [ ] **Manual smoke (deferred to deploy environment, not part of this plan run)**

Document in the commit body or PR description that the operator must:

1. After deploy, run `bin/cake import_gmail --max 1` to confirm the integration still ingests under the new retry stack.
2. Visit `/admin/settings/gmailAuth` and re-authorize. Confirm the `system_settings` row for `gmail_user_email` is updated and that `Log::info('Gmail user email persisted', ...)` appears in the application log with the masked email.

---

## Notes for the implementer

- **Test ordering:** Tasks 1.1 and 1.2 are TDD-paired by necessity — `GmailErrorCategory::categorize()` references `GmailApiException`, so the test for category cannot pass until the exception class exists. Both are written first, then both implementations land. This is the only place the strict "red → green → commit" cadence is two-step instead of one.
- **No new Composer dependencies.** `guzzlehttp/guzzle` is already vendored as a transitive of `google/apiclient`. Do not add anything to `composer.json`.
- **Do not touch `initializeClient`'s line-178 catch** in Task 1.4. That catch is for the OAuth token refresh, which has its own existing exception type (`GmailAuthenticationException`).
- **Do not touch `getSystemEmail`'s line-563 catch** either — it reads from `SystemSettings`, not from Gmail.
- **`composer test` baseline:** the project has 7 pre-existing failing tests unrelated to Gmail (template rendering, Windows path sanitization, circuit breaker shape). Treat them as the floor; any new failure caused by P1 work is a regression.
- **`phpstan` baseline:** 38 pre-existing errors all in `src/View/Helper/UserHelper.php`. New errors anywhere else are regressions.
- **Worktree:** if running this plan inside a `superpowers:using-git-worktrees` isolated worktree, that environment should already exist before Task 1.1 begins.
