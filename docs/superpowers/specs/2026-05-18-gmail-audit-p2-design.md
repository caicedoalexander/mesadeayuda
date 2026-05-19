# Gmail API Audit · P2 Findings Resolution

- **Date:** 2026-05-18
- **Audit source:** `docs/audits/2026-05-16-gmail-api-audit.md` §8 (P2 priority block)
- **Predecessor specs:**
  - `docs/superpowers/specs/2026-05-16-gmail-audit-p0-design.md`
  - `docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md`
- **Scope:** Three findings — M-4 (persist RFC 5322 threading headers), M-5 (retry queue for `markAsRead`), M-2 (migrate polling to `users.history.list` with checkpoint).
- **Out of scope:** P3 items (B-1 token bucket, B-2 multipart/alternative selection, B-3 broader bulk detection, `watch()` + Pub/Sub), I-1/I-2/I-3, and the legacy `X-Mesa-Ayuda-Notification` retirement (separate operational item scheduled around 2026-06-15).
- **Delivery:** Three sequential commits directly on `main`, same cadence as P0/P1.

---

## 1. Background

After P0 (commit `b8e3d2a`/`5b21651`/`8ae81f0`) and P1 (commit `78b9487`/`7894d98`/`0204c18`), the inbound Gmail pipeline is secure, resilient against transient failures, and categorizes errors. Three structural weaknesses still remain in `GmailImportService::run()`:

- **M-2** — Polling uses `is:unread` with a hard cap of 200 messages per run. Every minute the system scans up to 200 IDs even when nothing changed (`5 + 20 × 200 ≈ 4 005` quota units per empty run). After an outage, new messages exceeding the cap silently stall until they bubble to the top of `is:unread`. Messages marked read manually in the Gmail UI before the run disappear from the query and never become tickets.
- **M-4** — Threading relies solely on Gmail's internal `gmail_thread_id`. When a customer replies from a non-Gmail client (Outlook, Apple Mail, mobile native clients), or an agent answers outside the Gmail UI, Gmail issues a different `thread_id` and the system creates a **duplicate ticket** on the same logical conversation. The canonical RFC 5322 identifiers (`Message-ID`, `In-Reply-To`, `References`) are not persisted and therefore cannot be used for cross-client thread reattachment.
- **M-5** — `markAsRead` is fire-and-forget. If it fails (transient network blip, 5xx after retry budget exhausted), the message stays `UNREAD` forever. Dedup by `gmail_message_id` correctly prevents reprocessing, but the message permanently consumes a slot in the 200-message cap. Over time the inbox fills with "zombie" messages that have been ingested but never marked.

---

## 2. Goals

1. Replace `is:unread` polling with delta queries against `users.history.list`, persisting a `historyId` checkpoint so each run costs O(delta) quota instead of O(200) (M-2).
2. Bootstrap and graceful-fallback paths so the system survives an empty checkpoint, a 404-expired `historyId`, and the first deploy with the same code path (M-2).
3. Persist `Message-ID`, `In-Reply-To`, and `References` on both `tickets` and `ticket_comments`, and use them for thread reattachment before falling back to `gmail_thread_id` (M-4).
4. Introduce a small, idempotent retry queue for `markAsRead` failures so the unread state in Gmail converges to reality across runs (M-5).
5. Zero new vendor dependencies. Three schema migrations, all additive.
6. Preserve every behavior added in P0 and P1 — retry middleware, typed exceptions, OAuth cache, HMAC stamp, auto-populated `gmail_user_email`.

## 3. Non-goals

- **Push notifications (`watch()` + Pub/Sub).** Audit §3 P3 #10. Polling cadence at 60s is sufficient for the current helpdesk volume and avoids Pub/Sub infrastructure cost.
- **Shared token bucket for attachment downloads (B-1).** The `usleep` is acceptable post-M-2 because total attachments per run drop proportionally with the delta.
- **`multipart/alternative` selection (B-2).** Orthogonal to threading and polling; defer.
- **Broader bulk detection (B-3).** Same.
- **PII masking across logs (I-3).** Audit-wide initiative, not P2 scope. New log calls added here follow the existing convention (full subject, `from` address logged).
- **Outbox for `sendEmail` durability.** Explicitly rejected during P1 brainstorming; P2 does not revisit this.
- **Removal of the legacy `X-Mesa-Ayuda-Notification` branch.** Scheduled separately around 2026-06-15 per the P0 commit body.

---

## 4. Commit order and dependencies

Three sequential commits on `main`, in this order:

| # | Commit subject | Finding | Why this order |
|---|---|---|---|
| 1 | `feat(gmail): persist RFC 5322 threading headers (M-4)` | M-4 | Lands first because it is the most isolated change — schema-additive, parser extension, ingestion lookup. Does not interact with the orchestration loop. |
| 2 | `feat(gmail): retry queue for markAsRead failures (M-5)` | M-5 | Lands second because M-2 must call `processPending()` at the start of each run. M-5 introduces that hook point. |
| 3 | `feat(gmail): history.list checkpoint polling (M-2)` | M-2 | Lands last. Restructures `GmailImportService::run()` and consumes the M-5 hook. Pulling RFC headers from `parseMessage` (M-4) and the markAsRead queue (M-5) are both already wired by the time the orchestrator changes. |

---

## 5. M-4 · Persist RFC 5322 threading headers

### 5.1 New migration `AddRfcThreadingToTickets`

File: `config/Migrations/20260518120000_AddRfcThreadingToTickets.php`.

Adds three nullable columns to `tickets` and `ticket_comments` and one non-unique index per table on `rfc_message_id` for fast lookup. Non-unique because the same `Message-ID` can legitimately appear if a message is re-ingested through a different path (e.g., import-after-outage), and the application-level dedup by `gmail_message_id` already enforces uniqueness at the Gmail-account scope.

```php
final class AddRfcThreadingToTickets extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tickets')
            ->addColumn('rfc_message_id', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('in_reply_to', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('references_header', 'text', ['null' => true, 'default' => null])
            ->addIndex(['rfc_message_id'], ['name' => 'idx_tickets_rfc_message_id'])
            ->update();

        $this->table('ticket_comments')
            ->addColumn('rfc_message_id', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('in_reply_to', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('references_header', 'text', ['null' => true, 'default' => null])
            ->addIndex(['rfc_message_id'], ['name' => 'idx_ticket_comments_rfc_message_id'])
            ->update();
    }
}
```

Note: the column is named `references_header` (not `references`) because `REFERENCES` is a reserved word in MySQL/MariaDB and quoting it everywhere is brittle. The mapping to the RFC 5322 `References:` header is documented in the entity and the parser.

### 5.2 Extension of `GmailService::parseMessage`

`parseMessage(string $messageId): array` currently returns an array including `gmail_message_id`, `gmail_thread_id`, `from`, `subject`, `body_html`, `body_text`, `email_to`, `email_cc`, `is_auto_reply`, `is_system_notification`, `attachments`. Three new keys land in the same return shape:

- `rfc_message_id` (string|null) — raw value of the `Message-ID:` header, stripped of surrounding angle brackets and whitespace via a new helper `EmailHeaderParser::extractMessageId(string $raw): ?string`.
- `in_reply_to` (string|null) — raw value of `In-Reply-To:`, similarly normalized.
- `references_header` (string|null) — the full `References:` header value, retained as-is (whitespace-separated list of message IDs).

Existing header extraction already runs through `EmailHeaderParser` and `getHeader(array, string)`. The change is mechanical — add three reads and one normalization helper.

### 5.3 Extension of `Ticket::fromEmailIngest`

Factory signature gains three optional parameters at the end, all defaulting to `null`:

```php
public static function fromEmailIngest(
    string $ticketNumber,
    int $requesterId,
    string $subject,
    string $sanitizedDescription,
    string $channel,
    string $sourceEmail,
    ?string $gmailMessageId = null,
    ?string $gmailThreadId = null,
    ?array $emailTo = null,
    ?array $emailCc = null,
    ?string $rfcMessageId = null,
    ?string $inReplyTo = null,
    ?string $referencesHeader = null,
): self
```

Defaults preserve every existing call site (none currently pass the new params).

### 5.4 Changes in `TicketIngestionService`

Two changes:

1. **`createFromEmail`**: persist the three new columns when building the `Ticket` entity via `Ticket::fromEmailIngest`. No behavioral change here yet — the new columns get populated but nothing reads them for new tickets.
2. **`createCommentFromEmail`**: persist the three new columns on the `TicketComment` entity (alongside the existing `gmail_message_id`).

### 5.5 New helper `TicketIngestionService::findExistingTicketByThreading`

Encapsulates the thread reattachment decision. Returns a `Ticket|null`:

```php
private function findExistingTicketByThreading(array $emailData): ?Ticket
{
    // 1. RFC 5322 lookup (strongest signal, cross-client).
    $inReplyTo = $emailData['in_reply_to'] ?? null;
    if ($inReplyTo !== null) {
        $ticket = $this->lookupTicketByRfc($inReplyTo);
        if ($ticket !== null && $this->withinReattachWindow($ticket)) {
            return $ticket;
        }
    }

    // 2. References header — try each candidate from newest to oldest.
    $references = $emailData['references_header'] ?? null;
    if ($references !== null) {
        foreach (array_reverse($this->parseReferences($references)) as $candidate) {
            $ticket = $this->lookupTicketByRfc($candidate);
            if ($ticket !== null && $this->withinReattachWindow($ticket)) {
                return $ticket;
            }
        }
    }

    // 3. Fallback to existing gmail_thread_id behavior.
    $threadId = $emailData['gmail_thread_id'] ?? null;
    if ($threadId !== null) {
        return $this->fetchTable('Tickets')->find()
            ->where(['gmail_thread_id' => $threadId])
            ->first();
    }

    return null;
}
```

`lookupTicketByRfc(string $rfcId): ?Ticket` performs a two-step search:

1. Find a row in `ticket_comments` where `rfc_message_id = $rfcId`. If found, return the parent `Ticket` (resolved by `ticket_id`).
2. Otherwise find a row in `tickets` where `rfc_message_id = $rfcId` and return it directly.

Both queries use the indexes added in 5.1. The two-step order matters: most reattachments target comments (the most recent thread participant), so checking comments first is the common-case fast path.

`withinReattachWindow(Ticket $ticket): bool` returns `true` when the ticket's last activity is inside a configurable window (default 90 days, see 5.6). When `false`, the function falls through to the next candidate and ultimately to ticket creation — preventing resurrection of ancient closed tickets. A ticket whose status is `CLOSED` and whose last modification exceeds the window is treated as "out of window" regardless of the timestamp on the incoming message.

### 5.6 New configuration constant

`TicketConstants::THREAD_REATTACH_WINDOW_DAYS = 90`. Lives in `TicketConstants` rather than `SettingKeys` because no operator-facing override is needed in this iteration; promote to a settings key later if real usage shows the need.

### 5.7 Orchestrator wiring in `GmailImportService::run()`

Replace the inline `if (!empty($emailData['gmail_thread_id']))` lookup with a single call to `$this->tickets->findExistingTicketByThreading($emailData)`. The orchestration loop body shrinks; the threading policy lives entirely in `TicketIngestionService`.

This change is included in commit 1 (M-4), not deferred. Commit 1 leaves `run()` ready for the M-5 hook in commit 2.

### 5.8 Tests for M-4

New cases in `TicketIngestionServiceTest`:

- `testCreateFromEmailPersistsRfcHeaders` — ticket gets `rfc_message_id`, `in_reply_to`, `references_header` populated.
- `testCreateCommentFromEmailPersistsRfcHeaders` — comment likewise.
- `testFindExistingTicketByThreadingMatchesInReplyToOnComment` — In-Reply-To points to a comment's `rfc_message_id`; parent ticket is returned.
- `testFindExistingTicketByThreadingMatchesInReplyToOnTicket` — In-Reply-To points to the original ticket's `rfc_message_id`.
- `testFindExistingTicketByThreadingWalksReferencesNewestToOldest` — multiple `References:` entries; the newest match wins.
- `testFindExistingTicketByThreadingFallsBackToThreadId` — no RFC match, `gmail_thread_id` still wins.
- `testFindExistingTicketByThreadingRespectsReattachWindow` — RFC match exists but ticket is older than 90 days; returns null so a new ticket is created.
- `testFindExistingTicketByThreadingSkipsClosedAncientTicket` — closed ticket beyond the window is bypassed; reattachment fails and the caller creates a new ticket.

New case in `GmailServiceTest`:

- `testParseMessageExtractsRfcThreadingHeaders` — feeds a fixture Gmail Message with `Message-ID`, `In-Reply-To`, `References` headers and asserts the three keys appear in the return.

These tests are unit tests against `TicketIngestionService` with mocked Table objects, consistent with the existing testing convention (`tests/bootstrap.php` does not wire fixtures).

---

## 6. M-5 · Retry queue for `markAsRead`

### 6.1 New migration `CreateGmailMarkReadPending`

File: `config/Migrations/20260518120100_CreateGmailMarkReadPending.php`.

```php
final class CreateGmailMarkReadPending extends AbstractMigration
{
    public function change(): void
    {
        $this->table('gmail_mark_read_pending')
            ->addColumn('gmail_message_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('attempts', 'integer', ['signed' => false, 'limit' => 3, 'default' => 0, 'null' => false])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('last_category', 'string', ['limit' => 32, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['gmail_message_id'], ['unique' => true, 'name' => 'uniq_mark_read_message_id'])
            ->create();
    }
}
```

`attempts` is `TINYINT UNSIGNED` (sufficient for the cap of 3) and `last_category` stores a `GmailErrorCategory` constant for observability.

### 6.2 New table class `App\Model\Table\GmailMarkReadPendingTable`

Minimal — extends `Table`, sets the table name, adds `Timestamp` behavior. No association with `Tickets` (this is a transient operational queue, not domain data).

### 6.3 New service `App\Service\Gmail\MarkReadQueueService`

Single responsibility: enqueue failures, drain pending on each run.

```php
final class MarkReadQueueService
{
    use LocatorAwareTrait;

    public const MAX_ATTEMPTS = 3;
    public const DEFAULT_BATCH = 20;

    public function enqueue(string $gmailMessageId, ?string $error, string $category): void
    {
        // INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1.
        // Implemented via Table->saveOrUpdate semantics or a raw upsert.
    }

    /**
     * @return array{processed:int, retried:int, failed:int, dropped:int}
     */
    public function processPending(GmailService $gmail, int $batch = self::DEFAULT_BATCH): array
    {
        // 1. SELECT up to $batch rows ORDER BY created ASC.
        // 2. For each row: call $gmail->markAsRead().
        //    - Success ⇒ delete row, processed++.
        //    - GmailApiException with category=PERMANENT (404 — message gone)
        //      ⇒ delete row, dropped++.
        //    - Any other exception ⇒ increment attempts.
        //         If attempts >= MAX_ATTEMPTS after increment ⇒ delete row,
        //         log warning 'Gmail mark-read dropped after max attempts',
        //         dropped++.
        //         Otherwise retried++.
        // 3. Return counters.
    }
}
```

Error handling delegates to the existing P1 machinery: `markAsRead` already throws `GmailApiException` with the proper category. `MarkReadQueueService` does not catch generic `Throwable`; it lets unexpected exceptions propagate (the outer `run()` loop is the safety net).

### 6.4 Changes in `GmailService::markAsRead`

No behavioral change. `markAsRead` continues to return `bool` and throw `GmailApiException` on hard failures. The decision to enqueue lives in the orchestrator, not in `GmailService`, to keep `GmailService` stateless.

### 6.5 Changes in `GmailImportService`

Two integration points:

1. **At the start of `run()`** — before fetching new mail, drain pending:

   ```php
   $markReadCounters = $this->markReadQueue->processPending($this->gmail);
   ```

2. **At every existing `$this->gmail->markAsRead($messageId)` call site** — wrap to enqueue on failure:

   ```php
   try {
       $this->gmail->markAsRead($messageId);
   } catch (GmailApiException $e) {
       $this->markReadQueue->enqueue($messageId, $e->getMessage(), $e->getCategory());
   }
   ```

   This wraps the four current call sites in `run()`: the auto-reply skip branch, the system-notification skip branch, the post-create branch (new ticket), and the post-comment branch (reattached thread).

### 6.6 Constructor injection

`GmailImportService::__construct` gains a `MarkReadQueueService $markReadQueue` parameter. `GmailImportService::fromSettings()` instantiates it (no config needed). The existing public constructor remains the canonical entry point for tests.

### 6.7 Extension of `GmailImportResult`

Add three readonly properties and three keys in `toArray()`:

- `markReadRetried: int` — count of pending entries successfully processed in this run.
- `markReadDropped: int` — count of pending entries dropped (either as PERMANENT on 404 or after exceeding `MAX_ATTEMPTS`).
- `markReadEnqueued: int` — count of new failures enqueued during this run.

Constructor parameters default to `0` to keep existing call sites compiling.

### 6.8 Tests for M-5

New `MarkReadQueueServiceTest` (~7 cases, all unit-level with a mocked `GmailService`):

- `testEnqueueInsertsNewRow`.
- `testEnqueueIncrementsAttemptsOnDuplicate`.
- `testProcessPendingMarksAndDeletesOnSuccess`.
- `testProcessPendingDropsOnPermanentCategory` — 404 / PERMANENT category drops immediately.
- `testProcessPendingIncrementsAttemptsOnTransientFailure`.
- `testProcessPendingDropsAfterMaxAttempts`.
- `testProcessPendingHonorsBatchSize`.

New cases in `GmailImportServiceTest`:

- `testRunDrainsPendingBeforeFetch` — order of operations verified via mock expectations.
- `testRunEnqueuesMarkReadFailure` — when `markAsRead` throws `GmailApiException`, the queue receives an `enqueue` call.

---

## 7. M-2 · `history.list` checkpoint polling

### 7.1 New setting key `GMAIL_LAST_HISTORY_ID`

`SettingKeys::GMAIL_LAST_HISTORY_ID = 'gmail_last_history_id'`. Persisted in `system_settings` as a string (Gmail's `historyId` is an unsigned 64-bit integer expressed as a decimal string in the API; storing it as string sidesteps PHP integer-precision concerns). Not added to `USER_EDITABLE_KEYS` — it is operational, not user-facing.

### 7.2 New method `GmailService::getProfileHistoryId`

```php
public function getProfileHistoryId(): string
{
    $profile = $this->getService()->users->getProfile('me');
    $historyId = (string)($profile->getHistoryId() ?? '');
    if ($historyId === '') {
        throw new GmailApiException(
            'getProfile returned empty historyId',
            GmailErrorCategory::PERMANENT,
        );
    }
    return $historyId;
}
```

Note: `getUserEmail()` introduced in B-4 already calls `users.getProfile('me')`; both methods could share a private `fetchProfile()` helper. The spec lands them as separate calls for now to keep B-4 untouched. A small refactor (`fetchProfile()` returning a `Profile` object, both `getUserEmail()` and `getProfileHistoryId()` consuming it) is a candidate for the same commit.

### 7.3 New method `GmailService::getHistoryDelta`

```php
/**
 * Returns an ordered list of messageIds that were added since $startHistoryId,
 * or null if Gmail responds 404 (checkpoint too old — caller should full-sync).
 *
 * @return list<string>|null
 */
public function getHistoryDelta(string $startHistoryId): ?array
{
    try {
        $pageToken = null;
        $messageIds = [];
        do {
            $response = $this->getService()->users_history->listUsersHistory('me', [
                'startHistoryId' => $startHistoryId,
                'historyTypes' => ['messageAdded'],
                'pageToken' => $pageToken,
            ]);
            foreach ($response->getHistory() ?? [] as $history) {
                foreach ($history->getMessagesAdded() ?? [] as $added) {
                    $messageIds[] = $added->getMessage()->getId();
                }
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');

        return array_values(array_unique($messageIds));
    } catch (\Google\Service\Exception $e) {
        if ($e->getCode() === 404) {
            return null;
        }
        throw GmailApiException::wrap($e);
    }
}
```

Pagination is required because the Gmail SDK caps page size; production deltas are usually one page but the loop is cheap insurance.

Retries are already applied by the Guzzle middleware from H-2 (P1).

### 7.4 New helper `GmailService::getMaxHistoryIdFromMessages`

`parseMessage` already retrieves each Gmail `Message` object; the `Message::getHistoryId()` accessor exposes the per-message historyId. The new helper accepts a list of `Message` objects (or, equivalently, returns the historyId out of `parseMessage` as part of its array) and returns the maximum.

Implementation choice: extend `parseMessage`'s return array with a `gmail_history_id` key (string). The orchestrator collects them and computes the max. No new public API surface beyond the new key.

### 7.5 New mode enum on `GmailImportResult`

Add a readonly `string $historyMode` property accepting one of four constants from a new `App\Service\Gmail\HistoryMode` final class:

- `HistoryMode::BOOTSTRAP` — no checkpoint existed; bootstrapped from `getProfileHistoryId()` and ran a one-time `messages.list newer_than:7d` full sync.
- `HistoryMode::DELTA` — checkpoint present, `history.list` returned a delta.
- `HistoryMode::FULL_SYNC_FALLBACK` — checkpoint present but `history.list` returned 404; full-sync executed and checkpoint refreshed.
- `HistoryMode::MANUAL_OVERRIDE` — caller (CLI) passed an explicit `$queryOverride`; checkpoint left untouched. Distinguishing this from `DELTA` in the result matters because admins reading the JSON output need to know whether the run advanced the checkpoint or merely scanned a manual query.

`toArray()` exposes `history_mode` and a new `history_fallbacks` counter (always 0 except in FULL_SYNC_FALLBACK, where it is 1).

### 7.6 Restructured `GmailImportService::run()`

The contract changes: `$query` is no longer the primary control; `run()` decides the query based on checkpoint state. The `$query` parameter is retained for backward compatibility (CLI `bin/cake import_gmail --query='...'`) but documented as override for manual operations.

Pseudocode:

```php
public function run(int $max = 50, ?string $queryOverride = null, int $delayMs = 0): GmailImportResult
{
    $startedAt = microtime(true);

    // 0. Drain pending markAsRead queue (M-5).
    $markReadCounters = $this->markReadQueue->processPending($this->gmail);

    // 1. Read checkpoint.
    $settings = $this->fetchTable('SystemSettings');
    $lastHistoryId = $this->readHistoryCheckpoint($settings);

    // 2. Decide mode and fetch messageIds.
    $historyMode = null;
    $messageIds = [];

    if ($queryOverride !== null) {
        // Manual override (CLI). Behaves like before; do not touch checkpoint.
        $messageIds = $this->gmail->getMessages($queryOverride, max(1, min($max, 200)));
        $historyMode = HistoryMode::MANUAL_OVERRIDE;
        $touchCheckpoint = false;
    } elseif ($lastHistoryId === null) {
        $historyMode = HistoryMode::BOOTSTRAP;
        $bootstrapHistoryId = $this->gmail->getProfileHistoryId();
        $messageIds = $this->gmail->getMessages('in:inbox newer_than:7d', max(1, min($max, 200)));
        $this->writeHistoryCheckpoint($settings, $bootstrapHistoryId);
        $touchCheckpoint = false; // Bootstrap already wrote; per-message max is irrelevant for the first run.
    } else {
        $delta = $this->gmail->getHistoryDelta($lastHistoryId);
        if ($delta === null) {
            $historyMode = HistoryMode::FULL_SYNC_FALLBACK;
            Log::warning('Gmail history.list returned 404, falling back to full sync', [
                'checkpoint' => $lastHistoryId,
            ]);
            $freshHistoryId = $this->gmail->getProfileHistoryId();
            $messageIds = $this->gmail->getMessages('in:inbox newer_than:7d', max(1, min($max, 200)));
            $this->writeHistoryCheckpoint($settings, $freshHistoryId);
            $touchCheckpoint = false;
        } else {
            $historyMode = HistoryMode::DELTA;
            $messageIds = array_slice($delta, 0, max(1, min($max, 200)));
            $touchCheckpoint = true; // Advance to max gmail_history_id seen during the loop.
        }
    }

    // 3. Existing per-message loop (extended with markRead enqueue from M-5).
    //    parseMessage now also returns gmail_history_id.
    //    Per-message error categorization (M-3) preserved verbatim.

    // 4. If $touchCheckpoint, persist max gmail_history_id observed.
    //    Persist even when zero messages were created — the delta was successfully consumed.

    // 5. Build GmailImportResult with all counters (P0 + P1 + M-5 + M-2 fields).
}
```

`readHistoryCheckpoint`/`writeHistoryCheckpoint` are tiny private helpers that wrap the `SystemSettings` Table reads/writes and the cache invalidation already handled by `SettingsService::saveSetting` (so checkpoint writes do **not** invalidate the OAuth cache — see 7.7).

### 7.7 Cache invalidation interaction

`SettingsService::saveSetting` currently purges the OAuth cache directory when `GMAIL_CLIENT_SECRET_JSON` or `GMAIL_REFRESH_TOKEN` is written (P0 / M-1). `GMAIL_LAST_HISTORY_ID` writes must **not** trigger that purge. The condition in `SettingsService::saveSetting` already keys on the specific setting name, so a write to the new key skips the OAuth-cache branch by construction. The spec asserts this behavior with a dedicated test (`SettingsServiceTest::testWritingGmailLastHistoryIdDoesNotPurgeOAuthCache`).

### 7.8 Quota and timing impact

| Scenario | Pre-P2 cost | Post-P2 cost |
|---|---|---|
| Empty minute (no new mail) | `list (5) ≈ 5` units (cap miss, but still the round-trip) | `history.list (2) = 2` units. ~60% reduction. |
| 5 new messages | `list (5) + 5 × get (20) + 5 × modify (5) = 130` | `history.list (2) + 5 × get (20) + 5 × modify (5) = 127`. Marginal. |
| After-outage 500 new messages | `list (5)` returns top 200; remaining 300 stalled until next run drains the unread cap. Realistic catch-up: 3+ runs. | `history.list (2)` returns all 500 IDs; `$max` cap (200) still applies, but the unread cap no longer compounds. Catch-up: same number of runs, but each run advances the checkpoint correctly. |
| Manual mark-read via UI before run | Message disappears from `is:unread`, ticket never created. | `history.list` reports `messageAdded` regardless of read state. Captured. |

### 7.9 Tests for M-2

New cases in `GmailServiceTest`:

- `testGetProfileHistoryIdReturnsString`.
- `testGetProfileHistoryIdThrowsOnEmptyValue`.
- `testGetHistoryDeltaReturnsMessageIds` — paginated response with two pages.
- `testGetHistoryDeltaReturnsNullOn404`.
- `testGetHistoryDeltaWrapsOtherErrorsAsGmailApiException`.
- `testParseMessageIncludesGmailHistoryId`.

New cases in `GmailImportServiceTest`:

- `testRunBootstrapWritesCheckpointFromProfile` — no checkpoint ⇒ profile.historyId persisted, full sync ran.
- `testRunDeltaUsesHistoryListAndAdvancesCheckpoint` — checkpoint present ⇒ history.list called, max historyId across processed messages becomes new checkpoint.
- `testRunDeltaEmptyResponseLeavesCheckpointUnchanged`.
- `testRunFullSyncFallbackOn404RewritesCheckpoint`.
- `testRunQueryOverrideDoesNotTouchCheckpoint` — CLI usage stays orthogonal.
- `testRunModeIsReportedInResult` — `historyMode` is one of the three constants in every code path.

New case in `SettingsServiceTest`:

- `testWritingGmailLastHistoryIdDoesNotPurgeOAuthCache`.

---

## 8. Verification plan

Same gates as P0/P1:

1. `composer cs-check` over touched files; expect no new warnings versus the existing baseline.
2. `vendor/bin/phpstan analyse src`; expect the 38 baseline errors (all in unrelated files: `UserHelper.php`, `AppController.php`, etc.) and no new errors in P2 files.
3. `composer test`; expect ~265 tests (230 baseline + ~35 new across M-4/M-5/M-2) with the 7 pre-existing baseline failures unchanged.
4. `bin/cake migrations migrate` against a scratch MariaDB — both new migrations apply cleanly and produce the expected schema.
5. `bin/cake import_gmail --max 1` smoke (optional, requires Gmail config) — bootstrap path runs, checkpoint persisted.

For acceptance after deploy, three smoke scenarios:

- **Cross-client thread reattachment (M-4):** open a ticket, reply from an external Outlook account, confirm a comment appears on the original ticket instead of a duplicate. Then close the ticket, wait 91+ days (or temporarily set `THREAD_REATTACH_WINDOW_DAYS = 0` in a staging tweak), reply again, confirm a new ticket is created.
- **markRead retry (M-5):** simulate a transient `markAsRead` failure (e.g., block the Gmail API endpoint via firewall during one run), confirm the message is enqueued in `gmail_mark_read_pending`; restore network, confirm the next run drains the queue and the message becomes read.
- **history.list checkpoint (M-2):** truncate `gmail_last_history_id` (or delete the row) and confirm `run()` reports `history_mode=bootstrap` once, then `delta` thereafter. Send a new test email between two runs; confirm exactly one ticket is created and `history_mode=delta`.

## 9. Post-deploy operational notes

1. The first production run after deploy will be `historyMode=bootstrap` — expect a one-time full sync over `newer_than:7d`. Monitor that the checkpoint gets persisted (`SELECT value FROM system_settings WHERE \`key\` = 'gmail_last_history_id'`).
2. Monitor `gmail_mark_read_pending` table size for the first 48 hours. Sustained growth (rows older than a couple of hours) signals a deeper Gmail API issue worth alerting on.
3. Watch logs for `Gmail history.list returned 404, falling back to full sync`. Occasional occurrences after multi-day downtime are expected; a daily occurrence is not (Gmail keeps the `historyId` ≥ 1 week per docs).
4. The `history_mode` field in the webhook response JSON is a useful signal to expose on the admin dashboard later; not in scope for P2 but trivially available.

## 10. Files touched

**New:**

- `config/Migrations/20260518120000_AddRfcThreadingToTickets.php`
- `config/Migrations/20260518120100_CreateGmailMarkReadPending.php`
- `src/Model/Table/GmailMarkReadPendingTable.php`
- `src/Service/Gmail/MarkReadQueueService.php`
- `src/Service/Gmail/HistoryMode.php`
- `tests/TestCase/Service/Gmail/MarkReadQueueServiceTest.php`

**Modified:**

- `src/Constants/SettingKeys.php` — add `GMAIL_LAST_HISTORY_ID`.
- `src/Constants/TicketConstants.php` — add `THREAD_REATTACH_WINDOW_DAYS`.
- `src/Service/Util/EmailHeaderParser.php` — add `extractMessageId()`.
- `src/Service/GmailService.php` — extend `parseMessage()` with three RFC keys + `gmail_history_id`; add `getProfileHistoryId()`, `getHistoryDelta()`.
- `src/Model/Entity/Ticket.php` — extend `fromEmailIngest()` signature.
- `src/Service/TicketIngestionService.php` — persist new columns; introduce `findExistingTicketByThreading()` and helpers.
- `src/Service/GmailImportService.php` — restructured `run()`; constructor takes `MarkReadQueueService`; integrates M-5 enqueue/drain and M-2 checkpoint logic.
- `src/Service/Dto/GmailImportResult.php` — add `markReadRetried`, `markReadDropped`, `markReadEnqueued`, `historyMode`, `historyFallbacks`.
- `tests/TestCase/Service/GmailServiceTest.php` — new cases per 5.8, 7.9.
- `tests/TestCase/Service/GmailImportServiceTest.php` — new cases per 6.8, 7.9.
- `tests/TestCase/Service/TicketIngestionServiceTest.php` — new cases per 5.8.
- `tests/TestCase/Service/SettingsServiceTest.php` — checkpoint-write cache test.

## 11. Risks and rollback

| Risk | Likelihood | Mitigation |
|---|---|---|
| `history.list` 404 churn (checkpoint expires faster than docs say) | Low | Fallback path always recovers; counter exposed in `GmailImportResult`. |
| RFC reattachment captures false positives (legitimately separate threads share an `In-Reply-To` due to mailing-list rewrite) | Low | 90-day window + `gmail_thread_id` already differs in those cases — reattachment requires *both* RFC match and recent activity. |
| `gmail_mark_read_pending` grows unbounded if Gmail returns persistent 5xx for one message | Very low | `MAX_ATTEMPTS = 3` drops the row; PERMANENT category drops immediately on 404. |
| `parseMessage`'s new keys break a downstream consumer that array-merges or strictly types the return | Very low | All consumers are inside `App\Service\*` and accept extra keys silently. New keys are nullable. |
| Migration race during deploy (rows insert before columns exist) | Low | Standard CakePHP migration ordering (`bin/cake migrations migrate` before serving traffic); covered by the standard deploy runbook. |
| Quota for `users.getProfile` (bootstrap and fallback path) | Negligible | `getProfile` costs 1 unit; called at most twice per run (B-4 already calls it). |

**Rollback:** each commit is reversible.

- Reverting commit 3 (M-2) restores the `is:unread` polling. The checkpoint setting becomes orphaned data — harmless.
- Reverting commit 2 (M-5) leaves the migrated table orphaned; behavioral effect is back to fire-and-forget `markAsRead`. The `gmail_mark_read_pending` table can be dropped in a follow-up cleanup migration if M-5 is permanently abandoned.
- Reverting commit 1 (M-4) leaves the new columns populated on tickets created post-deploy; benign — they read as `NULL` for callers that don't expect them. The threading lookup reverts to `gmail_thread_id`-only.
