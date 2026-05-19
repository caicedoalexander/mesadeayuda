# Gmail API Audit · P3 Findings Resolution

- **Date:** 2026-05-19
- **Audit source:** `docs/audits/2026-05-16-gmail-api-audit.md` §8 (P3 priority block + pending Low/Info findings)
- **Predecessor specs:**
  - `docs/superpowers/specs/2026-05-16-gmail-audit-p0-design.md`
  - `docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md`
  - `docs/superpowers/specs/2026-05-18-gmail-audit-p2-design.md`
- **Scope:** Four findings — B-1 (attachment throttle, closed as WONT_FIX), B-2 (`multipart/alternative` branch selection), B-3 (broader auto-reply detection), I-3 (PII masking in info logs).
- **Out of scope:** P3 item #10 (`watch()` + Pub/Sub — deferred to a separate evaluation when volume justifies it), I-1 (`google/apiclient` maintenance-mode tracking — pure documentation, no action), I-2 (GCP OAuth Published/Production — operational checklist outside this repo).
- **Delivery:** Three sequential commits on `main`, same cadence as P0/P1/P2. Plus an audit-document edit to close B-1.

---

## 1. Background

After P0 (commits `b8e3d2a`/`5b21651`/`8ae81f0`), P1 (`78b9487`/`7894d98`/`0204c18`), and P2 (`7575072`/`c231dac`/`e45a98b`), the inbound and outbound Gmail pipeline is secure, resilient against transient failures, categorized by error type, and uses a delta checkpoint instead of full polling. Four secondary findings remain:

- **B-1** — `usleep(200 ms)` synchronous throttle before every `downloadAttachment` call. The audit flagged it as *defensive and sensible* and only suggested replacing it with a shared token bucket if attachment volume justified it. Post-M-2 the per-run attachment count drops proportionally with the delta, removing the original concern.
- **B-2** — `GmailService::extractMessageParts` walks every node and concatenates every `text/html` body it finds. In nested-forward emails, the same content is written twice into `body_html`.
- **B-3** — `isAutoReply` checks `Auto-Submitted`, `X-Autoreply`, `X-Autorespond`, `Precedence`. It misses modern bulk indicators: `List-Unsubscribe` (RFC 2369/8058, ubiquitous since 2018), `Feedback-ID` (Gmail/Yahoo bulk-sender requirement, 2024+), and the `Auto-Submitted: auto-notified` variant (RFC 3834).
- **I-3** — Info-level logs include the raw `to` / `from` email address. Not a vulnerability, but a GDPR/LOPD hygiene issue worth fixing once.

---

## 2. Goals

1. Close B-1 with documented reasoning (WONT_FIX, risk accepted) in the audit document. No production code change.
2. Stop the duplicate-HTML side effect by picking exactly one branch of every `multipart/alternative` node, preferring the richer rendering (HTML, including HTML wrapped in `multipart/related`) and falling back to plain text when HTML is absent (B-2).
3. Detect modern auto-reply / bulk indicators (`List-Unsubscribe`, `Feedback-ID`, `Auto-Submitted: auto-notified` and any non-`no` value of `Auto-Submitted`) so newsletters and out-of-office responders are routed correctly (B-3).
4. Mask the local-part of every email address in info-level log entries via a centralized helper, leaving the subject in clear text so support workflows that grep logs by ticket number keep working (I-3).
5. Zero new vendor dependencies. Zero schema migrations. Zero changes to public service contracts.
6. Preserve every behavior added in P0/P1/P2.

## 3. Non-goals

- **`watch()` + Pub/Sub.** Audit §8 P3 #10. Polling cadence at 60 s plus delta queries (M-2) is sufficient for the current helpdesk volume and avoids Pub/Sub infrastructure cost.
- **Shared token bucket for attachment downloads (B-1).** Replaced by WONT_FIX status — see §4.
- **Reworking the existing `Auto-Submitted` parser into a strict RFC 3834 grammar.** A pragmatic *non-empty and not `no`* check covers `auto-notified`, `auto-replied`, `auto-generated`, and any future IANA-registered value without bringing in a grammar parser.
- **Masking subject lines in logs.** Subjects in this system carry the ticket number (`#<N>`) which is the operational identifier used during incident triage. Masking it would remove the only correlation key visible without database access.
- **Touching debug-level logs.** The audit explicitly names info-level entries. Debug is opt-in by the operator.
- **Removing the legacy `X-Mesa-Ayuda-Notification` branch.** Scheduled separately around 2026-06-15 per the P0 commit body.
- **Migrating off `google/apiclient` (I-1) or documenting the GCP OAuth Production requirement (I-2).** I-1 is a tracker note; I-2 belongs in operations runbooks, not in this codebase.

---

## 4. B-1 · Close as WONT_FIX

### 4.1 Rationale

- The 200 ms `usleep` is preventive throttling, not a circuit breaker. With the current helpdesk volume, the realistic worst case is ~5 attachments per run; the cumulative blocking time fits inside the `set_time_limit(300)` window with three orders of magnitude of headroom.
- After M-2 (delta polling) the per-run attachment count is bounded by the actual inbox delta, not by a fixed 200-message ceiling. The original sizing concern in §5 B-1 disappears.
- The `RetryHandler` introduced in H-2 already absorbs 429 / 5xx responses with exponential backoff plus jitter. A token bucket would duplicate that defense at a higher cost (shared cache lookup, race window between read and write of the bucket counter, cache miss = throttle bypass) without addressing a new failure mode.
- The audit itself describes the current throttle as *defensive and sensible*. Reopening it requires evidence of sustained > 5 attachments/s in production, which the metrics surfaced by P1 (`GmailImportResult::*Errors`) do not currently show.

### 4.2 Deliverable

A single edit to `docs/audits/2026-05-16-gmail-api-audit.md`:

- §1 (Resumen ejecutivo): bump the Bajo counter to `2 (B-1, B-4 cerrado)`.
- §5 B-1 block: prepend the standard `> **Cerrado 2026-05-19 — WONT_FIX (riesgo aceptado).**` banner with a one-paragraph rationale pointing at this spec.
- §8 P3 block: mark item 11 (B-1) as **Completed 2026-05-19 — WONT_FIX**.
- §12.x B-1 detail block (if present): keep the proposal text for historical reference, append a closing note.

No code change. No commit other than the audit edit. The edit lands together with the B-2/B-3/I-3 work or as its own follow-up commit, at the implementer's discretion.

---

## 5. B-2 · Pick one branch in `multipart/alternative`

### 5.1 Diagnosis

`GmailService::extractMessageParts()` (currently at `src/Service/GmailService.php` lines 391-448) recursively visits every node. The body extraction branch concatenates HTML found at any depth:

```php
$data['body_html'] = empty($data['body_html']) ? $htmlContent : $data['body_html'] . "\n" . $htmlContent;
```

This is correct for `multipart/mixed` (two distinct logical parts of one message), but incorrect for `multipart/alternative` (RFC 2046 §5.1.4 — equivalent renderings of the **same** content; the receiver picks one). Forwards land as `multipart/mixed` containing two `multipart/alternative` siblings — one for the cover note, one for the quoted message. Today both HTML branches are concatenated; the body persisted to the ticket comment shows the quoted reply twice.

### 5.2 Approach

Add a dispatch branch at the top of `extractMessageParts` that intercepts `multipart/alternative` and descends into exactly one child, then returns without falling through to the generic recursion. Other multipart types (`multipart/mixed`, `multipart/related`, `multipart/signed`, etc.) keep today's "visit every child" behavior.

```php
private function extractMessageParts(MessagePart $payload, array &$data): void
{
    $mimeType = $payload->getMimeType();

    // B-2: multipart/alternative carries equivalent renderings of one body.
    // Pick the richest branch and skip the others — visiting every child
    // duplicates body_html when forwards are nested.
    if ($mimeType === 'multipart/alternative') {
        $chosen = $this->pickAlternativeBranch($payload->getParts() ?? []);
        if ($chosen !== null) {
            $this->extractMessageParts($chosen, $data);
        }
        return;
    }

    // ... existing body / attachment / recursion code unchanged.
}
```

### 5.3 Branch selection rules

`pickAlternativeBranch(array $parts): ?MessagePart`:

1. Walk `$parts` once. Track the first `text/html` direct child and the first `text/plain` direct child.
2. If a child is itself a `multipart/*` node (typical case: `multipart/related` wrapping the HTML body plus inline images), keep it as a candidate when no direct `text/html` has been found.
3. Return `text/html` candidate if present, else the multipart-with-html candidate, else `text/plain`, else `null`.

`containsHtml(MessagePart $part): bool`: recursive helper used to validate the multipart candidate from rule 2. Returns true if any descendant has `mimeType === 'text/html'`.

### 5.4 Behavioral change vs today

| Payload shape | Before | After |
|---|---|---|
| `alternative(plain, html)` | both branches walked; `body_text` and `body_html` both populated | only HTML walked; `body_html` populated, `body_text` empty |
| `alternative(plain)` | plain walked; `body_text` populated | same |
| `alternative(plain, related(html, image))` | both branches walked; `body_html` populated; image becomes inline | same outputs as before, but plain branch is skipped |
| `mixed(alternative(plain, html), alternative(plain, html))` (nested forward) | both HTML branches concatenated; **duplicate** | each alternative resolves to one HTML branch; the two HTMLs are concatenated at the mixed level (correct: they belong to distinct logical parts) |
| `mixed(alternative(plain, html), application/pdf)` | PDF attached, body intact | PDF attached, body intact — alternative filter does not affect siblings of the mixed container |

The `body_text`-empty side effect of the first row is acceptable: `TicketIngestionService::createFromEmail` and `createCommentFromEmail` both prefer `body_html` and only fall back to `body_text` when the HTML branch is missing (verify during implementation; if any consumer reads `body_text` while `body_html` is also populated, raise the concern before merging).

### 5.5 Tests (`GmailServiceTest`)

- `testMultipartAlternativePicksHtmlBranch` — `alternative(plain, html)` ⇒ `body_html` populated, `body_text` empty.
- `testMultipartAlternativeFallsBackToPlainWhenNoHtml` — `alternative(plain)` ⇒ `body_text` populated.
- `testMultipartAlternativeWithRelatedHtmlBranch` — `alternative(plain, related(html, image/png))` ⇒ HTML in `body_html`, image in `inline_images`, plain ignored.
- `testNestedForwardDoesNotDuplicateHtml` — `mixed(alternative(plain, html_A), alternative(plain, html_B))` with distinct A/B content ⇒ `body_html === html_A . "\n" . html_B` (each alternative reduced to one HTML, mixed-level concat preserved).
- `testAttachmentInsideMixedSurvivesAlternativeFilter` — `mixed(alternative(plain, html), application/pdf)` ⇒ PDF present in `attachments`, body intact.

### 5.6 Risk

Medium. The change reduces the set of nodes walked. Attachments and inline images never live inside `multipart/alternative` (alternatives are equivalent renderings, not containers), so the recursion narrowing does not lose them. The fifth test exists specifically to guard that invariant.

---

## 6. B-3 · Broader auto-reply detection

### 6.1 Diagnosis

`GmailService::isAutoReply` (lines 547-574) covers:

- `Auto-Submitted` — `stripos` on `auto-replied` and `auto-generated`. Misses `auto-notified` (RFC 3834 §5).
- `X-Autoreply` / `X-Autorespond` — both `yes`.
- `Precedence` — `bulk`, `list`, `junk`.

Modern bulk senders flag themselves with `List-Unsubscribe` (RFC 2369; updated by RFC 8058 in 2018 for one-click unsubscribe) and `Feedback-ID` (required by Google and Yahoo for high-volume senders starting 2024). Newsletters arriving through these headers but without the legacy `Precedence: bulk` are treated as regular customer mail today and turn into tickets.

### 6.2 Approach

Rewrite the `Auto-Submitted` check to "any non-empty value other than `no`" and append two presence-checks for `List-Unsubscribe` and `Feedback-ID`. Final shape:

```php
public function isAutoReply(array $headers): bool
{
    // RFC 3834 §5: any non-"no" value indicates automation.
    $autoSubmitted = strtolower($this->getHeader($headers, 'Auto-Submitted'));
    if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
        return true;
    }

    if (stripos($this->getHeader($headers, 'X-Autoreply'), 'yes') !== false) {
        return true;
    }
    if (stripos($this->getHeader($headers, 'X-Autorespond'), 'yes') !== false) {
        return true;
    }

    $precedence = strtolower($this->getHeader($headers, 'Precedence'));
    if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
        return true;
    }

    // RFC 2369 / 8058 — any bulk/list mail carries List-Unsubscribe.
    if (trim($this->getHeader($headers, 'List-Unsubscribe')) !== '') {
        return true;
    }

    // Google/Yahoo bulk-sender requirement, 2024+.
    if (trim($this->getHeader($headers, 'Feedback-ID')) !== '') {
        return true;
    }

    return false;
}
```

### 6.3 Behavioral change vs today

- `Auto-Submitted: auto-notified` — was false, now true.
- `Auto-Submitted: anything-else` (non-empty, non-`no`) — was usually false (substring match), now true. Practically no false positives because human mail clients do not set this header.
- `Auto-Submitted: no` — false (unchanged; regression test guards this).
- `List-Unsubscribe: <mailto:...>` or `<https://...>` — was false, now true.
- `Feedback-ID: campaign:account:mailer:server` — was false, now true.

### 6.4 Tests (`GmailServiceTest`)

- `testIsAutoReplyDetectsListUnsubscribe`.
- `testIsAutoReplyDetectsFeedbackId`.
- `testIsAutoReplyDetectsAutoSubmittedAutoNotified`.
- `testIsAutoReplyIgnoresAutoSubmittedNo` — regression guard.
- `testIsAutoReplyIgnoresEmptyListUnsubscribe` — header absent ⇒ false.

### 6.5 Risk

Low. All additions and a tightening of an existing check. The downstream effect of `isAutoReply === true` is `TicketIngestionService` skipping the message (no ticket created), the same path already exercised by `Precedence: bulk` today.

---

## 7. I-3 · PII masking in info logs

### 7.1 Diagnosis

Five info-level log entries carry raw email addresses:

| Location | Field | Source |
|---|---|---|
| `src/Service/EmailService.php:126` | `to` (single recipient string) | `sendEmail` success |
| `src/Service/EmailService.php:132` | `to` (single recipient string) | `sendEmail` failure |
| `src/Service/GmailService.php:848` | `to` (array keys joined by `, `) | `sendEmail` success |
| `src/Service/GmailService.php:858` | `to` (array keys joined by `, `) | `sendEmail` failure |
| `src/Service/TicketIngestionService.php:153` | `from` (extracted email) | Ticket created |

Subjects also appear in `EmailService.php:126,132` and `GmailService.php:848,858`. They are intentionally left in clear text per §3 — subjects carry the `#<ticketNumber>` correlation key used during incident triage.

### 7.2 Approach

Introduce a stateless utility `App\Service\Util\LogMasker` with a single static method `email(string): string`. Apply it at the five log call sites listed above. No interface, no DI registration.

```php
<?php
declare(strict_types=1);

namespace App\Service\Util;

/**
 * I-3: mask the local-part of email addresses before they hit info-level logs.
 *
 * Keeps the first character and the domain intact so an operator can still
 * triage incidents ("which tenant complained?"), but the full identifier is
 * never persisted. Subjects are NOT masked — they are not PII in this system
 * and remain searchable for support workflows.
 */
final class LogMasker
{
    public static function email(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (str_contains($value, ',')) {
            $parts = array_map('trim', explode(',', $value));
            return implode(', ', array_map(self::email(...), $parts));
        }

        $atPos = strrpos($value, '@');
        if ($atPos === false || $atPos === 0) {
            return $value;
        }

        $local = substr($value, 0, $atPos);
        $domain = substr($value, $atPos);

        if (strlen($local) <= 1) {
            return '*' . $domain;
        }
        return $local[0] . '***' . $domain;
    }
}
```

### 7.3 Masking rules

| Input | Output |
|---|---|
| `alex@example.com` | `a***@example.com` |
| `a@example.com` | `*@example.com` (single-char local) |
| `alex@example.com, bob@example.com` | `a***@example.com, b***@example.com` |
| `` (empty) | `` |
| `notanemail` | `notanemail` (no `@`) |
| `@example.com` | `@example.com` (`@` at position 0) |

The function is idempotent on already-masked output: `a***@example.com` re-masked returns `a***@example.com` (the local-part survives intact because it is longer than one char and the first character is `a`).

### 7.4 Call-site changes

```php
// src/Service/EmailService.php:126
Log::info('Email sent successfully via Gmail API', [
    'to' => LogMasker::email($to),
    'subject' => $subject,
]);

// src/Service/EmailService.php:132
Log::error('Failed to send email via Gmail API', [
    'to' => LogMasker::email($to),
    'subject' => $subject,
    'error' => $e->getMessage(),
]);

// src/Service/GmailService.php:848 — sendEmail success log
'to' => LogMasker::email(is_array($to) ? implode(', ', array_keys($to)) : $to),
'subject' => $subject,

// src/Service/GmailService.php:858 — sendEmail failure log
'to' => LogMasker::email(is_array($to) ? implode(', ', array_keys($to)) : $to),
'subject' => $subject,

// src/Service/TicketIngestionService.php:153
'from' => LogMasker::email($fromEmail),
```

Add the `use App\Service\Util\LogMasker;` import to each of the three modified files.

### 7.5 Out of scope (deliberate)

- The arrays returned by `GmailService::parseMessage` carry `from`/`to`/`subject` keys (lines 334-336). They are payload, not log output, and persist into the ticket / comment rows where the full address is required for reply routing. Not masked.
- `Gmail API error` log entries (introduced by P1 — lines ~365, ~478, ~519, etc.) do not include addresses; nothing to mask.
- Debug-level logs added by `RetryHandler` (`Gmail API warning` / `Gmail API retry`). Debug is opt-in.

### 7.6 Tests (`tests/TestCase/Service/Util/LogMaskerTest.php`)

- `testMasksTypicalEmail`.
- `testMasksSingleCharLocal`.
- `testMasksMultiRecipientCommaSeparated`.
- `testReturnsEmptyStringForEmpty`.
- `testReturnsInputUnchangedWhenNoAtSign`.
- `testReturnsInputUnchangedWhenAtSignAtStart`.

### 7.7 Risk

Very low. Pure additive utility; the five call-site changes are local string transforms before serialization. No behavioral effect on the email pipeline itself.

---

## 8. Commit order and dependencies

Three sequential commits on `main`, in this order:

| # | Commit subject | Finding | Why this order |
|---|---|---|---|
| 1 | `feat(gmail): pick one branch in multipart/alternative (B-2)` | B-2 | Independent of the others. Smallest surface area. |
| 2 | `feat(gmail): detect List-Unsubscribe and Feedback-ID auto-replies (B-3)` | B-3 | Independent. Touches only `isAutoReply`. |
| 3 | `chore(gmail): mask email PII in info logs (I-3)` | I-3 | Independent. Touches three files. |
| 4 (doc only) | `docs(audit): close B-1 as WONT_FIX on 2026-05-19 Gmail audit` | B-1 | Documentation update, lands last for chronology. Can land in the same PR cluster as commits 1-3 or as a separate doc-only commit. |

Commits 1, 2, and 3 are independent and may be reviewed in any order, but the chronological order above keeps the audit closure (commit 4) at the end of the sequence so the audit's "what is still open" line reads correctly during PR review.

---

## 9. Verification checklist

- `composer cs-fix && composer cs-check` clean on all touched files.
- `vendor/bin/phpstan analyse src` — no new errors versus baseline (38 pre-existing).
- `composer test` — all new tests green, no regressions on the 261 pre-existing tests.
- `bin/cake import_gmail --max 1` against a sandbox inbox containing:
  1. A newsletter with `List-Unsubscribe` and `Feedback-ID` — verify it is skipped (not ingested).
  2. A nested-forward email with two `multipart/alternative` blocks of distinct HTML — verify the persisted body contains each HTML once, not twice.
  3. Any regular email — verify the info log entry shows the masked address (`a***@example.com`) and the subject in clear text.
- Verify the audit document's §1 counter reads `Bajo: 2 (B-1, B-4 cerrado)` and B-1 is marked Closed with WONT_FIX rationale.

---

## 10. Post-deploy operational notes

- No re-OAuth, no migration, no GCP console action required.
- Monitor info logs for one week to confirm no production code path emits unmasked addresses. If a new log site appears, route it through `LogMasker::email`.
- If a future audit revisits B-1 (e.g. attachment volume grows past 5/s sustained), reopen with metrics from `GmailImportResult`.

---

## 11. Open questions

None. All design decisions in this spec were validated during the brainstorming session on 2026-05-19. If implementation surfaces a contradiction with an earlier P0/P1/P2 commit body, document the deviation in the matching commit per project convention.
