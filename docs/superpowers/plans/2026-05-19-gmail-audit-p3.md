# Gmail Audit P3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close P3 of the 2026-05-16 Gmail API audit by fixing duplicate-HTML in `multipart/alternative` (B-2), broadening auto-reply detection (B-3), masking email PII in info-level logs (I-3), and recording B-1 as WONT_FIX with documented rationale.

**Architecture:** Three independent code commits on `main` (B-2 → B-3 → I-3) followed by a doc-only commit that closes B-1. Each commit is independently revertable, touches a small surface, and ships with focused PHPUnit coverage. No migrations. No new vendor dependencies. No changes to public service contracts.

**Tech Stack:** CakePHP 5.x, PHP 8.5+, PHPUnit 13, `google/apiclient` ^2.19.3 (transitive), Guzzle (transitive).

**Spec:** `docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md`

---

## File structure

### Commit 1 — B-2 (multipart/alternative branch selection)

**Modify:**
- `src/Service/GmailService.php` — add the alternative-branch dispatch at the top of `extractMessageParts` plus two private helpers `pickAlternativeBranch` and `containsHtml`.
- `tests/TestCase/Service/GmailServiceTest.php` — five new cases against parseMessage / mock MessagePart payloads.

### Commit 2 — B-3 (broader auto-reply detection)

**Modify:**
- `src/Service/GmailService.php` — rewrite `isAutoReply` to widen `Auto-Submitted` and add `List-Unsubscribe` + `Feedback-ID` presence checks.
- `tests/TestCase/Service/GmailServiceTest.php` — five new cases for the new and tightened detections.

### Commit 3 — I-3 (PII masking in info logs)

**Create:**
- `src/Service/Util/LogMasker.php` — stateless utility with `email(string): string`.
- `tests/TestCase/Service/Util/LogMaskerTest.php` — six unit tests.

**Modify:**
- `src/Service/EmailService.php` — apply `LogMasker::email` on the two info/error log entries (lines 126, 132).
- `src/Service/GmailService.php` — apply `LogMasker::email` on the two `sendEmail` error log entries (lines 848, 858).
- `src/Service/TicketIngestionService.php` — apply `LogMasker::email` on the "Created ticket from email" log (line 153).

### Commit 4 — B-1 closure (doc-only)

**Modify:**
- `docs/audits/2026-05-16-gmail-api-audit.md` — close B-1 banner, bump §1 counter, mark §8 P3 item 11 as completed-as-WONT_FIX.

### Verification gates (every commit)

1. `composer cs-fix && composer cs-check` over modified files — no new warnings vs baseline.
2. `vendor/bin/phpstan analyse src` — 38 baseline errors expected, none new in touched files.
3. `composer test` — 7 pre-existing baseline failures expected (none related to Gmail).

---

# PHASE 1 — Commit 1 — B-2 · multipart/alternative branch selection

### Task 1.1: Write the failing test for HTML-branch selection

**Files:**
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Add a MessagePart fixture builder near the top of the test class**

Add this private helper to `GmailServiceTest` (right after the existing `header()` helper around line 86). It produces an anonymous object that quacks like `Google\Service\Gmail\MessagePart` for the four `extractMessageParts` accessors (`getMimeType`, `getParts`, `getBody`, `getFilename`, `getHeaders`). It is used by every B-2 test.

```php
    /**
     * Build a fake MessagePart that quacks like Google\Service\Gmail\MessagePart.
     * Body data must be already base64-url-encoded (the production code calls
     * base64_decode + strtr internally).
     *
     * @param list<object> $parts
     * @param list<object> $headers
     */
    private function part(
        string $mimeType,
        ?string $bodyData = null,
        array $parts = [],
        string $filename = '',
        ?string $attachmentId = null,
        array $headers = [],
    ): object {
        $body = new class ($bodyData, $attachmentId) {
            public function __construct(private ?string $data, private ?string $attachmentId)
            {
            }

            public function getSize(): int
            {
                return $this->data === null ? 0 : strlen($this->data);
            }

            public function getData(): ?string
            {
                return $this->data;
            }

            public function getAttachmentId(): ?string
            {
                return $this->attachmentId;
            }
        };

        return new class ($mimeType, $body, $parts, $filename, $headers) {
            /**
             * @param list<object> $parts
             * @param list<object> $headers
             */
            public function __construct(
                private string $mimeType,
                private object $body,
                private array $parts,
                private string $filename,
                private array $headers,
            ) {
            }

            public function getMimeType(): string
            {
                return $this->mimeType;
            }

            public function getBody(): object
            {
                return $this->body;
            }

            /** @return list<object> */
            public function getParts(): array
            {
                return $this->parts;
            }

            public function getFilename(): string
            {
                return $this->filename;
            }

            /** @return list<object> */
            public function getHeaders(): array
            {
                return $this->headers;
            }
        };
    }

    /** Encode a raw string the way Gmail does for part body data. */
    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Invoke the private extractMessageParts on a freshly-built GmailService,
     * returning the data array it populated.
     */
    private function callExtractParts(object $payload): array
    {
        $service = $this->buildService();
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('extractMessageParts');

        $data = [
            'body_html' => '',
            'body_text' => '',
            'attachments' => [],
            'inline_images' => [],
        ];
        $method->invokeArgs($service, [$payload, &$data]);

        return $data;
    }
```

- [ ] **Step 2: Write the first failing test below the helpers**

```php
    public function testMultipartAlternativePicksHtmlBranch(): void
    {
        $payload = $this->part('multipart/alternative', parts: [
            $this->part('text/plain', $this->b64url('plain version')),
            $this->part('text/html', $this->b64url('<p>html version</p>')),
        ]);

        $data = $this->callExtractParts($payload);

        $this->assertSame('<p>html version</p>', $data['body_html']);
        $this->assertSame('', $data['body_text'], 'plain branch must be skipped when html is available');
    }
```

- [ ] **Step 3: Run the test and confirm it fails**

Run: `vendor/bin/phpunit --filter testMultipartAlternativePicksHtmlBranch tests/TestCase/Service/GmailServiceTest.php`
Expected: FAIL — current `extractMessageParts` visits both branches and populates `body_text`.

### Task 1.2: Implement the alternative-branch dispatch

**Files:**
- Modify: `src/Service/GmailService.php` (currently `extractMessageParts` at lines 391-448)

- [ ] **Step 1: Insert the multipart/alternative branch at the top of `extractMessageParts`**

Replace the opening of the method (lines 391-395):

```php
    private function extractMessageParts(MessagePart $payload, array &$data): void
    {
        $mimeType = $payload->getMimeType();
        $parts = $payload->getParts();
        $body = $payload->getBody();
```

with:

```php
    private function extractMessageParts(MessagePart $payload, array &$data): void
    {
        $mimeType = $payload->getMimeType();

        // B-2: multipart/alternative carries equivalent renderings of one body.
        // Pick the richest branch and skip the others — visiting every child
        // duplicates body_html when forwards are nested (RFC 2046 §5.1.4).
        if ($mimeType === 'multipart/alternative') {
            $chosen = $this->pickAlternativeBranch($payload->getParts() ?? []);
            if ($chosen !== null) {
                $this->extractMessageParts($chosen, $data);
            }
            return;
        }

        $parts = $payload->getParts();
        $body = $payload->getBody();
```

- [ ] **Step 2: Add the two private helpers below `extractMessageParts`**

Insert after the closing brace of `extractMessageParts` (around line 449, before the next method `downloadAttachment`):

```php
    /**
     * B-2: pick the richest alternative for a multipart/alternative node.
     * Prefers a direct text/html child, then a multipart/* descendant that
     * contains text/html (e.g. multipart/related with inline images),
     * finally falling back to text/plain. Returns null when none match.
     *
     * @param array<int, MessagePart> $parts
     */
    private function pickAlternativeBranch(array $parts): ?MessagePart
    {
        $html = null;
        $multipartHtml = null;
        $plain = null;

        foreach ($parts as $part) {
            $mt = (string)$part->getMimeType();
            if ($mt === 'text/html' && $html === null) {
                $html = $part;
                continue;
            }
            if ($mt === 'text/plain' && $plain === null) {
                $plain = $part;
                continue;
            }
            if (str_starts_with($mt, 'multipart/') && $multipartHtml === null && $this->containsHtml($part)) {
                $multipartHtml = $part;
            }
        }

        return $html ?? $multipartHtml ?? $plain;
    }

    /**
     * Recursive check used by pickAlternativeBranch: true iff the subtree
     * rooted at $part contains a text/html node at any depth.
     */
    private function containsHtml(MessagePart $part): bool
    {
        if ((string)$part->getMimeType() === 'text/html') {
            return true;
        }
        foreach ($part->getParts() ?? [] as $child) {
            if ($this->containsHtml($child)) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 3: Run the first test and confirm it passes**

Run: `vendor/bin/phpunit --filter testMultipartAlternativePicksHtmlBranch tests/TestCase/Service/GmailServiceTest.php`
Expected: PASS.

### Task 1.3: Add the four remaining B-2 tests

**Files:**
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Append the four tests below `testMultipartAlternativePicksHtmlBranch`**

```php
    public function testMultipartAlternativeFallsBackToPlainWhenNoHtml(): void
    {
        $payload = $this->part('multipart/alternative', parts: [
            $this->part('text/plain', $this->b64url('plain only')),
        ]);

        $data = $this->callExtractParts($payload);

        $this->assertSame('', $data['body_html']);
        $this->assertSame('plain only', $data['body_text']);
    }

    public function testMultipartAlternativeWithRelatedHtmlBranch(): void
    {
        $imagePart = $this->part(
            'image/png',
            $this->b64url('fake-png-bytes'),
            filename: 'inline.png',
            attachmentId: 'att-1',
            headers: [
                $this->header('Content-ID', '<cid-1>'),
                $this->header('Content-Disposition', 'inline'),
            ],
        );
        $related = $this->part('multipart/related', parts: [
            $this->part('text/html', $this->b64url('<p>rich body</p>')),
            $imagePart,
        ]);
        $payload = $this->part('multipart/alternative', parts: [
            $this->part('text/plain', $this->b64url('plain fallback')),
            $related,
        ]);

        $data = $this->callExtractParts($payload);

        $this->assertSame('<p>rich body</p>', $data['body_html']);
        $this->assertSame('', $data['body_text'], 'plain alternative must be skipped');
        $this->assertCount(1, $data['inline_images']);
        $this->assertSame('cid-1', $data['inline_images'][0]['content_id']);
    }

    public function testNestedForwardDoesNotDuplicateHtml(): void
    {
        $mixed = $this->part('multipart/mixed', parts: [
            $this->part('multipart/alternative', parts: [
                $this->part('text/plain', $this->b64url('plain A')),
                $this->part('text/html', $this->b64url('<p>A</p>')),
            ]),
            $this->part('multipart/alternative', parts: [
                $this->part('text/plain', $this->b64url('plain B')),
                $this->part('text/html', $this->b64url('<p>B</p>')),
            ]),
        ]);

        $data = $this->callExtractParts($mixed);

        // Each alternative resolves to ONE html branch; the two distinct htmls
        // are still concatenated by the outer multipart/mixed (correct: they
        // are different logical parts of one message).
        $this->assertSame("<p>A</p>\n<p>B</p>", $data['body_html']);
        $this->assertSame('', $data['body_text']);
    }

    public function testAttachmentInsideMixedSurvivesAlternativeFilter(): void
    {
        $pdf = $this->part(
            'application/pdf',
            $this->b64url('%PDF-1.4 fake'),
            filename: 'invoice.pdf',
            attachmentId: 'pdf-1',
            headers: [$this->header('Content-Disposition', 'attachment; filename="invoice.pdf"')],
        );
        $payload = $this->part('multipart/mixed', parts: [
            $this->part('multipart/alternative', parts: [
                $this->part('text/plain', $this->b64url('plain')),
                $this->part('text/html', $this->b64url('<p>html</p>')),
            ]),
            $pdf,
        ]);

        $data = $this->callExtractParts($payload);

        $this->assertSame('<p>html</p>', $data['body_html']);
        $this->assertCount(1, $data['attachments']);
        $this->assertSame('invoice.pdf', $data['attachments'][0]['filename']);
        $this->assertSame('pdf-1', $data['attachments'][0]['attachment_id']);
    }

    public function testMultipartAlternativeReturnsNullBranchWhenEmpty(): void
    {
        $payload = $this->part('multipart/alternative', parts: []);

        $data = $this->callExtractParts($payload);

        $this->assertSame('', $data['body_html']);
        $this->assertSame('', $data['body_text']);
        $this->assertSame([], $data['attachments']);
    }
```

- [ ] **Step 2: Run all B-2 tests**

Run: `vendor/bin/phpunit --filter 'Multipart|Nested|AttachmentInsideMixed' tests/TestCase/Service/GmailServiceTest.php`
Expected: 5 tests PASS.

### Task 1.4: Verification gates and commit

- [ ] **Step 1: Code style**

Run: `composer cs-fix && composer cs-check`
Expected: no new warnings on `src/Service/GmailService.php` or `tests/TestCase/Service/GmailServiceTest.php`.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse src`
Expected: 38 baseline errors (none in `GmailService.php`).

- [ ] **Step 3: Full test suite**

Run: `composer test`
Expected: 5 new tests added; 7 pre-existing failures still present (rendering, Windows paths, circuit-breaker shape — none Gmail-related).

- [ ] **Step 4: Commit**

```bash
git add src/Service/GmailService.php tests/TestCase/Service/GmailServiceTest.php
git commit -m "feat(gmail): pick one branch in multipart/alternative (B-2)

extractMessageParts walked every child, concatenating every text/html it
found. In nested-forward emails (multipart/mixed > two multipart/alternative
blocks) this duplicated body_html. RFC 2046 §5.1.4 defines alternative as
equivalent renderings of the same content — one must be picked.

pickAlternativeBranch prefers a direct text/html child, falls back to a
multipart/* subtree that contains text/html (typical multipart/related
wrapping HTML + inline images), then text/plain. Other multipart types
keep today's visit-every-child behavior, so attachments siblings of an
alternative inside a multipart/mixed are untouched.

Spec: docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md §5"
```

---

# PHASE 2 — Commit 2 — B-3 · Broader auto-reply detection

### Task 2.1: Write failing tests for the new headers

**Files:**
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Append five tests at the end of the class**

```php
    public function testIsAutoReplyDetectsListUnsubscribe(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('From', 'newsletter@example.com'),
            $this->header('List-Unsubscribe', '<mailto:unsub@example.com>, <https://example.com/u/123>'),
        ];

        $this->assertTrue($service->isAutoReply($headers));
    }

    public function testIsAutoReplyDetectsFeedbackId(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('From', 'mailer@example.com'),
            $this->header('Feedback-ID', 'campaign-42:acct:newsletter:mailer'),
        ];

        $this->assertTrue($service->isAutoReply($headers));
    }

    public function testIsAutoReplyDetectsAutoSubmittedAutoNotified(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('Auto-Submitted', 'auto-notified; owner-email="x@y.tld"'),
        ];

        $this->assertTrue($service->isAutoReply($headers));
    }

    public function testIsAutoReplyIgnoresAutoSubmittedNo(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('From', 'human@example.com'),
            $this->header('Auto-Submitted', 'no'),
        ];

        $this->assertFalse($service->isAutoReply($headers));
    }

    public function testIsAutoReplyIgnoresEmptyHeaders(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('From', 'human@example.com'),
        ];

        $this->assertFalse($service->isAutoReply($headers));
    }
```

- [ ] **Step 2: Run the new tests and confirm three fail / two pass**

Run: `vendor/bin/phpunit --filter 'IsAutoReply' tests/TestCase/Service/GmailServiceTest.php`
Expected:
- `testIsAutoReplyDetectsListUnsubscribe` FAIL — header not checked today.
- `testIsAutoReplyDetectsFeedbackId` FAIL — header not checked today.
- `testIsAutoReplyDetectsAutoSubmittedAutoNotified` FAIL — current `stripos` does not match `auto-notified`.
- `testIsAutoReplyIgnoresAutoSubmittedNo` PASS — guard test, current code also returns false.
- `testIsAutoReplyIgnoresEmptyHeaders` PASS — guard test.

### Task 2.2: Rewrite `isAutoReply`

**Files:**
- Modify: `src/Service/GmailService.php` (current `isAutoReply` at lines 547-574)

- [ ] **Step 1: Replace the body of `isAutoReply`**

Replace the entire current method body with:

```php
    public function isAutoReply(array $headers): bool
    {
        // RFC 3834 §5: any non-"no" value of Auto-Submitted indicates automation.
        $autoSubmitted = strtolower(trim($this->getHeader($headers, 'Auto-Submitted')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no' && !str_starts_with($autoSubmitted, 'no;')) {
            return true;
        }

        // Legacy vendor headers.
        if (stripos($this->getHeader($headers, 'X-Autoreply'), 'yes') !== false) {
            return true;
        }
        if (stripos($this->getHeader($headers, 'X-Autorespond'), 'yes') !== false) {
            return true;
        }

        // RFC 2076: Precedence: bulk, list, junk.
        $precedence = strtolower(trim($this->getHeader($headers, 'Precedence')));
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return true;
        }

        // RFC 2369 / 8058: any bulk/list mail carries List-Unsubscribe.
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

The `str_starts_with($autoSubmitted, 'no;')` clause guards against the rare `Auto-Submitted: no; foo` parameterized form which RFC 3834 also classifies as non-automated.

- [ ] **Step 2: Run the five new tests**

Run: `vendor/bin/phpunit --filter 'IsAutoReply' tests/TestCase/Service/GmailServiceTest.php`
Expected: 5 PASS.

- [ ] **Step 3: Run the rest of the GmailServiceTest to catch regressions**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php`
Expected: all tests PASS (existing `isAutoReply` was only covered indirectly; no dedicated existing test should break).

### Task 2.3: Verification gates and commit

- [ ] **Step 1: Code style**

Run: `composer cs-fix && composer cs-check`
Expected: no new warnings.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse src`
Expected: 38 baseline errors, none new.

- [ ] **Step 3: Full test suite**

Run: `composer test`
Expected: 5 new tests added on top of phase 1; same 7 baseline failures.

- [ ] **Step 4: Commit**

```bash
git add src/Service/GmailService.php tests/TestCase/Service/GmailServiceTest.php
git commit -m "feat(gmail): detect List-Unsubscribe and Feedback-ID auto-replies (B-3)

isAutoReply missed three modern bulk/automation indicators:
- List-Unsubscribe (RFC 2369/8058) — ubiquitous on newsletters since 2018.
- Feedback-ID — required by Google and Yahoo for high-volume senders 2024+.
- Auto-Submitted: auto-notified (RFC 3834 §5) — fell through the substring
  match for auto-replied/auto-generated.

The Auto-Submitted check now follows RFC 3834: any value other than 'no'
(or 'no;…' parameterized form) is automation. List-Unsubscribe and
Feedback-ID use presence-not-empty semantics — both carry URI-shaped
values that are never legitimately blank.

Downstream effect of isAutoReply=true is the existing 'skip ingestion'
path already exercised by Precedence: bulk, so no other code changes.

Spec: docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md §6"
```

---

# PHASE 3 — Commit 3 — I-3 · PII masking in info logs

### Task 3.1: Write failing tests for `LogMasker::email`

**Files:**
- Create: `tests/TestCase/Service/Util/LogMaskerTest.php`

- [ ] **Step 1: Write the full test file**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Util;

use App\Service\Util\LogMasker;
use PHPUnit\Framework\TestCase;

final class LogMaskerTest extends TestCase
{
    public function testMasksTypicalEmail(): void
    {
        $this->assertSame('a***@example.com', LogMasker::email('alex@example.com'));
    }

    public function testMasksSingleCharLocal(): void
    {
        $this->assertSame('*@example.com', LogMasker::email('a@example.com'));
    }

    public function testMasksMultiRecipientCommaSeparated(): void
    {
        $this->assertSame(
            '*@x.com, b***@y.com',
            LogMasker::email('a@x.com, bob@y.com'),
        );
    }

    public function testReturnsEmptyStringForEmpty(): void
    {
        $this->assertSame('', LogMasker::email(''));
    }

    public function testReturnsInputUnchangedWhenNoAtSign(): void
    {
        $this->assertSame('notanemail', LogMasker::email('notanemail'));
    }

    public function testReturnsInputUnchangedWhenAtSignAtStart(): void
    {
        $this->assertSame('@example.com', LogMasker::email('@example.com'));
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Util/LogMaskerTest.php`
Expected: 6 errors — `App\Service\Util\LogMasker` does not exist.

### Task 3.2: Implement `LogMasker`

**Files:**
- Create: `src/Service/Util/LogMasker.php`

- [ ] **Step 1: Write the class**

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
 *
 * Examples:
 *   alex@example.com           -> a***@example.com
 *   a@example.com              -> *@example.com
 *   alex@x.com, bob@y.com      -> a***@x.com, b***@y.com
 *   ''                         -> ''
 *   notanemail                 -> notanemail
 *   @example.com               -> @example.com
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

- [ ] **Step 2: Run the test file and confirm all six tests pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Util/LogMaskerTest.php`
Expected: 6 PASS.

### Task 3.3: Apply masking in `EmailService`

**Files:**
- Modify: `src/Service/EmailService.php` (lines 126, 132)

- [ ] **Step 1: Add the import next to the existing use list**

In `src/Service/EmailService.php` add the import alongside the others (alphabetical order keeps it near the other `App\Service\Util\*` imports):

```php
use App\Service\Util\LogMasker;
```

- [ ] **Step 2: Replace the success log (line ~126)**

Find:

```php
                Log::info('Email sent successfully via Gmail API', ['to' => $to, 'subject' => $subject]);
```

Replace with:

```php
                Log::info('Email sent successfully via Gmail API', [
                    'to' => LogMasker::email($to),
                    'subject' => $subject,
                ]);
```

- [ ] **Step 3: Replace the failure log (line ~131)**

Find:

```php
            Log::error('Failed to send email via Gmail API', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
```

Replace with:

```php
            Log::error('Failed to send email via Gmail API', [
                'to' => LogMasker::email($to),
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
```

### Task 3.4: Apply masking in `GmailService::sendEmail`

**Files:**
- Modify: `src/Service/GmailService.php` (lines 848, 858)

- [ ] **Step 1: Add the import**

In the existing `use` block at the top of `src/Service/GmailService.php`, add:

```php
use App\Service\Util\LogMasker;
```

Keep alphabetical order (insert between `App\Service\Gmail\RetryHandler` and `App\Service\Traits\HtmlSanitizerTrait`).

- [ ] **Step 2: Replace the GoogleServiceException log entry (around line 848)**

Find:

```php
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'message' => $e->getMessage(),
            ]);
```

Replace with:

```php
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'to' => LogMasker::email(is_array($to) ? implode(', ', array_keys($to)) : $to),
                'subject' => $subject,
                'message' => $e->getMessage(),
            ]);
```

- [ ] **Step 3: Replace the generic Exception log entry (around line 858)**

Find:

```php
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
```

Replace with:

```php
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'to' => LogMasker::email(is_array($to) ? implode(', ', array_keys($to)) : $to),
                'subject' => $subject,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
```

### Task 3.5: Apply masking in `TicketIngestionService`

**Files:**
- Modify: `src/Service/TicketIngestionService.php` (line ~153)

- [ ] **Step 1: Add the import**

In the existing `use` block, insert:

```php
use App\Service\Util\LogMasker;
```

(keep alphabetical order with other `App\Service\Util\*` imports if any).

- [ ] **Step 2: Replace the "Created ticket from email" log**

Find:

```php
        Log::info('Created ticket from email', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'from' => $fromEmail,
        ]);
```

Replace with:

```php
        Log::info('Created ticket from email', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'from' => LogMasker::email($fromEmail),
        ]);
```

### Task 3.6: Verification gates and commit

- [ ] **Step 1: Code style**

Run: `composer cs-fix && composer cs-check`
Expected: no new warnings on the touched files.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse src`
Expected: 38 baseline errors; none new in the four touched files.

- [ ] **Step 3: Full test suite**

Run: `composer test`
Expected: 6 new tests added on top of phases 1-2; same 7 baseline failures.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Util/LogMasker.php tests/TestCase/Service/Util/LogMaskerTest.php \
        src/Service/EmailService.php src/Service/GmailService.php \
        src/Service/TicketIngestionService.php
git commit -m "chore(gmail): mask email PII in info logs (I-3)

Info-level log entries in EmailService, GmailService::sendEmail and
TicketIngestionService were emitting raw 'to'/'from' addresses. Added a
stateless App\\Service\\Util\\LogMasker::email that keeps the first char
of the local-part and the full domain (a***@example.com, *@example.com
for single-char locals, comma-separated lists handled element-wise).

Subjects are intentionally left in clear text — they carry the
ticket-number correlation key used during incident triage and are not
PII in this system. The payload arrays returned by parseMessage are not
touched either (they persist into ticket/comment rows and must keep the
full address for reply routing).

Spec: docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md §7"
```

---

# PHASE 4 — Commit 4 — B-1 closure (doc-only)

### Task 4.1: Update §1 summary counter

**Files:**
- Modify: `docs/audits/2026-05-16-gmail-api-audit.md`

- [ ] **Step 1: Find the summary table in §1**

In the table that lists `Severidad | Cantidad inicial | Estado actual (2026-05-16)`, locate the `Bajo` row.

- [ ] **Step 2: Update the row**

Find:

```
| Bajo      | 4 | 3 (B-4 cerrado) |
```

Replace with:

```
| Bajo      | 4 | 2 (B-1 WONT_FIX, B-4 cerrado) |
```

### Task 4.2: Add the closure banner on the B-1 block in §5

**Files:**
- Modify: `docs/audits/2026-05-16-gmail-api-audit.md`

- [ ] **Step 1: Locate the B-1 block (§5)**

It starts with the heading `### B-1 — usleep(200ms) síncrono dentro del request HTTP`.

- [ ] **Step 2: Prepend a banner block right under the heading**

Insert immediately below the heading and before the existing `**Archivo:**` line:

```markdown
> **Cerrado 2026-05-19 — WONT_FIX (riesgo aceptado).** El `usleep(200ms)` preventivo es defendible: con el volumen actual (pyme) el tiempo acumulado de sleeps cabe holgadamente en el `set_time_limit(300)` del webhook, y post-M-2 (delta polling) el número de adjuntos por corrida está limitado por el delta real, no por el cap de 200 mensajes. El `RetryHandler` introducido en H-2 ya absorbe 429/5xx con backoff exponencial; un token-bucket compartido duplicaría esa defensa con coste extra (cache miss = throttle inefectivo) sin atender un modo de falla nuevo. Reabrir si métricas futuras muestran >5 adjuntos/segundo sostenidos. Spec: `docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md` §4.
```

### Task 4.3: Mark §8 P3 item 11 as completed

**Files:**
- Modify: `docs/audits/2026-05-16-gmail-api-audit.md`

- [ ] **Step 1: Locate §8 P3 block**

Find the heading `### P3 (evaluar valor)`.

- [ ] **Step 2: Replace the B-1 line**

Find:

```
11. `B-1` token-bucket compartido en lugar de sleep.
```

Replace with:

```
11. `B-1` token-bucket compartido en lugar de sleep. — **Cerrado 2026-05-19 — WONT_FIX**. Ver spec P3 §4 y banner en §5.
```

### Task 4.4: Append P3 closure note to §11

**Files:**
- Modify: `docs/audits/2026-05-16-gmail-api-audit.md`

- [ ] **Step 1: Append a new dated subsection at the bottom of §11**

After the existing `### 2026-05-18 — P2 cerrado (M-4 + M-5 + M-2)` block (the last subsection in §11 before the `---` separator that opens §12), insert:

```markdown
### 2026-05-19 — P3 cerrado (B-2 + B-3 + I-3) y B-1 WONT_FIX

**Hallazgos cubiertos:** los tres ítems de código del bloque P3 (B-2 multipart/alternative, B-3 List-Unsubscribe/Feedback-ID, I-3 enmascarado de PII en logs) y el cierre documental de B-1 como WONT_FIX. Implementados como tres commits secuenciales en `main` siguiendo el plan en `docs/superpowers/plans/2026-05-19-gmail-audit-p3.md` y la spec `docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md`.

**Commits:**

| Commit | Hallazgo | Resumen |
|---|---|---|
| `<HASH-B2>` | B-2 | `GmailService::extractMessageParts` ahora intercepta `multipart/alternative` y desciende solo en una rama (HTML > multipart con HTML > plain). Frena la duplicación de `body_html` en forwards anidados. Cinco tests en `GmailServiceTest`. |
| `<HASH-B3>` | B-3 | `isAutoReply` amplía `Auto-Submitted` a "cualquier valor distinto de `no`" (RFC 3834 §5) y suma `List-Unsubscribe` (RFC 2369/8058) y `Feedback-ID` (Google/Yahoo bulk-sender 2024+). Cinco tests. |
| `<HASH-I3>` | I-3 | Nuevo `App\Service\Util\LogMasker::email`. Aplicado en cinco call sites de log (`EmailService` x2, `GmailService::sendEmail` x2, `TicketIngestionService` x1). Subject queda en claro porque carga el `#<ticketNumber>` operativo. Seis tests. |

**B-1 — WONT_FIX:** documentado en §5 (banner) y §8 P3 #11. Razonamiento: el sleep preventivo es proporcional al volumen pyme y al cap post-M-2; el `RetryHandler` ya cubre 429/5xx reales; el token-bucket añade superficie sin nuevo beneficio. Métricas a vigilar antes de reabrir: tasa de adjuntos/segundo sostenida.

**Verificación ejecutada:**

- `composer cs-check` sobre archivos tocados: sin nuevos errores/warnings versus la línea base pre-existente.
- `vendor/bin/phpstan analyse src`: 38 errores de línea base (los mismos archivos de P0/P1/P2); sin nuevos errores en archivos tocados por P3.
- `composer test`: 16 tests nuevos (5 B-2 + 5 B-3 + 6 LogMasker), 7 fallos idénticos a la línea base pre-trabajo (rendering, paths Windows, circuit-breaker shape — ninguno relacionado con Gmail).
- `bin/cake import_gmail --max 1`: omitido en el entorno de ejecución (sin DB/Gmail config).

**Pendiente operativo post-deploy:**

1. Tras el primer run del webhook post-deploy, confirmar en un log `info` que `Created ticket from email` reporta el `from` enmascarado (`a***@dominio.tld`).
2. Enviar un newsletter de prueba (uno con `List-Unsubscribe` real) a la cuenta de soporte y verificar que NO se crea un ticket (es decir, que `isAutoReply` lo intercepta).
3. Smoke de `multipart/alternative`: reenviar un email forwardeado de Gmail con cuerpo HTML y verificar que el comentario o ticket persistido no muestre el cuerpo duplicado.
```

### Task 4.5: Commit the doc-only changes

- [ ] **Step 1: Verify the audit file still renders**

Run: `git diff docs/audits/2026-05-16-gmail-api-audit.md`
Expected: only the four inserts/replaces above.

- [ ] **Step 2: Commit**

```bash
git add docs/audits/2026-05-16-gmail-api-audit.md
git commit -m "docs(audit): close P3 (B-2, B-3, I-3) and record B-1 as WONT_FIX

P3 close-out for the 2026-05-16 Gmail API audit. Three code findings
were implemented in commits <HASH-B2>/<HASH-B3>/<HASH-I3>; B-1 is
closed as WONT_FIX with the rationale documented inline (the existing
RetryHandler covers the original 429/5xx concern and post-M-2 the
per-run attachment count tracks the inbox delta, not the 200-message
cap that originally motivated the token-bucket proposal).

§1 counter, §5 B-1 banner, §8 P3 item 11, and a new §11 dated
subsection are all updated."
```

(Replace the `<HASH-*>` placeholders with the actual short SHAs of the three preceding commits before running the command. `git log --oneline -4` shows them.)

---

## Self-Review

**Spec coverage:**
- §4 (B-1 WONT_FIX) → Phase 4 (Tasks 4.1-4.5).
- §5 (B-2) → Phase 1 (Tasks 1.1-1.4); all five tests from §5.5 are mapped.
- §6 (B-3) → Phase 2 (Tasks 2.1-2.3); all five tests from §6.4 are mapped.
- §7 (I-3) → Phase 3 (Tasks 3.1-3.6); all six tests from §7.6, all five call-sites from §7.4 are mapped.
- §8 (commit order) → mirrored as the four phases.
- §9 (verification checklist) → embedded as gates in every phase.

**Placeholder scan:** the `<HASH-*>` placeholders in Task 4.4 and 4.5 are intentional — they are runtime-determined SHAs from the preceding three commits; the plan explicitly tells the implementer where to source them (`git log --oneline -4`). No TBD/TODO/"fill in" anywhere else.

**Type consistency:** method names (`extractMessageParts`, `pickAlternativeBranch`, `containsHtml`, `isAutoReply`, `LogMasker::email`) match across tasks. Header objects used in tests reuse the existing `$this->header(...)` helper. MessagePart fixture builder (`$this->part(...)`) is defined once in Task 1.1 and reused in Task 1.3.
