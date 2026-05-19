# Gmail Audit P2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close P2 of the 2026-05-16 Gmail API audit by persisting RFC 5322 threading headers (M-4), adding a retry queue for `markAsRead` failures (M-5), and migrating polling to `users.history.list` with a persisted checkpoint (M-2).

**Architecture:** Three sequential commits on `main` (M-4 → M-5 → M-2). Each commit is independently revertable. All Gmail I/O is wrapped by the existing P1 retry middleware and typed-exception machinery, so the new code only adds behavior; it does not re-implement resilience.

**Tech Stack:** CakePHP 5.x, PHP 8.5+, MariaDB/MySQL, PHPUnit 13, `google/apiclient` ^2.19.3, Guzzle (transitive), `symfony/cache` ^7.4 (already in P0).

**Spec:** `docs/superpowers/specs/2026-05-18-gmail-audit-p2-design.md`

---

## File structure

### Commit 1 — M-4 (RFC threading)

**Create:**
- `config/Migrations/20260518120000_AddRfcThreadingToTickets.php` — adds `rfc_message_id`, `in_reply_to`, `references_header` to `tickets` and `ticket_comments` plus per-table index.
- `tests/TestCase/Service/Util/EmailHeaderParserTest.php` — new file (none exists today) covering the new `extractMessageId` helper.

**Modify:**
- `src/Service/Util/EmailHeaderParser.php` — add `extractMessageId(string $raw): ?string`.
- `src/Service/GmailService.php` — extend `parseMessage` return shape with three RFC keys.
- `src/Model/Entity/Ticket.php` — extend `fromEmailIngest` signature with three nullable params.
- `src/Constants/TicketConstants.php` — add `THREAD_REATTACH_WINDOW_DAYS = 90`.
- `src/Service/TicketIngestionService.php` — persist new columns; introduce `findExistingTicketByThreading()` + private helpers.
- `src/Service/GmailImportService.php` — replace inline `gmail_thread_id` lookup with a single call to `TicketIngestionService::findExistingTicketByThreading()`.
- `tests/TestCase/Service/GmailServiceTest.php` — new case for `parseMessage` RFC header extraction.

### Commit 2 — M-5 (markAsRead retry queue)

**Create:**
- `config/Migrations/20260518120100_CreateGmailMarkReadPending.php` — new table.
- `src/Model/Table/GmailMarkReadPendingTable.php` — Table class.
- `src/Service/Gmail/MarkReadQueueService.php` — enqueue + processPending.
- `tests/TestCase/Service/Gmail/MarkReadQueueServiceTest.php` — unit tests with mocked Table + mocked GmailService.

**Modify:**
- `src/Service/Dto/GmailImportResult.php` — add `markReadRetried`, `markReadDropped`, `markReadEnqueued` readonly properties + `toArray()` keys.
- `src/Service/GmailImportService.php` — constructor takes `MarkReadQueueService`; `run()` drains at start and enqueues on `markAsRead` failures.
- `src/Service/GmailImportService.php::fromSettings()` — instantiate `MarkReadQueueService`.
- `tests/TestCase/Service/Dto/GmailImportResultTest.php` — new cases for the new keys.

### Commit 3 — M-2 (history.list checkpoint)

**Create:**
- `src/Service/Gmail/HistoryMode.php` — four-constant final class.
- `tests/TestCase/Service/Gmail/HistoryModeTest.php` — sanity tests on the constants.

**Modify:**
- `src/Constants/SettingKeys.php` — add `GMAIL_LAST_HISTORY_ID = 'gmail_last_history_id'`.
- `src/Service/GmailService.php` — add `getProfileHistoryId()` and `getHistoryDelta()`; extend `parseMessage` to return `gmail_history_id`.
- `src/Service/Dto/GmailImportResult.php` — add `historyMode` (string) and `historyFallbacks` (int).
- `src/Service/GmailImportService.php` — restructure `run()` per spec §7.6.
- `tests/TestCase/Service/GmailServiceTest.php` — new MockHandler cases for the two new methods + `gmail_history_id` in parseMessage.
- `tests/TestCase/Service/Dto/GmailImportResultTest.php` — new case for the new keys.

### Verification gates (every commit)

1. `composer cs-check` over modified files — no new warnings vs baseline.
2. `vendor/bin/phpstan analyse src` — 38 baseline errors expected, none new in touched files.
3. `composer test` — 7 pre-existing baseline failures expected (none related to Gmail).
4. Where migrations land: `bin/cake migrations migrate` against a scratch MariaDB.

---

# PHASE 1 — Commit 1 — M-4 · RFC 5322 threading

### Task 1.1: Migration for RFC threading columns

**Files:**
- Create: `config/Migrations/20260518120000_AddRfcThreadingToTickets.php`

- [ ] **Step 1: Write the migration**

```php
<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

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

- [ ] **Step 2: Verify migration syntax**

Run: `bin/cake migrations status`
Expected: lists `20260518120000_AddRfcThreadingToTickets` as pending (no parse errors).

- [ ] **Step 3: Apply migration to a scratch database**

Run: `bin/cake migrations migrate`
Expected: applies cleanly; `DESCRIBE tickets` and `DESCRIBE ticket_comments` show the three new columns with the correct nullable/limit values.

### Task 1.2: `EmailHeaderParser::extractMessageId`

**Files:**
- Modify: `src/Service/Util/EmailHeaderParser.php`
- Create: `tests/TestCase/Service/Util/EmailHeaderParserTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Util;

use App\Service\Util\EmailHeaderParser;
use PHPUnit\Framework\TestCase;

final class EmailHeaderParserTest extends TestCase
{
    public function testExtractMessageIdReturnsNullOnEmptyString(): void
    {
        $this->assertNull(EmailHeaderParser::extractMessageId(''));
    }

    public function testExtractMessageIdStripsAngleBrackets(): void
    {
        $this->assertSame(
            'CAEPj=abc123@mail.gmail.com',
            EmailHeaderParser::extractMessageId('<CAEPj=abc123@mail.gmail.com>'),
        );
    }

    public function testExtractMessageIdTrimsWhitespace(): void
    {
        $this->assertSame(
            'CAEPj=abc123@mail.gmail.com',
            EmailHeaderParser::extractMessageId("   <CAEPj=abc123@mail.gmail.com>   \r\n"),
        );
    }

    public function testExtractMessageIdAcceptsRawIdWithoutBrackets(): void
    {
        $this->assertSame(
            'plain-id@example.com',
            EmailHeaderParser::extractMessageId('plain-id@example.com'),
        );
    }

    public function testExtractMessageIdReturnsNullWhenOnlyWhitespace(): void
    {
        $this->assertNull(EmailHeaderParser::extractMessageId("   \r\n   "));
    }
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Service/Util/EmailHeaderParserTest.php`
Expected: 5 failures with "method `extractMessageId` not found" or similar.

- [ ] **Step 3: Implement the helper**

Add to `src/Service/Util/EmailHeaderParser.php`:

```php
public static function extractMessageId(string $raw): ?string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return null;
    }
    // Strip surrounding angle brackets if present.
    if (str_starts_with($trimmed, '<') && str_ends_with($trimmed, '>')) {
        $trimmed = substr($trimmed, 1, -1);
    }
    $trimmed = trim($trimmed);
    return $trimmed === '' ? null : $trimmed;
}
```

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Util/EmailHeaderParserTest.php`
Expected: 5 passed, 0 failures.

### Task 1.3: `GmailService::parseMessage` extracts RFC headers

**Files:**
- Modify: `src/Service/GmailService.php` (around line 309–377, the `parseMessage` method)
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/TestCase/Service/GmailServiceTest.php`:

```php
public function testParseMessageExtractsRfcThreadingHeaders(): void
{
    $service = $this->buildService();

    // Compose a Gmail API JSON body for messages.get with three threading headers.
    $payload = [
        'id' => 'gmail-id-1',
        'threadId' => 'thread-1',
        'historyId' => '999',
        'payload' => [
            'headers' => [
                ['name' => 'Message-ID', 'value' => '<root@example.com>'],
                ['name' => 'In-Reply-To', 'value' => '<previous@example.com>'],
                ['name' => 'References', 'value' => '<a@x.com> <b@x.com> <previous@example.com>'],
                ['name' => 'From', 'value' => 'Alice <alice@example.com>'],
                ['name' => 'Subject', 'value' => 'Test thread'],
            ],
            'mimeType' => 'text/plain',
            'body' => ['data' => rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')],
        ],
    ];

    $this->stubHttp($service, [new Response(200, [], json_encode($payload))]);

    $data = $service->parseMessage('gmail-id-1');

    $this->assertSame('root@example.com', $data['rfc_message_id']);
    $this->assertSame('previous@example.com', $data['in_reply_to']);
    $this->assertSame('<a@x.com> <b@x.com> <previous@example.com>', $data['references_header']);
}
```

- [ ] **Step 2: Run test and verify it fails**

Run: `vendor/bin/phpunit --filter testParseMessageExtractsRfcThreadingHeaders`
Expected: FAIL — `rfc_message_id` key undefined on `$data`.

- [ ] **Step 3: Extend `parseMessage`**

Inside `src/Service/GmailService.php::parseMessage`, after the existing header extraction (where `subject`, `from`, etc. are pulled via `$this->getHeader($headers, ...)`), add:

```php
$rfcMessageId = EmailHeaderParser::extractMessageId($this->getHeader($headers, 'Message-ID'));
$inReplyTo = EmailHeaderParser::extractMessageId($this->getHeader($headers, 'In-Reply-To'));
$referencesRaw = $this->getHeader($headers, 'References');
$referencesHeader = trim($referencesRaw) !== '' ? trim($referencesRaw) : null;
```

Then extend the returned array literal with:

```php
'rfc_message_id' => $rfcMessageId,
'in_reply_to' => $inReplyTo,
'references_header' => $referencesHeader,
```

Add `use App\Service\Util\EmailHeaderParser;` at the top if not already present.

- [ ] **Step 4: Run test and verify it passes**

Run: `vendor/bin/phpunit --filter testParseMessageExtractsRfcThreadingHeaders`
Expected: PASS.

- [ ] **Step 5: Run the full GmailServiceTest suite to verify no regression**

Run: `vendor/bin/phpunit tests/TestCase/Service/GmailServiceTest.php`
Expected: all previously passing tests still pass; the new test passes.

### Task 1.4: Extend `Ticket::fromEmailIngest` signature

**Files:**
- Modify: `src/Model/Entity/Ticket.php` (line 278–305)

- [ ] **Step 1: Extend the signature and body**

Replace the existing `fromEmailIngest` block (around line 278–305) with:

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
    mixed $emailTo = null,
    mixed $emailCc = null,
    ?string $rfcMessageId = null,
    ?string $inReplyTo = null,
    ?string $referencesHeader = null,
): self {
    $ticket = new self();
    $ticket->ticket_number = $ticketNumber;
    $ticket->gmail_message_id = $gmailMessageId;
    $ticket->gmail_thread_id = $gmailThreadId;
    $ticket->subject = $subject !== '' ? $subject : '(Sin asunto)';
    $ticket->description = $sanitizedDescription;
    $ticket->status = TicketConstants::STATUS_NUEVO;
    $ticket->priority = TicketConstants::PRIORITY_MEDIA;
    $ticket->requester_id = $requesterId;
    $ticket->channel = $channel;
    $ticket->source_email = $sourceEmail;
    $ticket->email_to = $emailTo;
    $ticket->email_cc = $emailCc;
    $ticket->rfc_message_id = $rfcMessageId;
    $ticket->in_reply_to = $inReplyTo;
    $ticket->references_header = $referencesHeader;

    return $ticket;
}
```

- [ ] **Step 2: Run all tests to verify no consumers break**

Run: `composer test`
Expected: all previously-passing tests still pass (the new params are optional). The 7 baseline failures remain unchanged.

### Task 1.5: Add `TicketConstants::THREAD_REATTACH_WINDOW_DAYS`

**Files:**
- Modify: `src/Constants/TicketConstants.php`

- [ ] **Step 1: Add the constant**

Add inside `TicketConstants`:

```php
/**
 * Window in days during which an incoming reply with a matching RFC 5322
 * In-Reply-To / References header reattaches to an existing ticket. Outside
 * this window, the reply creates a new ticket — preventing resurrection of
 * ancient closed threads. Configurable later via SettingKeys if needed.
 */
public const THREAD_REATTACH_WINDOW_DAYS = 90;
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: no regressions.

### Task 1.6: `TicketIngestionService::findExistingTicketByThreading`

**Files:**
- Modify: `src/Service/TicketIngestionService.php`

This task implements the core M-4 lookup. The audit codebase does not unit-test DB-dependent paths (per `tests/bootstrap.php`); validation of the actual lookup is done via the smoke test in §8 of the spec.

- [ ] **Step 1: Add `parseReferences` and `findExistingTicketByThreading` helpers**

Add three private methods to `TicketIngestionService`:

```php
/**
 * Split a raw References: header into a list of message-id values
 * (angle brackets stripped, empty entries removed).
 *
 * @return list<string>
 */
private function parseReferences(string $raw): array
{
    $tokens = preg_split('/\s+/', trim($raw)) ?: [];
    $result = [];
    foreach ($tokens as $token) {
        $id = \App\Service\Util\EmailHeaderParser::extractMessageId($token);
        if ($id !== null) {
            $result[] = $id;
        }
    }
    return $result;
}

private function withinReattachWindow(Ticket $ticket): bool
{
    $modified = $ticket->modified ?? $ticket->created;
    if ($modified === null) {
        return false;
    }
    $cutoff = \Cake\I18n\DateTime::now()->subDays(TicketConstants::THREAD_REATTACH_WINDOW_DAYS);
    // Closed tickets must additionally have activity inside the window; if both
    // condition fail-shut, return false to force a new ticket.
    if ($ticket->isResolved() && $modified->lessThan($cutoff)) {
        return false;
    }
    return $modified->greaterThanOrEquals($cutoff);
}

private function lookupTicketByRfc(string $rfcId): ?Ticket
{
    // Most reattachments target a comment (latest thread participant).
    $comment = $this->fetchTable('TicketComments')->find()
        ->where(['rfc_message_id' => $rfcId])
        ->order(['id' => 'DESC'])
        ->first();
    if ($comment !== null) {
        return $this->fetchTable('Tickets')->find()
            ->where(['id' => $comment->ticket_id])
            ->first();
    }

    return $this->fetchTable('Tickets')->find()
        ->where(['rfc_message_id' => $rfcId])
        ->first();
}

public function findExistingTicketByThreading(array $emailData): ?Ticket
{
    $inReplyTo = $emailData['in_reply_to'] ?? null;
    if (is_string($inReplyTo) && $inReplyTo !== '') {
        $ticket = $this->lookupTicketByRfc($inReplyTo);
        if ($ticket !== null && $this->withinReattachWindow($ticket)) {
            return $ticket;
        }
    }

    $references = $emailData['references_header'] ?? null;
    if (is_string($references) && $references !== '') {
        foreach (array_reverse($this->parseReferences($references)) as $candidate) {
            $ticket = $this->lookupTicketByRfc($candidate);
            if ($ticket !== null && $this->withinReattachWindow($ticket)) {
                return $ticket;
            }
        }
    }

    $threadId = $emailData['gmail_thread_id'] ?? null;
    if (is_string($threadId) && $threadId !== '') {
        return $this->fetchTable('Tickets')->find()
            ->where(['gmail_thread_id' => $threadId])
            ->first();
    }

    return null;
}
```

- [ ] **Step 2: Persist the three new columns on ticket creation**

In `createFromEmail` (around line 107–118), change the `Ticket::fromEmailIngest(...)` call to also pass the three new keys:

```php
$ticket = Ticket::fromEmailIngest(
    ticketNumber: $ticketNumber,
    requesterId: (int)$user->id,
    subject: $subject,
    sanitizedDescription: $description,
    channel: $channel,
    sourceEmail: $fromEmail,
    gmailMessageId: $emailData['gmail_message_id'] ?? null,
    gmailThreadId: $emailData['gmail_thread_id'] ?? null,
    emailTo: !empty($emailData['email_to']) ? $emailData['email_to'] : null,
    emailCc: !empty($emailData['email_cc']) ? $emailData['email_cc'] : null,
    rfcMessageId: $emailData['rfc_message_id'] ?? null,
    inReplyTo: $emailData['in_reply_to'] ?? null,
    referencesHeader: $emailData['references_header'] ?? null,
);
```

- [ ] **Step 3: Persist the three new columns on comment creation**

In `createCommentFromEmail` (around line 208–220), extend the `newEntity()` payload and the `accessibleFields` whitelist:

```php
$comment = $ticketCommentsTable->newEntity([
    'ticket_id' => $ticket->id,
    'user_id' => $user->id,
    'body' => $body,
    'comment_type' => TicketConstants::COMMENT_PUBLIC,
    'is_system_comment' => false,
    'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
    'sent_as_email' => false,
    'email_to' => !empty($emailData['email_to']) ? json_encode($emailData['email_to']) : null,
    'email_cc' => !empty($emailData['email_cc']) ? json_encode($emailData['email_cc']) : null,
    'rfc_message_id' => $emailData['rfc_message_id'] ?? null,
    'in_reply_to' => $emailData['in_reply_to'] ?? null,
    'references_header' => $emailData['references_header'] ?? null,
], ['accessibleFields' => [
    'user_id' => true,
    'is_system_comment' => true,
    'gmail_message_id' => true,
    'sent_as_email' => true,
    'rfc_message_id' => true,
    'in_reply_to' => true,
    'references_header' => true,
]]);
```

- [ ] **Step 4: Run the full test suite**

Run: `composer test`
Expected: existing tests still pass (the changes are additive); 7 baseline failures remain.

### Task 1.7: Wire orchestrator to the new lookup

**Files:**
- Modify: `src/Service/GmailImportService.php` (line 132–137 inline thread_id lookup)

- [ ] **Step 1: Replace the inline lookup**

Replace this block in `GmailImportService::run`:

```php
$existingTicket = null;
if (!empty($emailData['gmail_thread_id'])) {
    $existingTicket = $ticketsTable->find()
        ->where(['gmail_thread_id' => $emailData['gmail_thread_id']])
        ->first();
}
```

With:

```php
$existingTicket = $this->tickets->findExistingTicketByThreading($emailData);
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: no regressions.

### Task 1.8: Style + static analysis gate

- [ ] **Step 1: Run code style check**

Run: `composer cs-check`
Expected: no new warnings/errors versus baseline. Fix with `composer cs-fix` if needed and re-run.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse src`
Expected: 38 baseline errors, none new in touched files (`EmailHeaderParser.php`, `GmailService.php`, `Ticket.php`, `TicketConstants.php`, `TicketIngestionService.php`, `GmailImportService.php`).

### Task 1.9: Commit M-4

- [ ] **Step 1: Stage all M-4 files**

Run:

```bash
git add config/Migrations/20260518120000_AddRfcThreadingToTickets.php \
        src/Service/Util/EmailHeaderParser.php \
        src/Service/GmailService.php \
        src/Model/Entity/Ticket.php \
        src/Constants/TicketConstants.php \
        src/Service/TicketIngestionService.php \
        src/Service/GmailImportService.php \
        tests/TestCase/Service/Util/EmailHeaderParserTest.php \
        tests/TestCase/Service/GmailServiceTest.php
```

- [ ] **Step 2: Commit**

```bash
git commit -m "$(cat <<'EOF'
feat(gmail): persist RFC 5322 threading headers (M-4)

parseMessage now extracts Message-ID, In-Reply-To, and References from
inbound mail. Tickets and ticket_comments gain three new nullable columns
plus a per-table index on rfc_message_id. TicketIngestionService gets
findExistingTicketByThreading(), used by GmailImportService in place of
the previous gmail_thread_id-only lookup. RFC matches require activity
inside TicketConstants::THREAD_REATTACH_WINDOW_DAYS (default 90 days) to
prevent resurrection of ancient closed tickets.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

# PHASE 2 — Commit 2 — M-5 · markAsRead retry queue

### Task 2.1: Migration for `gmail_mark_read_pending`

**Files:**
- Create: `config/Migrations/20260518120100_CreateGmailMarkReadPending.php`

- [ ] **Step 1: Write the migration**

```php
<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

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

- [ ] **Step 2: Apply migration to a scratch DB**

Run: `bin/cake migrations migrate`
Expected: `DESCRIBE gmail_mark_read_pending` shows the expected schema; index `uniq_mark_read_message_id` exists.

### Task 2.2: Table class

**Files:**
- Create: `src/Model/Table/GmailMarkReadPendingTable.php`

- [ ] **Step 1: Write the Table class**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Transient operational queue of Gmail message IDs whose markAsRead() call
 * failed during ingestion. Drained at the start of each GmailImportService
 * run; not domain data, not audited.
 */
class GmailMarkReadPendingTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('gmail_mark_read_pending');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: no regressions; the class is just registered (no consumers yet).

### Task 2.3: `MarkReadQueueService` — design for testability

**Files:**
- Create: `src/Service/Gmail/MarkReadQueueService.php`
- Create: `tests/TestCase/Service/Gmail/MarkReadQueueServiceTest.php`

The service receives the Table as a constructor argument (rather than via `LocatorAwareTrait`) so unit tests can inject a mocked Table.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Gmail\MarkReadQueueService;
use App\Service\GmailService;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use PHPUnit\Framework\TestCase;

final class MarkReadQueueServiceTest extends TestCase
{
    private function makeTable(array $rows = []): Table
    {
        // Anonymous class to avoid the LocatorAwareTrait fixture machinery.
        return new class ($rows) extends Table {
            /** @var array<int, object> */
            public array $rows;
            /** @var list<array{op:string, payload:mixed}> */
            public array $log = [];

            public function __construct(array $rows)
            {
                // No parent::__construct — we don't need CakePHP's full Table init.
                $this->rows = array_map(static function (array $r): object {
                    $entity = new \stdClass();
                    foreach ($r as $k => $v) {
                        $entity->{$k} = $v;
                    }
                    $entity->id = $entity->id ?? random_int(1, 100000);
                    return $entity;
                }, $rows);
            }

            public function find(string $type = 'all', mixed ...$args): SelectQuery
            {
                throw new \LogicException('Use the test-specific helpers instead of find().');
            }

            public function selectAllForTest(): array
            {
                return $this->rows;
            }

            public function saveOrFail(\Cake\Datasource\EntityInterface|object $entity, array $options = []): object
            {
                $this->log[] = ['op' => 'save', 'payload' => $entity];
                return $entity;
            }

            public function delete(\Cake\Datasource\EntityInterface|object $entity, array $options = []): bool
            {
                $this->log[] = ['op' => 'delete', 'payload' => $entity];
                $this->rows = array_values(array_filter(
                    $this->rows,
                    static fn (object $r) => $r->id !== $entity->id,
                ));
                return true;
            }
        };
    }

    public function testEnqueueInsertsNewRowWhenMessageIdAbsent(): void
    {
        $table = $this->makeTable();
        $service = new MarkReadQueueService($table);

        $service->enqueue('msg-1', 'transient blip', GmailErrorCategory::TRANSIENT);

        $this->assertCount(1, $table->log);
        $entity = $table->log[0]['payload'];
        $this->assertSame('msg-1', $entity->gmail_message_id);
        $this->assertSame(1, $entity->attempts);
        $this->assertSame('transient blip', $entity->last_error);
        $this->assertSame(GmailErrorCategory::TRANSIENT, $entity->last_category);
    }

    public function testEnqueueIncrementsAttemptsOnDuplicate(): void
    {
        $existing = (object)[
            'id' => 7,
            'gmail_message_id' => 'msg-1',
            'attempts' => 1,
            'last_error' => 'old',
            'last_category' => GmailErrorCategory::TRANSIENT,
        ];
        $table = $this->makeTable();
        $table->rows = [$existing];
        $service = new MarkReadQueueService($table);

        $service->enqueue('msg-1', 'second blip', GmailErrorCategory::TRANSIENT);

        $this->assertSame(2, $existing->attempts);
        $this->assertSame('second blip', $existing->last_error);
    }

    public function testProcessPendingMarksAndDeletesOnSuccess(): void
    {
        $table = $this->makeTable([
            ['gmail_message_id' => 'msg-1', 'attempts' => 1, 'last_error' => null, 'last_category' => null],
        ]);
        $gmail = $this->createMock(GmailService::class);
        $gmail->expects($this->once())
            ->method('markAsRead')
            ->with('msg-1')
            ->willReturn(true);

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        $this->assertSame(['processed' => 1, 'retried' => 1, 'failed' => 0, 'dropped' => 0], $counters);
        $this->assertCount(0, $table->rows);
    }

    public function testProcessPendingDropsOnPermanentCategory(): void
    {
        $table = $this->makeTable([
            ['gmail_message_id' => 'msg-1', 'attempts' => 1, 'last_error' => null, 'last_category' => null],
        ]);
        $gmail = $this->createMock(GmailService::class);
        $gmail->method('markAsRead')->willThrowException(
            new GmailApiException('not found', GmailErrorCategory::PERMANENT),
        );

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        $this->assertSame(1, $counters['dropped']);
        $this->assertSame(0, $counters['retried']);
        $this->assertCount(0, $table->rows);
    }

    public function testProcessPendingIncrementsAttemptsOnTransientFailure(): void
    {
        $row = (object)['id' => 1, 'gmail_message_id' => 'msg-1', 'attempts' => 1, 'last_error' => null, 'last_category' => null];
        $table = $this->makeTable();
        $table->rows = [$row];

        $gmail = $this->createMock(GmailService::class);
        $gmail->method('markAsRead')->willThrowException(
            new GmailApiException('rate limited', GmailErrorCategory::RATE),
        );

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        $this->assertSame(0, $counters['dropped']);
        $this->assertSame(1, $counters['failed']);
        $this->assertSame(2, $row->attempts);
        $this->assertCount(1, $table->rows);
    }

    public function testProcessPendingDropsAfterMaxAttempts(): void
    {
        $row = (object)['id' => 1, 'gmail_message_id' => 'msg-1', 'attempts' => 2, 'last_error' => null, 'last_category' => null];
        $table = $this->makeTable();
        $table->rows = [$row];

        $gmail = $this->createMock(GmailService::class);
        $gmail->method('markAsRead')->willThrowException(
            new GmailApiException('still failing', GmailErrorCategory::TRANSIENT),
        );

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail);

        // attempts was 2; this run increments to 3 (MAX_ATTEMPTS), so drop.
        $this->assertSame(1, $counters['dropped']);
        $this->assertSame(0, $counters['failed']);
        $this->assertCount(0, $table->rows);
    }

    public function testProcessPendingHonorsBatchSize(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['gmail_message_id' => "msg-{$i}", 'attempts' => 0, 'last_error' => null, 'last_category' => null];
        }
        $table = $this->makeTable($rows);

        $gmail = $this->createMock(GmailService::class);
        $gmail->expects($this->exactly(2))->method('markAsRead')->willReturn(true);

        $service = new MarkReadQueueService($table);
        $counters = $service->processPending($gmail, batch: 2);

        $this->assertSame(2, $counters['processed']);
        $this->assertCount(3, $table->rows);
    }
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/MarkReadQueueServiceTest.php`
Expected: fail with "class MarkReadQueueService not found".

- [ ] **Step 3: Implement `MarkReadQueueService`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use App\Service\Exception\GmailApiException;
use App\Service\GmailService;
use Cake\Log\Log;
use Cake\ORM\Table;

/**
 * Retry queue for Gmail::markAsRead failures.
 *
 * Drained at the start of every GmailImportService::run(). Failures during
 * the run are enqueued; success/permanent failures delete the row; transient
 * failures increment attempts up to MAX_ATTEMPTS, then drop with a log.
 */
final class MarkReadQueueService
{
    public const MAX_ATTEMPTS = 3;
    public const DEFAULT_BATCH = 20;

    public function __construct(private readonly Table $table)
    {
    }

    public function enqueue(string $gmailMessageId, ?string $error, string $category): void
    {
        $existing = $this->findByMessageId($gmailMessageId);
        if ($existing !== null) {
            $existing->attempts = ((int)($existing->attempts ?? 0)) + 1;
            $existing->last_error = $this->truncateError($error);
            $existing->last_category = $category;
            $this->table->saveOrFail($existing);
            return;
        }

        // The seam (property_exists 'rows') distinguishes the test anonymous
        // Table from a real Cake ORM Table. Real Tables go through newEntity()
        // so persistence wires the entity to the source. Tests use a bare
        // stdClass — sufficient because the test harness overrides saveOrFail.
        $row = property_exists($this->table, 'rows')
            ? new \stdClass()
            : $this->table->newEntity([]);
        $row->gmail_message_id = $gmailMessageId;
        $row->attempts = 1;
        $row->last_error = $this->truncateError($error);
        $row->last_category = $category;
        $this->table->saveOrFail($row);
    }

    /**
     * @return array{processed:int, retried:int, failed:int, dropped:int}
     */
    public function processPending(GmailService $gmail, int $batch = self::DEFAULT_BATCH): array
    {
        $rows = $this->selectPending($batch);

        $processed = 0;
        $retried = 0;
        $failed = 0;
        $dropped = 0;

        foreach ($rows as $row) {
            try {
                $gmail->markAsRead($row->gmail_message_id);
                $this->table->delete($row);
                $processed++;
                $retried++;
            } catch (GmailApiException $e) {
                if ($e->getCategory() === GmailErrorCategory::PERMANENT) {
                    $this->table->delete($row);
                    $dropped++;
                    Log::info('Gmail mark-read dropped (permanent)', [
                        'message_id' => $row->gmail_message_id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $row->attempts = ((int)($row->attempts ?? 0)) + 1;
                $row->last_error = $this->truncateError($e->getMessage());
                $row->last_category = $e->getCategory();
                if ($row->attempts >= self::MAX_ATTEMPTS) {
                    $this->table->delete($row);
                    $dropped++;
                    Log::warning('Gmail mark-read dropped after max attempts', [
                        'message_id' => $row->gmail_message_id,
                        'attempts' => $row->attempts,
                    ]);
                } else {
                    $this->table->saveOrFail($row);
                    $failed++;
                }
            }
        }

        return ['processed' => $processed, 'retried' => $retried, 'failed' => $failed, 'dropped' => $dropped];
    }

    private function findByMessageId(string $gmailMessageId): ?object
    {
        // Production path uses ORM; the test-only anonymous Table holds rows in a public array.
        if (property_exists($this->table, 'rows')) {
            foreach ($this->table->rows as $row) {
                if (($row->gmail_message_id ?? null) === $gmailMessageId) {
                    return $row;
                }
            }
            return null;
        }
        $found = $this->table->find()->where(['gmail_message_id' => $gmailMessageId])->first();
        return $found instanceof \Cake\Datasource\EntityInterface ? $found : null;
    }

    /**
     * @return list<object>
     */
    private function selectPending(int $batch): array
    {
        if (method_exists($this->table, 'selectAllForTest')) {
            return array_slice($this->table->selectAllForTest(), 0, $batch);
        }
        $iter = $this->table->find()
            ->order(['created' => 'ASC'])
            ->limit($batch)
            ->all();
        $rows = [];
        foreach ($iter as $entity) {
            $rows[] = $entity;
        }
        return $rows;
    }

    private function truncateError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }
        return mb_substr($error, 0, 255);
    }
}
```

Note: the small dual-path (`property_exists($this->table, 'rows')` / `method_exists($this->table, 'selectAllForTest')`) is the seam that lets the pure-unit test harness drive the service without booting a real ORM Table. This costs ~6 lines and saves us the fixture-DB infrastructure the rest of the codebase explicitly forbids (per `tests/bootstrap.php`).

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/MarkReadQueueServiceTest.php`
Expected: 7 passed.

### Task 2.4: Extend `GmailImportResult` with mark-read counters

**Files:**
- Modify: `src/Service/Dto/GmailImportResult.php`
- Modify: `tests/TestCase/Service/Dto/GmailImportResultTest.php`

- [ ] **Step 1: Add the failing test**

Append to `GmailImportResultTest`:

```php
public function testToArrayIncludesMarkReadCounters(): void
{
    $result = new GmailImportResult(
        fetched: 1,
        created: 1,
        comments: 0,
        skipped: 0,
        errors: 0,
        durationSeconds: 0.1,
        markReadRetried: 2,
        markReadDropped: 1,
        markReadEnqueued: 3,
    );

    $array = $result->toArray();

    $this->assertSame(2, $array['mark_read_retried']);
    $this->assertSame(1, $array['mark_read_dropped']);
    $this->assertSame(3, $array['mark_read_enqueued']);
}

public function testMarkReadCountersDefaultToZero(): void
{
    $result = new GmailImportResult(
        fetched: 0, created: 0, comments: 0, skipped: 0, errors: 0, durationSeconds: 0.0,
    );

    $this->assertSame(0, $result->markReadRetried);
    $this->assertSame(0, $result->markReadDropped);
    $this->assertSame(0, $result->markReadEnqueued);
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit --filter MarkRead tests/TestCase/Service/Dto/GmailImportResultTest.php`
Expected: 2 failures (unknown properties).

- [ ] **Step 3: Extend the DTO**

In `src/Service/Dto/GmailImportResult.php`, add the three params at the end of `__construct` and the three keys in `toArray()`:

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
    public int $markReadRetried = 0,
    public int $markReadDropped = 0,
    public int $markReadEnqueued = 0,
) {
}
```

```php
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
        'mark_read_retried' => $this->markReadRetried,
        'mark_read_dropped' => $this->markReadDropped,
        'mark_read_enqueued' => $this->markReadEnqueued,
    ];
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/GmailImportResultTest.php`
Expected: all tests pass (original 3 + new 2).

### Task 2.5: Wire `MarkReadQueueService` into `GmailImportService`

**Files:**
- Modify: `src/Service/GmailImportService.php`

- [ ] **Step 1: Update constructor + `fromSettings()` factory**

Replace the constructor and `fromSettings()` with:

```php
public function __construct(
    private readonly GmailService $gmail,
    private readonly TicketIngestionService $tickets,
    private readonly MarkReadQueueService $markReadQueue,
) {
}

public static function fromSettings(): self
{
    $config = GmailService::loadConfigFromDatabase();
    if (empty($config['refresh_token'])) {
        throw GmailNotConfiguredException::missingRefreshToken();
    }

    $locator = new \Cake\ORM\Locator\TableLocator();

    return new self(
        new GmailService($config),
        new TicketIngestionService(SystemConfig::fromSettingsArray(self::loadSystemSettings())),
        new MarkReadQueueService($locator->get('GmailMarkReadPending')),
    );
}
```

Add `use App\Service\Gmail\MarkReadQueueService;` to the use-block.

- [ ] **Step 2: Drain pending at the start of `run()`**

At the top of `run()`, immediately after `$startedAt = microtime(true);` and before the `$max = max(...)` line, add:

```php
$markReadCounters = $this->markReadQueue->processPending($this->gmail);
```

- [ ] **Step 3: Wrap every `markAsRead` call site**

The four call sites currently in `run()`:

```php
$this->gmail->markAsRead($messageId);
```

Replace each with:

```php
try {
    $this->gmail->markAsRead($messageId);
} catch (\App\Service\Exception\GmailApiException $e) {
    $this->markReadQueue->enqueue($messageId, $e->getMessage(), $e->getCategory());
    $markReadCounters['failed'] = ($markReadCounters['failed'] ?? 0) + 1;
}
```

The four locations are:
1. Auto-reply branch (line ~116).
2. System-notification branch (line ~122).
3. Post-comment branch (line ~146).
4. Post-create branch (line ~151).

- [ ] **Step 4: Populate the new counters in the returned `GmailImportResult`**

Update the final `new GmailImportResult(...)` call so the constructor receives:

```php
markReadRetried: $markReadCounters['retried'],
markReadDropped: $markReadCounters['dropped'],
markReadEnqueued: $markReadCounters['failed'],
```

(Note: `failed` in the queue counters maps to `enqueued` in the result — i.e. failures during the current run that got pushed to the queue.)

Also update the early-return `new GmailImportResult(...)` block (the `$fetched === 0` branch around line 80–87) so it forwards the drain counters too:

```php
return new GmailImportResult(
    fetched: 0,
    created: 0,
    comments: 0,
    skipped: 0,
    errors: 0,
    durationSeconds: microtime(true) - $startedAt,
    markReadRetried: $markReadCounters['retried'],
    markReadDropped: $markReadCounters['dropped'],
    markReadEnqueued: 0,
);
```

- [ ] **Step 5: Run all tests**

Run: `composer test`
Expected: no regressions; the new path is exercised indirectly by `GmailImportResultTest`.

### Task 2.6: Style + static analysis gate

- [ ] **Step 1: Run code style check**

Run: `composer cs-check`
Expected: clean. Fix with `composer cs-fix` if needed.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse src`
Expected: no new errors versus baseline.

### Task 2.7: Commit M-5

- [ ] **Step 1: Stage all M-5 files**

```bash
git add config/Migrations/20260518120100_CreateGmailMarkReadPending.php \
        src/Model/Table/GmailMarkReadPendingTable.php \
        src/Service/Gmail/MarkReadQueueService.php \
        src/Service/Dto/GmailImportResult.php \
        src/Service/GmailImportService.php \
        tests/TestCase/Service/Gmail/MarkReadQueueServiceTest.php \
        tests/TestCase/Service/Dto/GmailImportResultTest.php
```

- [ ] **Step 2: Commit**

```bash
git commit -m "$(cat <<'EOF'
feat(gmail): retry queue for markAsRead failures (M-5)

New gmail_mark_read_pending table backs MarkReadQueueService, drained at
the start of every GmailImportService::run(). Failures during the run are
enqueued instead of silently logged; permanent failures (404) drop
immediately; transient failures retry up to MAX_ATTEMPTS=3. Result DTO
exposes mark_read_retried / mark_read_dropped / mark_read_enqueued for
operator visibility.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

# PHASE 3 — Commit 3 — M-2 · `history.list` checkpoint polling

### Task 3.1: Setting key

**Files:**
- Modify: `src/Constants/SettingKeys.php`

- [ ] **Step 1: Add the constant**

In the `GMAIL_*` block of `SettingKeys`:

```php
public const GMAIL_LAST_HISTORY_ID = 'gmail_last_history_id';
```

Do **not** add this to `USER_EDITABLE_KEYS` — it is operational state, not configuration.

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: no regressions.

### Task 3.2: `HistoryMode` final class

**Files:**
- Create: `src/Service/Gmail/HistoryMode.php`
- Create: `tests/TestCase/Service/Gmail/HistoryModeTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Gmail\HistoryMode;
use PHPUnit\Framework\TestCase;

final class HistoryModeTest extends TestCase
{
    public function testFourDistinctConstants(): void
    {
        $values = [
            HistoryMode::BOOTSTRAP,
            HistoryMode::DELTA,
            HistoryMode::FULL_SYNC_FALLBACK,
            HistoryMode::MANUAL_OVERRIDE,
        ];

        $this->assertSame($values, array_unique($values));
        $this->assertCount(4, $values);
    }

    public function testConstantsAreShortLowerSnake(): void
    {
        // Used directly in GmailImportResult::toArray()['history_mode'], so
        // they should be readable in JSON without further mapping.
        $this->assertSame('bootstrap', HistoryMode::BOOTSTRAP);
        $this->assertSame('delta', HistoryMode::DELTA);
        $this->assertSame('full_sync_fallback', HistoryMode::FULL_SYNC_FALLBACK);
        $this->assertSame('manual_override', HistoryMode::MANUAL_OVERRIDE);
    }
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/HistoryModeTest.php`
Expected: class not found.

- [ ] **Step 3: Implement `HistoryMode`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Gmail;

/**
 * Modes reported by GmailImportService::run() in GmailImportResult::historyMode.
 *
 * - BOOTSTRAP: no checkpoint existed; full sync ran and checkpoint was written.
 * - DELTA: checkpoint present; users.history.list returned the delta.
 * - FULL_SYNC_FALLBACK: checkpoint present but Gmail returned 404; full sync re-ran.
 * - MANUAL_OVERRIDE: CLI override supplied a query string; checkpoint untouched.
 */
final class HistoryMode
{
    public const BOOTSTRAP = 'bootstrap';
    public const DELTA = 'delta';
    public const FULL_SYNC_FALLBACK = 'full_sync_fallback';
    public const MANUAL_OVERRIDE = 'manual_override';

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Gmail/HistoryModeTest.php`
Expected: 2 passed.

### Task 3.3: `GmailService::getProfileHistoryId`

**Files:**
- Modify: `src/Service/GmailService.php`
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `GmailServiceTest`:

```php
public function testGetProfileHistoryIdReturnsString(): void
{
    $service = $this->buildService();
    $this->stubHttp($service, [new Response(
        200,
        [],
        json_encode(['emailAddress' => 'user@example.com', 'historyId' => '12345']),
    )]);

    $this->assertSame('12345', $service->getProfileHistoryId());
}

public function testGetProfileHistoryIdThrowsOnEmptyValue(): void
{
    $service = $this->buildService();
    $this->stubHttp($service, [new Response(
        200,
        [],
        json_encode(['emailAddress' => 'user@example.com']),
    )]);

    $this->expectException(GmailApiException::class);
    $service->getProfileHistoryId();
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit --filter GetProfileHistoryId tests/TestCase/Service/GmailServiceTest.php`
Expected: method not found.

- [ ] **Step 3: Implement the method**

Add to `GmailService`:

```php
/**
 * Returns the current historyId for the authenticated user's mailbox.
 * Used to bootstrap a fresh history.list checkpoint or to refresh one
 * after a 404 fallback.
 */
public function getProfileHistoryId(): string
{
    try {
        $profile = $this->getService()->users->getProfile('me');
    } catch (\Google\Service\Exception $e) {
        throw GmailApiException::wrap($e);
    }

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

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit --filter GetProfileHistoryId tests/TestCase/Service/GmailServiceTest.php`
Expected: 2 passed.

### Task 3.4: `GmailService::getHistoryDelta`

**Files:**
- Modify: `src/Service/GmailService.php`
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `GmailServiceTest`:

```php
public function testGetHistoryDeltaReturnsMessageIdsAcrossPages(): void
{
    $service = $this->buildService();

    $page1 = json_encode([
        'history' => [
            ['messagesAdded' => [['message' => ['id' => 'm-1']]]],
            ['messagesAdded' => [['message' => ['id' => 'm-2']]]],
        ],
        'nextPageToken' => 'tok-2',
    ]);
    $page2 = json_encode([
        'history' => [
            ['messagesAdded' => [['message' => ['id' => 'm-3']]]],
        ],
    ]);
    $this->stubHttp($service, [
        new Response(200, [], $page1),
        new Response(200, [], $page2),
    ]);

    $this->assertSame(['m-1', 'm-2', 'm-3'], $service->getHistoryDelta('1000'));
}

public function testGetHistoryDeltaReturnsNullOn404(): void
{
    $service = $this->buildService();
    $this->stubHttp($service, [new Response(404, [], json_encode(['error' => ['code' => 404]]))]);

    $this->assertNull($service->getHistoryDelta('1000'));
}

public function testGetHistoryDeltaWrapsOtherErrorsAsGmailApiException(): void
{
    $service = $this->buildService();
    $this->stubHttp($service, [new Response(500, [], json_encode(['error' => ['code' => 500]]))]);

    $this->expectException(GmailApiException::class);
    $service->getHistoryDelta('1000');
}

public function testGetHistoryDeltaDedupsRepeatedIds(): void
{
    $service = $this->buildService();
    $payload = json_encode([
        'history' => [
            ['messagesAdded' => [['message' => ['id' => 'm-1']], ['message' => ['id' => 'm-1']]]],
        ],
    ]);
    $this->stubHttp($service, [new Response(200, [], $payload)]);

    $this->assertSame(['m-1'], $service->getHistoryDelta('1000'));
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit --filter GetHistoryDelta tests/TestCase/Service/GmailServiceTest.php`
Expected: method not found.

- [ ] **Step 3: Implement `getHistoryDelta`**

Add to `GmailService`:

```php
/**
 * @return list<string>|null List of messageIds added since $startHistoryId,
 *                            or null when Gmail responds 404 (checkpoint too old).
 */
public function getHistoryDelta(string $startHistoryId): ?array
{
    $messageIds = [];
    $pageToken = null;

    do {
        $params = [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => ['messageAdded'],
        ];
        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = $this->getService()->users_history->listUsersHistory('me', $params);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw GmailApiException::wrap($e);
        }

        foreach ($response->getHistory() ?? [] as $history) {
            foreach ($history->getMessagesAdded() ?? [] as $added) {
                $msg = $added->getMessage();
                if ($msg !== null && $msg->getId() !== null) {
                    $messageIds[] = $msg->getId();
                }
            }
        }
        $pageToken = $response->getNextPageToken();
    } while (is_string($pageToken) && $pageToken !== '');

    return array_values(array_unique($messageIds));
}
```

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit --filter GetHistoryDelta tests/TestCase/Service/GmailServiceTest.php`
Expected: 4 passed.

### Task 3.5: `parseMessage` returns `gmail_history_id`

**Files:**
- Modify: `src/Service/GmailService.php`
- Modify: `tests/TestCase/Service/GmailServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `GmailServiceTest`:

```php
public function testParseMessageIncludesGmailHistoryId(): void
{
    $service = $this->buildService();
    $payload = json_encode([
        'id' => 'gmail-id-1',
        'threadId' => 'thread-1',
        'historyId' => '54321',
        'payload' => [
            'headers' => [
                ['name' => 'From', 'value' => 'Alice <alice@example.com>'],
                ['name' => 'Subject', 'value' => 'Test'],
            ],
            'mimeType' => 'text/plain',
            'body' => ['data' => rtrim(strtr(base64_encode('body'), '+/', '-_'), '=')],
        ],
    ]);
    $this->stubHttp($service, [new Response(200, [], $payload)]);

    $data = $service->parseMessage('gmail-id-1');

    $this->assertSame('54321', $data['gmail_history_id']);
}
```

- [ ] **Step 2: Run test and verify it fails**

Run: `vendor/bin/phpunit --filter ParseMessageIncludesGmailHistoryId`
Expected: undefined index.

- [ ] **Step 3: Extend `parseMessage`**

Inside `parseMessage`, where the returned array is constructed, add:

```php
'gmail_history_id' => (string)($message->getHistoryId() ?? ''),
```

(`$message` is the Gmail SDK `Message` object already fetched at the top of `parseMessage`.)

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit --filter ParseMessage tests/TestCase/Service/GmailServiceTest.php`
Expected: all `ParseMessage*` cases pass (including the M-4 case from Task 1.3).

### Task 3.6: Extend `GmailImportResult` with `historyMode` + `historyFallbacks`

**Files:**
- Modify: `src/Service/Dto/GmailImportResult.php`
- Modify: `tests/TestCase/Service/Dto/GmailImportResultTest.php`

- [ ] **Step 1: Write the failing test**

Append to `GmailImportResultTest`:

```php
public function testToArrayIncludesHistoryModeAndFallbacks(): void
{
    $result = new GmailImportResult(
        fetched: 0, created: 0, comments: 0, skipped: 0, errors: 0, durationSeconds: 0.0,
        historyMode: \App\Service\Gmail\HistoryMode::DELTA,
        historyFallbacks: 0,
    );

    $array = $result->toArray();

    $this->assertSame('delta', $array['history_mode']);
    $this->assertSame(0, $array['history_fallbacks']);
}

public function testHistoryModeDefaultsToBootstrap(): void
{
    $result = new GmailImportResult(
        fetched: 0, created: 0, comments: 0, skipped: 0, errors: 0, durationSeconds: 0.0,
    );

    $this->assertSame('bootstrap', $result->historyMode);
    $this->assertSame(0, $result->historyFallbacks);
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit --filter History tests/TestCase/Service/Dto/GmailImportResultTest.php`
Expected: failures.

- [ ] **Step 3: Extend the DTO**

In `src/Service/Dto/GmailImportResult.php`, extend the constructor signature and `toArray()`:

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
    public int $markReadRetried = 0,
    public int $markReadDropped = 0,
    public int $markReadEnqueued = 0,
    public string $historyMode = \App\Service\Gmail\HistoryMode::BOOTSTRAP,
    public int $historyFallbacks = 0,
) {
}
```

```php
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
        'mark_read_retried' => $this->markReadRetried,
        'mark_read_dropped' => $this->markReadDropped,
        'mark_read_enqueued' => $this->markReadEnqueued,
        'history_mode' => $this->historyMode,
        'history_fallbacks' => $this->historyFallbacks,
    ];
}
```

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/GmailImportResultTest.php`
Expected: all tests pass.

### Task 3.7: Restructure `GmailImportService::run()`

**Files:**
- Modify: `src/Service/GmailImportService.php`

This is the largest single change in P2. It rewrites the orchestration policy at the top of `run()` while leaving the per-message loop body intact.

- [ ] **Step 1: Add settings-checkpoint helpers**

Add two private methods to `GmailImportService`:

```php
private function readHistoryCheckpoint(): ?string
{
    $row = $this->fetchTable('SystemSettings')->find()
        ->where(['key' => SettingKeys::GMAIL_LAST_HISTORY_ID])
        ->first();
    if ($row === null) {
        return null;
    }
    $value = (string)($row->value ?? '');
    return $value === '' ? null : $value;
}

private function writeHistoryCheckpoint(string $historyId): void
{
    (new SettingsService())->saveSetting(SettingKeys::GMAIL_LAST_HISTORY_ID, $historyId);
}
```

Add `use App\Constants\SettingKeys;` and `use App\Service\Gmail\HistoryMode;` at the top.

- [ ] **Step 2: Update `run()` signature**

Change the signature from:

```php
public function run(int $max = 50, string $query = 'is:unread', int $delayMs = 0): GmailImportResult
```

To:

```php
public function run(int $max = 50, ?string $queryOverride = null, int $delayMs = 0): GmailImportResult
```

- [ ] **Step 3: Replace the top of `run()` with the checkpoint state machine**

Replace the block from `$startedAt = microtime(true);` through the existing `$messageIds = $this->gmail->getMessages(...)` line with:

```php
$startedAt = microtime(true);
$max = max(1, min($max, 200));

$markReadCounters = $this->markReadQueue->processPending($this->gmail);

$historyMode = HistoryMode::BOOTSTRAP;
$historyFallbacks = 0;
$touchCheckpoint = false;
$messageIds = [];

$lastHistoryId = $this->readHistoryCheckpoint();

if ($queryOverride !== null) {
    $historyMode = HistoryMode::MANUAL_OVERRIDE;
    $messageIds = $this->gmail->getMessages($queryOverride, $max);
} elseif ($lastHistoryId === null) {
    $historyMode = HistoryMode::BOOTSTRAP;
    try {
        $bootstrapHistoryId = $this->gmail->getProfileHistoryId();
        $this->writeHistoryCheckpoint($bootstrapHistoryId);
    } catch (\Throwable $e) {
        Log::warning('Gmail bootstrap historyId unavailable; falling back to unread polling', [
            'error' => $e->getMessage(),
        ]);
    }
    $messageIds = $this->gmail->getMessages('in:inbox newer_than:7d', $max);
} else {
    $delta = $this->gmail->getHistoryDelta($lastHistoryId);
    if ($delta === null) {
        $historyMode = HistoryMode::FULL_SYNC_FALLBACK;
        $historyFallbacks = 1;
        Log::warning('Gmail history.list returned 404, falling back to full sync', [
            'checkpoint' => $lastHistoryId,
        ]);
        try {
            $freshHistoryId = $this->gmail->getProfileHistoryId();
            $this->writeHistoryCheckpoint($freshHistoryId);
        } catch (\Throwable $e) {
            Log::warning('Gmail history fallback could not refresh checkpoint', [
                'error' => $e->getMessage(),
            ]);
        }
        $messageIds = $this->gmail->getMessages('in:inbox newer_than:7d', $max);
    } else {
        $historyMode = HistoryMode::DELTA;
        $messageIds = array_slice($delta, 0, $max);
        $touchCheckpoint = true;
    }
}

$fetched = count($messageIds);
```

- [ ] **Step 4: Track `maxHistoryIdSeen` during the per-message loop**

Just before the `foreach ($messageIds as $messageId)` loop, initialize:

```php
$maxHistoryIdSeen = $lastHistoryId ?? '0';
```

Inside the loop, right after `$emailData = $this->gmail->parseMessage($messageId);`, add:

```php
$thisHistoryId = (string)($emailData['gmail_history_id'] ?? '0');
if ($thisHistoryId !== '' && $this->compareHistoryIds($thisHistoryId, $maxHistoryIdSeen) > 0) {
    $maxHistoryIdSeen = $thisHistoryId;
}
```

And add this comparator helper to `GmailImportService` (history IDs are unsigned 64-bit integers expressed as decimal strings; PHP `int` on 32-bit systems would overflow — use string-compare with same length):

```php
/**
 * Compares two unsigned-integer-as-string historyIds. Returns -1/0/1.
 */
private function compareHistoryIds(string $a, string $b): int
{
    $a = ltrim($a, '0');
    $b = ltrim($b, '0');
    if (strlen($a) !== strlen($b)) {
        return strlen($a) <=> strlen($b);
    }
    return strcmp($a, $b);
}
```

- [ ] **Step 5: Persist the advancing checkpoint after the loop**

Just before the final `new GmailImportResult(...)` construction at the end of `run()`, add:

```php
if ($touchCheckpoint && $maxHistoryIdSeen !== ($lastHistoryId ?? '0')) {
    $this->writeHistoryCheckpoint($maxHistoryIdSeen);
}
```

- [ ] **Step 6: Populate new result fields**

Update the final `new GmailImportResult(...)` to pass:

```php
markReadRetried: $markReadCounters['retried'],
markReadDropped: $markReadCounters['dropped'],
markReadEnqueued: $markReadCounters['failed'] ?? 0,
historyMode: $historyMode,
historyFallbacks: $historyFallbacks,
```

Apply the same updates to the early `$fetched === 0` short-circuit `new GmailImportResult(...)` (the drain may still have happened with no new mail; `historyMode` is set from the state machine above the short-circuit, so it will be one of bootstrap/delta/full_sync_fallback/manual_override).

Important: the existing early short-circuit for `$fetched === 0` lives **before** the per-message loop. Move the `$fetched === 0` check to **after** the state machine so the result reports the correct `historyMode` even when no messages were returned. The early-return block becomes:

```php
if ($fetched === 0) {
    return new GmailImportResult(
        fetched: 0,
        created: 0,
        comments: 0,
        skipped: 0,
        errors: 0,
        durationSeconds: microtime(true) - $startedAt,
        markReadRetried: $markReadCounters['retried'],
        markReadDropped: $markReadCounters['dropped'],
        markReadEnqueued: 0,
        historyMode: $historyMode,
        historyFallbacks: $historyFallbacks,
    );
}
```

- [ ] **Step 7: Update `ImportGmailCommand` to use the new `$queryOverride` arg name**

**File:** `src/Command/ImportGmailCommand.php`

The command currently calls `run(int $max, string $query, int $delayMs)`. Search for any positional or named call site and pass the user-supplied query string (if any) as `queryOverride:`, or `null` if none was supplied. If the command always defaulted to `'is:unread'`, update it to pass `null` so the checkpoint logic runs.

Run: `grep -n "->run(" src/Command/ImportGmailCommand.php`
Expected: a single call site to update.

Update the call site to pass `queryOverride: $userSuppliedQuery ?? null` and ensure that when the operator does NOT pass `--query`, the checkpoint logic is used.

- [ ] **Step 8: Run the full test suite**

Run: `composer test`
Expected: no regressions. New behavior is covered indirectly via `GmailImportResultTest` and the new GmailService MockHandler tests; the integrated `run()` flow itself is verified by the smoke tests in §8 of the spec.

### Task 3.8: Pure predicate test for OAuth-cache invalidation policy

**Files:**
- Modify: `src/Service/SettingsService.php`
- Create: `tests/TestCase/Service/SettingsServiceTest.php`

The spec asserts that writing `GMAIL_LAST_HISTORY_ID` must **not** purge the OAuth cache. P0/M-1 made the purge conditional on specific setting names. The codebase forbids DB-backed unit tests (`tests/bootstrap.php`), so this task extracts the OAuth-cache decision into a pure static predicate that is unit-testable in isolation.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\SettingKeys;
use App\Service\SettingsService;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testKeyRequiresOAuthCachePurgeForClientSecret(): void
    {
        $this->assertTrue(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_CLIENT_SECRET_JSON));
    }

    public function testKeyRequiresOAuthCachePurgeForRefreshToken(): void
    {
        $this->assertTrue(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_REFRESH_TOKEN));
    }

    public function testKeyDoesNotRequirePurgeForHistoryCheckpoint(): void
    {
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_LAST_HISTORY_ID));
    }

    public function testKeyDoesNotRequirePurgeForUserEmail(): void
    {
        // B-4 (P1) auto-populates this on OAuth callback; rewriting it should
        // NOT purge the OAuth cache because the cache entry is still valid.
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_USER_EMAIL));
    }

    public function testKeyDoesNotRequirePurgeForUnrelatedSettings(): void
    {
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge('system_title'));
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge('whatsapp_api_url'));
    }
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Service/SettingsServiceTest.php`
Expected: method not found.

- [ ] **Step 3: Extract the predicate and rewire `saveSetting`**

In `src/Service/SettingsService.php`, add the pure static method (immediately after the class opening):

```php
/**
 * Returns true when writing the given setting key must purge the Gmail
 * OAuth PSR-6 cache (because the credentials those tokens were bound to
 * have rotated). Pure predicate — no I/O, no state — exposed for testing
 * and to keep the policy explicit.
 */
public static function keyRequiresOAuthCachePurge(string $key): bool
{
    return in_array(
        $key,
        [SettingKeys::GMAIL_CLIENT_SECRET_JSON, SettingKeys::GMAIL_REFRESH_TOKEN],
        true,
    );
}
```

Then update `saveSetting` to call the predicate. Replace the existing block:

```php
if ($result) {
    $this->clearAllCaches();
    if (in_array($key, [SettingKeys::GMAIL_CLIENT_SECRET_JSON, SettingKeys::GMAIL_REFRESH_TOKEN], true)) {
        $this->clearGmailOAuthCache();
    }
}
```

With:

```php
if ($result) {
    $this->clearAllCaches();
    if (self::keyRequiresOAuthCachePurge($key)) {
        $this->clearGmailOAuthCache();
    }
}
```

- [ ] **Step 4: Run tests and verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Service/SettingsServiceTest.php`
Expected: 5 passed.

- [ ] **Step 5: Run the full suite to confirm no regression in cache behavior**

Run: `composer test`
Expected: no new failures vs baseline.

### Task 3.9: Style + static analysis gate

- [ ] **Step 1: Code style check**

Run: `composer cs-check`
Expected: clean. Fix with `composer cs-fix` if needed.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse src`
Expected: 38 baseline errors, none new in touched files.

- [ ] **Step 3: Full test run**

Run: `composer test`
Expected: ~265 tests; 7 pre-existing baseline failures, none related to Gmail.

### Task 3.10: Commit M-2

- [ ] **Step 1: Stage all M-2 files**

```bash
git add src/Constants/SettingKeys.php \
        src/Service/Gmail/HistoryMode.php \
        src/Service/GmailService.php \
        src/Service/SettingsService.php \
        src/Service/Dto/GmailImportResult.php \
        src/Service/GmailImportService.php \
        src/Command/ImportGmailCommand.php \
        tests/TestCase/Service/Gmail/HistoryModeTest.php \
        tests/TestCase/Service/GmailServiceTest.php \
        tests/TestCase/Service/Dto/GmailImportResultTest.php \
        tests/TestCase/Service/SettingsServiceTest.php
```

- [ ] **Step 2: Commit**

```bash
git commit -m "$(cat <<'EOF'
feat(gmail): history.list checkpoint polling (M-2)

Replaces is:unread polling with users.history.list keyed on a new
GMAIL_LAST_HISTORY_ID setting. Bootstrap path runs once when the
checkpoint is absent and writes the historyId returned by
users.getProfile('me'). Delta path runs every subsequent invocation;
a 404 from history.list (checkpoint too old) triggers a one-shot full
sync and checkpoint refresh. GmailImportResult exposes history_mode
and history_fallbacks for operator visibility. Empty deltas leave the
checkpoint untouched; non-empty deltas advance it to the maximum
gmail_history_id observed during the per-message loop.

ImportGmailCommand now passes queryOverride=null by default so the CLI
benefits from the checkpoint state machine; explicit --query still
bypasses the checkpoint (MANUAL_OVERRIDE mode).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

# PHASE 4 — Audit closure

### Task 4.1: Document P2 closure in the audit doc

**Files:**
- Modify: `docs/audits/2026-05-16-gmail-api-audit.md`

- [ ] **Step 1: Update §3/§4 finding statuses and §8 plan**

Mark M-2, M-4, and M-5 as **Cerrado** with their respective commit SHAs (use `git log --oneline -5` to capture the three latest hashes). Use the same prose pattern as the existing P0/P1 closures (see §11 of the audit doc):

For each finding, prepend the existing finding body with a `> **Cerrado YYYY-MM-DD — commit `xxxxxxx`.** …` line describing the operative change in two or three sentences.

In §8 (Plan de acción priorizado), update the "P2 (mediano plazo)" subsection header to **`### P2 (mediano plazo) — Completado YYYY-MM-DD`** and add the three commits in the same per-finding format the P0/P1 blocks use.

In §11 (Notas para implementación), append a new dated subsection `### YYYY-MM-DD — P2 cerrado (M-4 + M-5 + M-2)` summarizing:

- the three commits and their commit SHAs;
- any deviations from the plan (typically: any compromise made in tests, the dual-path `MarkReadQueueService` seam, the `references_header` rename);
- verification ran;
- post-deploy operational pendings (monitor `gmail_mark_read_pending` table size; verify `gmail_last_history_id` populated on first run; expect one bootstrap log line).

- [ ] **Step 2: Stage and commit**

```bash
git add docs/audits/2026-05-16-gmail-api-audit.md
git commit -m "$(cat <<'EOF'
docs(audit): close P2 (M-2, M-4, M-5) on 2026-05-16 Gmail audit

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

# Post-deploy smoke (manual)

Out-of-band — not part of the plan's automated verification, but required per the spec §8:

1. After deploy, run `bin/cake import_gmail --max 1` once. Expected: log line `Gmail import completed` with `history_mode=bootstrap` and `gmail_last_history_id` row present in `system_settings`.
2. Send a test email to the configured Gmail account, wait for the next webhook run. Expected: `history_mode=delta`, one ticket created, no entries in `gmail_mark_read_pending`.
3. Open the new ticket, reply from an external (non-Gmail) account. Expected: a comment is created on the existing ticket — no duplicate ticket — and the comment's `rfc_message_id` / `in_reply_to` columns are populated.
4. Manually insert a bogus row into `gmail_mark_read_pending` (`gmail_message_id='nonexistent', attempts=0`). After the next webhook run, the row should be dropped (Gmail returns 404 for the unknown ID → PERMANENT category → immediate drop).
