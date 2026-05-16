# Gmail API Audit · P0 Findings Resolution

- **Date:** 2026-05-16
- **Audit source:** `docs/audits/2026-05-16-gmail-api-audit.md` §8 (P0 priority block)
- **Scope:** Three findings — H-1 (OAuth scopes), H-3 (notification anti-spoof), M-1 (PSR-6 token cache)
- **Out of scope:** H-2, M-2..M-5, B-1..B-4, I-1..I-3 (backlogged to P1/P2)
- **Delivery:** Three sequential commits directly on `main`

---

## 1. Background

The Gmail integration audit (`docs/audits/2026-05-16-gmail-api-audit.md`) identified three findings prioritized as P0 ("this iteration"):

- **H-1** — Excessive OAuth scopes (`gmail.readonly` + `gmail.send` + `gmail.modify`) when `gmail.modify` alone suffices.
- **H-3** — `isSystemNotification()` relies on a trivially spoofable `X-Mesa-Ayuda-Notification: true` header; any external sender can add it and trigger silent ticket suppression (DoS for legitimate customers).
- **M-1** — `fetchAccessTokenWithRefreshToken` runs on every `new GmailService(...)`, causing 2-4 unnecessary OAuth token round-trips per HTTP request.

During the design phase, one additional finding was made: the current `isSystemNotification()` includes a check for subject prefix `Re: [Ticket #` which **never matches in production** — templates produce subjects like `Tu ticket #1284 fue creado`, not `[Ticket #1284]`. This dead-code check is removed as part of H-3 work.

---

## 2. Goals

1. Reduce OAuth attack surface to least-privilege (H-1).
2. Eliminate the trivial DoS vector against ticket creation (H-3).
3. Cut OAuth token endpoint latency from every Gmail-touching request (M-1).
4. Maintain backward compatibility for in-flight notification threads during a 30-day grace window (H-3).
5. Zero regression on Gmail ingest (`POST /webhooks/gmail/import`) and outbound notification delivery.

## 3. Non-goals

- Migrating to `users.history.list` checkpointing (M-2).
- Adding retry/backoff middleware (H-2).
- Typing `Google\Service\Exception` and enriching `GmailImportResult` (M-3).
- Persisting RFC 5322 `Message-ID` / `In-Reply-To` / `References` (M-4).
- Any schema migration.

---

## 4. Architecture overview

```
                          ┌────────────────────────────┐
                          │  EmailService::sendEmail   │
                          │  (outbound notifications)  │
                          └────────────┬───────────────┘
                                       │
                          (1) extract #N from subject
                                       │
                          (2) NotificationStamp::append
                                       │
                                       ▼
                          ┌────────────────────────────┐
                          │ Subject: "Tu ticket #1284  │
                          │  fue creado [#1284·s=ab12  │
                          │  cd34]"                    │
                          └────────────┬───────────────┘
                                       │ → Gmail API send
                                       │
                                       ▼ (customer replies → Gmail inbox)
                          ┌────────────────────────────┐
                          │ GmailService::             │
                          │   isSystemNotification     │
                          └────────────┬───────────────┘
                                       │
              ┌────────────────────────┼─────────────────────────┐
              │                        │                         │
       (a) HMAC stamp           (b) Legacy header           (c) From == system
       valid? → skip ticket      + DKIM=pass own              email → skip ticket
                                  domain → skip ticket
```

All three checks are inclusive — any one matching causes the message to be marked-read and skipped. H-3 introduces (a), tightens (b) with DKIM gating, and removes a fourth dead-code check.

The PSR-6 cache (M-1) is orthogonal: it wraps the `GoogleClient` so the access_token persists across `GmailService` instances within a request and across consecutive requests within ~1 hour.

---

## 5. Detailed design

### 5.1 Finding H-1 — Reduce OAuth scopes

**File:** `src/Service/GmailService.php` (`initializeClient`, lines 132-134).

```php
// BEFORE
$this->client->addScope(Gmail::GMAIL_READONLY);
$this->client->addScope(Gmail::GMAIL_SEND);
$this->client->addScope(Gmail::GMAIL_MODIFY);

// AFTER
$this->client->addScope(Gmail::GMAIL_MODIFY);
```

`gmail.modify` subsumes `gmail.readonly` and `gmail.send` for every API call the codebase makes (`users.messages.list/get/modify/attachments.get`, `users.messages.send`). Reducing scopes minimizes blast radius if the refresh_token is compromised and reduces friction during Google's OAuth verification.

**Operational steps post-deploy:**
1. GCP Console → OAuth consent screen → Edit App → remove `gmail.readonly` and `gmail.send` from declared scopes.
2. Visit `/admin/settings/gmailAuth` and re-authorize. The existing `prompt=consent` forces Google to display a fresh consent screen with the reduced scope set.
3. The pre-existing `refresh_token` continues to function with the narrower scope (Google issues access_tokens scoped to the current client config, not to what the refresh_token was originally minted for).
4. Verify with `bin/cake import_gmail --max 1`.

**Test:**

```php
// tests/TestCase/Service/GmailServiceTest.php
public function testOnlyGmailModifyScopeIsRequested(): void
{
    $service = new GmailService($this->fakeConfig());
    $client = $this->getPrivateProperty($service, 'client');

    $this->assertSame(
        ['https://www.googleapis.com/auth/gmail.modify'],
        $client->getScopes(),
    );
}
```

---

### 5.2 Finding H-3 — HMAC stamp + DKIM-gated legacy header

#### 5.2.1 Subject format reality

Notification templates produce subjects like:

- `Tu ticket #1284 fue creado`
- `El estado de tu ticket #1284 cambió a Resuelto`
- `Alexander te respondió en el ticket #1284`
- `Alexander actualizó tu ticket #1284`

The audit's §12.3 assumed `[Ticket #1234]` brackets which **never exist in this codebase**. The current `isSystemNotification()` check for `Re: [Ticket #` is therefore dead code and is removed.

#### 5.2.2 Stamp format

Append `[#<ticketNumber>·s=<8-hex>]` to outgoing subjects. The 8 hex chars are `substr(hash_hmac('sha256', 'ticket:'.$N, $salt), 0, 8)` = 32 bits HMAC-SHA256 truncated. Sufficient for anti-spoof at the cost of one preimage chance per ~4 billion — not a crypto signature, an anti-spam token.

Example: `Tu ticket #1284 fue creado [#1284·s=a1b2c3d4]`

The stamp embeds the ticket number so the receiver extracts both the candidate ticket and the candidate HMAC with one regex, then recomputes and compares with `hash_equals`.

#### 5.2.3 New file `src/Service/Util/NotificationStamp.php`

```php
<?php
declare(strict_types=1);

namespace App\Service\Util;

use Cake\Core\Configure;

final class NotificationStamp
{
    private const STAMP_RE = '/\[#(\d+)·s=([0-9a-f]{8})\]/u';
    private const STAMP_LENGTH = 8;

    public static function append(string $subject, string $ticketNumber): string
    {
        return rtrim($subject) . ' [#' . $ticketNumber . '·s=' . self::compute($ticketNumber) . ']';
    }

    /** Returns the ticket number when stamp is present AND HMAC valid; null otherwise. */
    public static function verifiedTicketNumber(string $subject): ?string
    {
        if (!preg_match(self::STAMP_RE, $subject, $m)) {
            return null;
        }
        return hash_equals(self::compute($m[1]), $m[2]) ? $m[1] : null;
    }

    private static function compute(string $ticketNumber): string
    {
        $salt = (string)Configure::read('Security.salt');
        return substr(hash_hmac('sha256', 'ticket:' . $ticketNumber, $salt), 0, self::STAMP_LENGTH);
    }
}
```

#### 5.2.4 Outbound stamping in `EmailService::sendEmail`

`EmailService::sendEmail($to, $subject, $body, $attachments)` does not receive a ticket entity. The subject already contains `#<number>`, so we extract from it:

```php
// src/Service/EmailService.php — before $options assembly
if (preg_match('/#(\d+)/', $subject, $m)) {
    $subject = NotificationStamp::append($subject, $m[1]);
}
```

Zero coupling to `NotificationMessage` shape. If a future caller passes a subject without `#N`, the stamp is simply skipped — no breakage.

#### 5.2.5 Inbound validation in `GmailService::isSystemNotification`

```php
public function isSystemNotification(array $headers): bool
{
    $subject = $this->getHeader($headers, 'Subject');

    // Check 1 (canonical): HMAC stamp embedded by our own EmailService.
    if (NotificationStamp::verifiedTicketNumber($subject) !== null) {
        return true;
    }

    // Check 2 (legacy, grace window): X-Mesa-Ayuda-Notification trusted ONLY when
    // Gmail's Authentication-Results header reports dkim=pass for our own domain.
    $legacy = $this->getHeader($headers, 'X-Mesa-Ayuda-Notification');
    if ($legacy === 'true' && $this->dkimPassesForOwnDomain($headers)) {
        return true;
    }

    // Check 3 (unchanged): From == system email (DMARC-spoof-mitigated but not perfect).
    $fromEmail = EmailHeaderParser::extractEmailAddress($this->getHeader($headers, 'From'));
    $systemEmail = $this->getSystemEmail();
    if ($systemEmail !== '' && strtolower($fromEmail) === strtolower($systemEmail)) {
        return true;
    }

    // Removed: subject-prefix "Re: [Ticket #" check — never matched in production
    // (templates produce "Tu ticket #N", not "[Ticket #N]").
    return false;
}

private function dkimPassesForOwnDomain(array $headers): bool
{
    $authResults = $this->getHeader($headers, 'Authentication-Results');
    $systemEmail = $this->getSystemEmail();
    $atPos = strrpos($systemEmail, '@');
    if ($authResults === '' || $atPos === false) {
        return false;
    }
    $ownDomain = substr($systemEmail, $atPos + 1);
    return (bool)preg_match(
        '/dkim=pass[^;]*?header\.d=' . preg_quote($ownDomain, '/') . '\b/i',
        $authResults,
    );
}
```

#### 5.2.6 Grace window timeline

- **Day 0** — Deploy. Every new outbound notification carries a stamp. In-flight threads (notifications sent before day 0) still loop-detect via the DKIM-gated legacy branch.
- **Day +30** — Remove the legacy branch entirely. Only HMAC + From==system remain. Note in `docs/operations/` for operational tracking.

#### 5.2.7 Tests

```php
// tests/TestCase/Service/Util/NotificationStampTest.php
public function testAppendAndVerifyRoundtrip(): void;          // valid stamp returns ticket number
public function testVerifyRejectsTamperedHmac(): void;         // mutated HMAC → null
public function testVerifyReturnsNullWhenNoStamp(): void;      // subject without stamp → null

// tests/TestCase/Service/GmailServiceTest.php
public function testIsSystemNotificationAcceptsStampedSubject(): void;
public function testIsSystemNotificationAcceptsLegacyHeaderWithDkimPassOwnDomain(): void;
public function testIsSystemNotificationRejectsLegacyHeaderWithDkimPassAttackerDomain(): void;
public function testIsSystemNotificationRejectsLegacyHeaderWithoutAuthResults(): void;
```

---

### 5.3 Finding M-1 — PSR-6 access_token cache

#### 5.3.1 Dependency

```bash
composer require cache/filesystem-adapter:^1.2
```

Brings `league/flysystem` transitively (~80 KB). This is the path the official `google/apiclient` README recommends for a file-based PSR-6 pool.

#### 5.3.2 Changes in `GmailService::initializeClient`

```php
use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

private function initializeClient(): void
{
    $this->client = new GoogleClient();

    if (!empty($this->config['client_secret']) && is_array($this->config['client_secret'])) {
        $this->client->setAuthConfig($this->config['client_secret']);
    } else {
        Log::error('Gmail client_secret not configured in system_settings');
    }

    $this->client->addScope(Gmail::GMAIL_MODIFY); // H-1 already applied
    $this->client->setAccessType('offline');
    $this->client->setPrompt('consent');

    // M-1: PSR-6 cache for access_token reuse across instances and requests.
    $cacheDir = TMP . 'gmail_oauth_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $pool = new FilesystemCachePool(new Filesystem(new LocalFilesystemAdapter($cacheDir)));
        $this->client->setCache($pool);
        $this->client->setCacheConfig(['lifetime' => 3500]);
        $this->client->setTokenCallback(static function (string $cacheKey, string $accessToken): void {
            Log::debug('Gmail access token refreshed by SDK', ['cache_key' => $cacheKey]);
        });
    } else {
        Log::warning('Gmail OAuth cache dir not writable; falling back to per-request token refresh', [
            'cache_dir' => $cacheDir,
        ]);
    }

    if (!empty($this->config['redirect_uri'])) {
        $this->client->setRedirectUri($this->config['redirect_uri']);
    }

    if (!empty($this->config['refresh_token'])) {
        try {
            $token = $this->client->fetchAccessTokenWithRefreshToken($this->config['refresh_token']);
            if (isset($token['error'])) {
                Log::error('OAuth token refresh failed', ['error' => $token]);
                throw new GmailAuthenticationException('Gmail authentication failed: ' . ($token['error_description'] ?? $token['error']));
            }
        } catch (Exception $e) {
            Log::error('Failed to refresh OAuth token: ' . $e->getMessage());
            throw new GmailAuthenticationException('Gmail authentication failed. Please re-authenticate in Admin Settings.');
        }
    }
}
```

The `is_writable` check makes the cache opt-in: if the directory creation fails (unusual permissions in some environment), behavior degrades gracefully to today's per-request token fetch.

#### 5.3.3 Cache invalidation on credential rotation

When the admin saves a new `gmail_client_secret_json` or `gmail_refresh_token`, the cached access_token from the previous credentials must be purged or the next request will use a stale token tied to the old auth.

```php
// src/Service/SettingsService.php — inside the existing settings-cache clearing path
private function clearGmailOAuthCache(): void
{
    $cacheDir = TMP . 'gmail_oauth_cache';
    if (!is_dir($cacheDir)) {
        return;
    }
    foreach (glob($cacheDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
}
```

Invoke `clearGmailOAuthCache()` from the existing settings-save path **only when** the saved key matches `gmail_client_secret_json` or `gmail_refresh_token`.

#### 5.3.4 Tests

```php
// tests/TestCase/Service/GmailServiceTest.php
public function testPsr6CacheIsConfiguredOnInitialize(): void
{
    $service = new GmailService($this->fakeConfig());
    $client = $this->getPrivateProperty($service, 'client');
    $this->assertInstanceOf(\Psr\Cache\CacheItemPoolInterface::class, $client->getCache());
}
```

Integration-level testing (assert that 3 `new GmailService(...)` calls produce only 1 token endpoint request) requires mocking the Guzzle handler inside `GoogleClient`. Deferred to a follow-up; the unit assertion above is sufficient evidence the wiring is correct.

---

## 6. Delivery plan

### 6.1 Commit sequence on `main`

```
1. perf(gmail): cache OAuth access_token via PSR-6 to avoid per-request token refresh (M-1)
2. fix(gmail): replace spoofable notification header with HMAC stamp (H-3)
3. fix(gmail): reduce OAuth scopes to gmail.modify only (H-1)
```

**Rationale for order:**

- **M-1 first** — Zero operational impact. Validates that the deploy machinery handles the new dependency and that the cache directory is writable as the runtime PHP-FPM user. If anything breaks, it breaks cleanly and revertibly without coordination.
- **H-3 second** — The DKIM grace branch should be observed under the current (3-scope) OAuth config before we also change scopes. Isolates variables when debugging.
- **H-1 last** — The only commit requiring manual operator action (GCP consent screen edit + admin re-authorization). Best done once the other two changes are stable.

### 6.2 Per-commit verification

For every commit, the following four checks must pass before claiming complete:

| Check | Command |
|-------|---------|
| Style | `composer cs-check` |
| Unit tests | `composer test` |
| Static analysis | `vendor/bin/phpstan analyse src` |
| Smoke ingest | `bin/cake import_gmail --max 1` |

### 6.3 Rollback strategy

| Commit | Rollback procedure |
|--------|-------------------|
| M-1 | `git revert <sha>` + `composer remove cache/filesystem-adapter`. No state mutation; reverts to per-request token refresh. |
| H-3 | `git revert <sha>`. Outbound subjects revert to unstamped form; the legacy header survives revert (it was never removed). Inbound check falls back to the pre-H-3 (broken) behavior. |
| H-1 | `git revert <sha>` + manually restore the two scopes in GCP consent screen. The current refresh_token continues working with any subset of its originally granted scopes. |

### 6.4 Post-deploy operator checklist (H-1 only)

- [ ] GCP Console → OAuth consent screen → remove `gmail.readonly` and `gmail.send` from declared sensitive scopes.
- [ ] Open `/admin/settings/gmailAuth` and complete the re-consent flow.
- [ ] Run `bin/cake import_gmail --max 1` and confirm zero errors.
- [ ] Send one test notification (assign a test ticket) and confirm the customer's eventual reply does NOT create a duplicate ticket (i.e. HMAC stamp + isSystemNotification working end-to-end).

### 6.5 Grace window cleanup (calendarized)

- **Target:** 2026-06-15 (day +30)
- **Action:** Remove the legacy `X-Mesa-Ayuda-Notification` branch from `isSystemNotification` and the corresponding header from `EmailService::sendEmail`. Add a follow-up entry in `docs/operations/` so this isn't forgotten.

---

## 7. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| `TMP/gmail_oauth_cache` not writable in some runtime | Low | Low | Graceful degradation: defensive `is_writable` check, falls back to today's behavior with a warning log. |
| Subject with `#N` referring to non-ticket text gets falsely stamped | Very low | Low | Stamp adds a suffix, doesn't replace anything; even if a spurious stamp is appended, it's harmless on the outbound side. Inbound only acts when `hash_equals` succeeds, which a random match cannot. |
| HMAC truncated to 32 bits enables guessing | Low | Low | 1-in-4-billion preimage chance per attempt; ticket numbers are predictable but the salt is private. Rate-limited by `is:unread` polling cap. Not a crypto signature — anti-spoof. |
| Cached access_token tied to old credentials after rotation | Medium | Medium | Explicit cache purge in `SettingsService` when saving `gmail_client_secret_json` or `gmail_refresh_token`. |
| `composer require` introduces transitive vulnerability | Low | Low | `cache/filesystem-adapter` and `league/flysystem` are widely used, actively maintained. CI's existing dependency audit (if any) catches advisories. |
| Re-consent in `/admin/settings/gmailAuth` fails | Low | High | Existing flow already supports re-authorization (commit history shows it's been used). Have GCP service-account credentials handy as backup. Rollback of H-1 commit restores prior consent state. |

---

## 8. Files touched

| File | Findings | Action |
|------|----------|--------|
| `src/Service/GmailService.php` | H-1, H-3, M-1 | Edit (scopes, isSystemNotification, dkimPassesForOwnDomain, PSR-6 wiring) |
| `src/Service/EmailService.php` | H-3 | Edit (stamp injection in sendEmail) |
| `src/Service/SettingsService.php` | M-1 | Edit (cache invalidation on Gmail credential save) |
| `src/Service/Util/NotificationStamp.php` | H-3 | New file |
| `tests/TestCase/Service/GmailServiceTest.php` | H-1, H-3, M-1 | New / extended |
| `tests/TestCase/Service/Util/NotificationStampTest.php` | H-3 | New file |
| `composer.json` / `composer.lock` | M-1 | Add `cache/filesystem-adapter:^1.2` |

---

## 9. Out of scope (for tracking)

- H-2 retry/backoff middleware → P1
- M-2 history.list checkpointing → P2
- M-3 typed `Google\Service\Exception` classification → P1
- M-4 RFC 5322 `Message-ID` persistence → P2
- M-5 markAsRead retry queue → P2
- B-1..B-4 → backlog
- I-1..I-3 → informational, no action this iteration

---

## 10. References

- `docs/audits/2026-05-16-gmail-api-audit.md` — original audit
- `CLAUDE.md` — notification architecture (Section "Notifications and integrations")
- `src/Service/GmailService.php` — current implementation
- `src/Service/EmailService.php` — current outbound path
