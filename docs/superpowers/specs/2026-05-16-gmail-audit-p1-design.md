# Gmail API Audit · P1 Findings Resolution

- **Date:** 2026-05-16
- **Audit source:** `docs/audits/2026-05-16-gmail-api-audit.md` §8 (P1 priority block)
- **Predecessor spec:** `docs/superpowers/specs/2026-05-16-gmail-audit-p0-design.md`
- **Scope:** Three findings — H-2 (retry/backoff for 429/5xx), M-3 (typed exceptions + categorized counters), B-4 (auto-populate `gmail_user_email`)
- **Out of scope:** H-2 outbox durability (explicitly rejected during brainstorming — in-memory retry only), M-2 (history.list), M-4 (RFC Message-ID), M-5 (markAsRead retry queue), B-1/B-2/B-3, I-1/I-2/I-3
- **Delivery:** Three sequential commits directly on `main`, same cadence as P0

---

## 1. Background

The Gmail integration audit identified four findings prioritized as P1 ("next two iterations"): H-2, M-3, B-4, plus M-5. The user elected to bundle the first three into a single P1 iteration (this spec) and defer M-5 to a later cycle.

Current behavior gaps these findings close:

- **H-2** — Every Gmail API call (`getMessages`, `parseMessage`, `downloadAttachment`, `markAsRead`, `sendEmail`) is wrapped in a generic `try/catch (Exception)` that logs and returns a neutral value or re-throws. There is no retry, no respect for `Retry-After`, and no exponential backoff. Under transient 429/5xx pressure, `sendEmail` silently fails and the customer never receives the notification; `getMessages` returns `[]` and the orchestrator cannot distinguish "no new mail" from "Gmail temporarily unavailable".
- **M-3** — Every catch swallows the categorical distinction between auth errors (401/403, alert-worthy), rate limits (429, retryable), transient 5xx (retryable), and permanent 4xx (caller bug). Operators have only a log line to diagnose.
- **B-4** — If an operator rotates the Gmail account in `/admin/settings/gmailAuth` but forgets to update the `GMAIL_USER_EMAIL` setting manually, every `From == systemEmail` check in `isSystemNotification` (the legacy DKIM-gated branch from P0) misfires and the anti-self-loop fallback is broken.

---

## 2. Goals

1. Survive transient 429 / 5xx / network errors without losing outbound customer notifications under reasonable pressure (H-2).
2. Make every Gmail API error categorized and counted, so operators can distinguish a token problem from a quota problem from a bug without reading raw logs (M-3).
3. Eliminate the manual step in OAuth re-authorization so `GMAIL_USER_EMAIL` always matches the live account (B-4).
4. Zero new dependencies (`guzzle/guzzle` is already vendored as a transitive of `google/apiclient`).
5. Zero schema migrations.

## 3. Non-goals

- Persistent outbox for `sendEmail` durability (rejected during brainstorming; `sendEmail` remains best-effort after retries are exhausted).
- Migrating polling to `users.history.list` (M-2 — future iteration).
- Persisting RFC 5322 `Message-ID` / `In-Reply-To` / `References` (M-4).
- A retry queue for `markAsRead` failures (M-5).
- Replacing the synchronous `usleep` attachment throttle with a shared token bucket (B-1).
- Selecting the richest part of `multipart/alternative` instead of concatenating (B-2).
- PII masking across all log levels (I-3) — B-4 introduces a tiny local `maskEmail` helper for its own logs, but does not propagate it system-wide.

---

## 4. Commit order and dependencies

Three sequential commits on `main`, in this order:

| # | Commit subject | Finding | Why this order |
|---|---|---|---|
| 1 | `feat(gmail): typed exception categories and counted errors (M-3)` | M-3 | Lands first because H-2 needs to distinguish `429`/`5xx` (retryable) from `4xx` (not) to behave correctly. M-3 introduces the typing of `Google\Service\Exception` and the category enum; H-2 consumes it. |
| 2 | `feat(gmail): exponential backoff retry for 429/5xx (H-2)` | H-2 | Pushes the `RetryHandler` middleware into the `GoogleClient`'s Guzzle stack. Depends on M-3 so the final post-retry exception is correctly categorized. |
| 3 | `feat(gmail): auto-populate gmail_user_email on OAuth callback (B-4)` | B-4 | Small, independent, last so it cannot mix with the client-level changes. Relies on M-3's `GmailApiException` for the new `getUserEmail()` method. |

---

## 5. M-3 · Typed exceptions and categorized counters

### 5.1 New class `App\Service\Gmail\GmailErrorCategory`

Plain final class with string constants and a single static `categorize(\Throwable $e): string` method. Not a PHP `enum` because callers also need to read the value cleanly from arrays/JSON without unwrapping cases.

```php
final class GmailErrorCategory
{
    public const AUTH      = 'auth';       // 401, 403 — token revoked, scope insufficient, account suspended
    public const RATE      = 'rate';       // 429 — quota exhausted (retryable)
    public const TRANSIENT = 'transient';  // 500, 502, 503, 504 (retryable)
    public const PERMANENT = 'permanent';  // other 4xx — caller bug (do not retry)
    public const UNKNOWN   = 'unknown';    // non-Google exception, no code, etc.

    public static function categorize(\Throwable $e): string
    {
        if ($e instanceof \Google\Service\Exception) {
            return self::fromHttpCode($e->getCode());
        }
        if ($e instanceof GmailApiException) {
            return $e->getCategory();
        }
        return self::UNKNOWN;
    }

    public static function fromHttpCode(int $code): string
    {
        return match (true) {
            $code === 401 || $code === 403           => self::AUTH,
            $code === 429                            => self::RATE,
            in_array($code, [500, 502, 503, 504], true) => self::TRANSIENT,
            $code >= 400 && $code < 500              => self::PERMANENT,
            default                                  => self::UNKNOWN,
        };
    }
}
```

### 5.2 New `App\Service\Exception\GmailApiException`

```php
final class GmailApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $category,
        int $code,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getCategory(): string
    {
        return $this->category;
    }
}
```

Lives alongside `GmailAuthenticationException` and `GmailNotConfiguredException` in `src/Service/Exception/`.

### 5.3 Changes in `GmailService`

Every catch block in API-calling methods (`getMessages`, `parseMessage`, `downloadAttachment`, `markAsRead`, `sendEmail`) is desugared:

```php
} catch (\Google\Service\Exception $e) {
    $category = GmailErrorCategory::categorize($e);
    Log::error('Gmail API error', [
        'method'   => __FUNCTION__,
        'category' => $category,
        'code'     => $e->getCode(),
        'message'  => $e->getMessage(),
    ]);
    throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
} catch (\Throwable $e) {
    Log::error('Gmail API error', [
        'method'   => __FUNCTION__,
        'category' => GmailErrorCategory::UNKNOWN,
        'message'  => $e->getMessage(),
        'class'    => $e::class,
    ]);
    throw new GmailApiException(GmailErrorCategory::UNKNOWN, 0, $e->getMessage(), previous: $e);
}
```

Methods that currently return a neutral sentinel on failure (`getMessages` → `[]`, `markAsRead` → `false`, `sendEmail` → `false`) keep that public contract: an inner `try/catch (GmailApiException)` re-catches the wrap and returns the sentinel. The categorized log is already emitted by the inner throw. This preserves caller behavior for `GmailImportService::run()` and `EmailService::send()`.

Methods that already re-throw on failure (`parseMessage`) propagate `GmailApiException` upward instead of raw `Exception`. The orchestrator's outer `catch (\Throwable)` continues to work because `GmailApiException extends RuntimeException implements Throwable`.

### 5.4 Extension of `GmailImportResult`

Five new readonly fields, all `int`:

```php
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
) {}
```

All five default to `0` for backward compatibility with existing constructor calls. `toArray()` adds five keys (`auth_errors`, `rate_errors`, `transient_errors`, `permanent_errors`, `unknown_errors`).

**Invariant:** `errors == authErrors + rateErrors + transientErrors + permanentErrors + unknownErrors`. Enforced by a constructor assertion (using `assert()`, no-op in production) and validated by `GmailImportResultTest::testCounterInvariant`.

### 5.5 Changes in `GmailImportService::run()`

The per-message `catch (Throwable $e)` block becomes:

```php
} catch (\Throwable $e) {
    $category = GmailErrorCategory::categorize($e);
    Log::error('Gmail import per-message error', [
        'message_id' => $messageId,
        'category'   => $category,
        'error'      => $e->getMessage(),
        'class'      => $e::class,
    ]);
    $errors++;
    $errorMessages[] = "{$messageId}: {$e->getMessage()}";
    $categoryCounters[$category]++;
}
```

`$categoryCounters` is a local `['auth' => 0, 'rate' => 0, 'transient' => 0, 'permanent' => 0, 'unknown' => 0]` map initialized before the loop. Passed into the `GmailImportResult` constructor at the end.

### 5.6 Tests

- `tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php` — 7 cases covering each branch of `fromHttpCode` (401, 403, 429, 500, 400, 418, unknown 200) plus 2 cases for `categorize` (Google exception with code, plain Throwable).
- `tests/TestCase/Service/Exception/GmailApiExceptionTest.php` — 2 cases: construction populates `category` / `code` / `message` and `getCategory()` returns the value; previous exception is preserved through `getPrevious()`.
- `tests/TestCase/Service/Dto/GmailImportResultTest.php` — invariant assertion plus `toArray()` key presence.
- `tests/TestCase/Service/GmailServiceTest.php` — two new cases: mock `Google\Service\Gmail` to throw `Google\Service\Exception` with code 401 and 429, assert the wrap throws `GmailApiException` with category `auth` and `rate` respectively.

---

## 6. H-2 · Retry with exponential backoff

### 6.1 New class `App\Service\Gmail\RetryHandler`

Stateless factory exposing two static callables for `GuzzleHttp\Middleware::retry`:

```php
final class RetryHandler
{
    public const MAX_RETRIES    = 5;
    public const BASE_DELAY_MS  = 250;
    public const MAX_BACKOFF_MS = 32_000;
    public const JITTER_MS      = 1_000;

    private const RETRIABLE_STATUS = [429, 500, 502, 503, 504];

    public static function decider(): callable
    {
        return static function (
            int $retries,
            \Psr\Http\Message\RequestInterface $request,
            ?\Psr\Http\Message\ResponseInterface $response = null,
            ?\Throwable $exception = null,
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }
            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
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
        return static function (int $retries, ?\Psr\Http\Message\ResponseInterface $response = null): int {
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
                'attempt'  => $retries + 1,
                'status'   => $response?->getStatusCode(),
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
        $deltaMs = max(0, ($timestamp - time()) * 1000);
        return $deltaMs;
    }
}
```

### 6.2 Integration with `GoogleClient`

In `GmailService::initializeClient()`, after OAuth/cache setup but before any API call:

```php
$stack = \GuzzleHttp\HandlerStack::create();
$stack->push(\GuzzleHttp\Middleware::retry(
    RetryHandler::decider(),
    RetryHandler::delay(),
));
$http = new \GuzzleHttp\Client([
    'handler'         => $stack,
    'timeout'         => 30,
    'connect_timeout' => 10,
]);
$this->client->setHttpClient($http);
```

This wraps every Gmail API call. The Google SDK applies its own auth middleware on top of the provided HTTP client, so OAuth headers are not bypassed.

### 6.3 Observability

- Per-attempt warning log from `RetryHandler::delay()`.
- Final post-exhaustion error log from M-3's typed catch (no duplicate log because the retry middleware does not log the terminal failure — it just lets it surface).

### 6.4 `sendEmail` trade-off (documented limit)

`sendEmail` performs up to 6 attempts (the initial call plus 5 retries — `MAX_RETRIES` counts retries, not attempts, per Guzzle convention). If all attempts fail, `GmailApiException` propagates to `TicketNotificationListener`, which catches `Throwable` and logs (current behavior). **The notification is lost.** This is a deliberate scope decision — durable outbox is explicitly out of scope. Acceptable because: (a) 5 retries with full jitter typically clear transient pressure; (b) the audit's outbox recommendation can land later without touching the retry layer.

### 6.5 Timeout budget vs webhook 300s

Worst-case per-call: 6 attempts × 30s request timeout + sum of inter-attempt delays. Backoff sum (no `Retry-After`): `250 + 500 + 1000 + 2000 + 4000` ≈ 7.75s base, plus up to `5 × 1000` ms jitter = ~12.75s max. Total: ~180s + ~13s = ~193s. The webhook calls `set_time_limit(300)`. The orchestrator processes messages serially; if `getMessages` itself burns ~193s and returns `[]`, the run is a near-no-op and the next webhook tick (60s cadence) retries. If a single Gmail message processing hits this ceiling, the loop continues with the remaining time budget; if the budget exhausts, PHP terminates and the next tick resumes (idempotent by `gmail_message_id`).

### 6.6 Tests

- `tests/TestCase/Service/Gmail/RetryHandlerTest.php`:
  - Decider — 6 cases: 200 no-retry, 429 retry, 500 retry, 401 no-retry, 404 no-retry, ConnectException retry. Plus retry-cap case (`retries >= MAX_RETRIES` returns false).
  - Delay — 3 cases: `n=0/2/4` produces a result within `[base, base + JITTER_MS]` for the expected base (250/1000/4000 ms); `n=10` returns exactly `MAX_BACKOFF_MS` (32000 ms — capped twice: base clamps first, then `base + jitter` re-clamps); `Retry-After: 5` (header in seconds) returns 5000 ms.
- `tests/TestCase/Service/GmailServiceTest.php::testRetryMiddlewareIsRegistered` — inspects the HandlerStack via `(string) $stack` for the retry middleware string signature.
- `tests/TestCase/Service/GmailServiceTest.php::testRetryMiddlewareSucceedsAfterTransientErrors` — uses `GuzzleHttp\Handler\MockHandler` queueing `[Response(429), Response(429), Response(200, body)]` and asserts the call completes with the 200 body after 2 retries.

---

## 7. B-4 · Auto-populate `gmail_user_email`

### 7.1 New method `GmailService::getUserEmail()`

```php
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
    } catch (\Google\Service\Exception $e) {
        throw new GmailApiException(
            GmailErrorCategory::categorize($e),
            $e->getCode(),
            $e->getMessage(),
            previous: $e,
        );
    }
}
```

Inherits retry from H-2 and typing from M-3 for free, because the call goes through the already-wrapped `GoogleClient`.

### 7.2 Changes in `SettingsController::gmailAuth`

After a successful `saveSetting(GMAIL_REFRESH_TOKEN, ...)` (and also after the "re-authorized using existing refresh token" path), append:

```php
try {
    $reloaded = new GmailService(GmailService::loadConfigFromDatabase());
    $email = $reloaded->getUserEmail();

    $existingEmail = (string) ($allSettings[SettingKeys::GMAIL_USER_EMAIL] ?? '');
    if ($this->settingsService->saveSetting(SettingKeys::GMAIL_USER_EMAIL, $email)) {
        if ($existingEmail !== '' && $existingEmail !== $email) {
            Log::info('Gmail user email changed via OAuth', [
                'old' => $this->maskEmail($existingEmail),
                'new' => $this->maskEmail($email),
            ]);
        } else {
            Log::info('Gmail user email persisted', ['email' => $this->maskEmail($email)]);
        }
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
```

**Reload of `GmailService`** is required because the original instance in `gmailAuth` was built without a refresh_token (the goal of the request was to acquire one). Building a fresh instance via `loadConfigFromDatabase()` picks up the just-persisted token and uses the M-1 PSR-6 cache for the access_token.

**Fail-soft semantics:** if `getProfile` fails (quota, network, malformed response), the OAuth refresh_token is already persisted and the integration is functional. The operator sees a flash warning; the legacy DKIM-gated branch of `isSystemNotification` is the affected fallback.

### 7.3 Helper `SettingsController::maskEmail`

Private method, local to the controller:

```php
private function maskEmail(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }
    return substr($email, 0, 1) . '***' . substr($email, $at);
}
```

Used only inside the B-4 branch. No system-wide PII masking (I-3 is out of scope).

### 7.4 Silent override decision

When `GMAIL_USER_EMAIL` already exists and differs from the newly-fetched value, **overwrite silently with an info log of the diff**. Rationale: a completed OAuth consent flow is an explicit operator action; that account is the new source of truth. The diff log preserves traceability.

### 7.5 Tests

- `tests/TestCase/Service/GmailServiceTest.php::testGetUserEmailReturnsAddressFromProfile` — mock `users->getProfile('me')` returning a `Google\Service\Gmail\Profile` with `setEmailAddress('soporte@x.com')`.
- `tests/TestCase/Service/GmailServiceTest.php::testGetUserEmailThrowsOnEmptyAddress` — mock returns empty `emailAddress`; assert `GmailApiException` with category `permanent`.
- `tests/TestCase/Service/GmailServiceTest.php::testGetUserEmailWrapsGoogleException` — mock throws `Google\Service\Exception` with code 401; assert wrap with category `auth`.
- **Manual smoke** documented in §9: re-OAuth in `/admin/settings/gmailAuth` and confirm `GMAIL_USER_EMAIL` is persisted in `system_settings`.

---

## 8. Verification plan

Same shape as the P0 closure record:

- `composer cs-check` over the touched files — no new errors vs the existing baseline.
- `vendor/bin/phpstan analyse src` — no new errors vs the 38 pre-existing baseline errors (all in `UserHelper.php`).
- `composer test` — Unit suite. Acceptable: the 7 pre-existing baseline failures unchanged. **No new failures.**
- New tests expected:
  - M-3: `GmailErrorCategoryTest` (9) + `GmailApiExceptionTest` (2) + `GmailImportResultTest` invariant (2) + `GmailServiceTest` typed catch (2) = **15**
  - H-2: `RetryHandlerTest` (10) + `GmailServiceTest` middleware registered (1) + integration MockHandler (1) = **12**
  - B-4: `GmailServiceTest` getUserEmail (3) = **3**
  - **Total: ~30 new tests**
- `bin/cake import_gmail --max 1` — manual smoke post-deploy.
- Re-OAuth in `/admin/settings/gmailAuth` — confirm `GMAIL_USER_EMAIL` ends up persisted.

---

## 9. Post-deploy operational notes

1. After H-2 merges, monitor logs for 24h for the `Gmail API retry` warning. If the rate is consistently high, consider pulling M-2 (history.list) forward to reduce quota pressure.
2. After B-4 merges, perform a re-OAuth with a different Gmail account and confirm the `Gmail user email changed via OAuth` log emits both `old` and `new` masked addresses.
3. Carry-over from P0: the legacy `X-Mesa-Ayuda-Notification` branch in `isSystemNotification` remains scheduled for removal around **2026-06-15**. P1 does not touch it.

---

## 10. Files touched

**New:**

- `src/Service/Gmail/GmailErrorCategory.php`
- `src/Service/Gmail/RetryHandler.php`
- `src/Service/Exception/GmailApiException.php`
- `tests/TestCase/Service/Gmail/GmailErrorCategoryTest.php`
- `tests/TestCase/Service/Gmail/RetryHandlerTest.php`
- `tests/TestCase/Service/Exception/GmailApiExceptionTest.php`

**Modified:**

- `src/Service/GmailService.php` — typed catches (commit 1), `setHttpClient` with retry stack (commit 2), `getUserEmail` (commit 3)
- `src/Service/GmailImportService.php` — category counters in `run()` (commit 1)
- `src/Service/Dto/GmailImportResult.php` — five new readonly counter fields + `toArray()` keys (commit 1)
- `src/Controller/Admin/SettingsController.php` — `gmailAuth` calls `getUserEmail` + `maskEmail` helper (commit 3)
- `tests/TestCase/Service/GmailServiceTest.php` — typed catch tests (commit 1), middleware tests (commit 2), `getUserEmail` tests (commit 3)
- `tests/TestCase/Service/Dto/GmailImportResultTest.php` — invariant + `toArray` coverage (commit 1)

**Unchanged:**

- `composer.json` — no new dependencies (`guzzle/guzzle` already vendored via `google/apiclient`).
- `config/Migrations/*` — no schema changes.

---

## 11. Risks and rollback

| Risk | Likelihood | Mitigation |
|---|---|---|
| Retry middleware shadows the Google SDK's own auth middleware | Low | `setHttpClient` is the documented extension point; SDK auth runs on top. Verified by the MockHandler integration test (a 200 response after retries must still contain authenticated content). |
| Categorization mis-classifies a Google error code we did not anticipate | Low | `UNKNOWN` is the fallback; `unknownErrors` counter surfaces it in `GmailImportResult`. No retry consequence (unknown is not in the retriable set). |
| Reload of `GmailService` in `gmailAuth` fails because PSR-6 cache from M-1 still holds a stale token | Very low | M-1's `SettingsService::saveSetting` already purges `TMP/gmail_oauth_cache` when `GMAIL_REFRESH_TOKEN` is rotated. Verified at commit `b8e3d2a`. |
| `random_int` inside `delay()` is too slow under heavy retry pressure | Negligible | `random_int` is cryptographically secure but inexpensive; called at most 5 times per failing request. |

**Rollback strategy:** each of the three commits is independently revertible.
- Reverting commit 3 (B-4) leaves H-2 and M-3 in place.
- Reverting commit 2 (H-2) leaves M-3 in place; behavior degrades to pre-P1 plus categorized logs.
- Reverting commit 1 (M-3) requires reverting 2 and 3 first (they consume `GmailApiException`).
