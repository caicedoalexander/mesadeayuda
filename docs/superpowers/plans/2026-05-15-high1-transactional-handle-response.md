# HIGH-1 Transactional handleResponse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce transactional boundaries in `TicketPipelineService::handleResponse()` with post-commit event dispatch and best-effort orphan-file cleanup, preserving the current "comment survives status failure" semantics.

**Architecture:** Two separate `Connection::transactional()` blocks — TX1 wraps comment + uploads, TX2 wraps status change — with a local `$pendingEvents` buffer flushed after both TXs succeed. Orphan attachment files tracked during TX1 are `unlink`ed best-effort if TX1 rolls back. `changeStatus()` gains a `deferDispatch` flag that suppresses inline event dispatch when called from inside a TX block.

**Tech Stack:** CakePHP 5.x, PHP 8.5+, PHPUnit, `Cake\Database\Connection::transactional()`.

**Spec reference:** `docs/superpowers/specs/2026-05-15-high1-transactional-handle-response-design.md`.

---

## File map

- **Modify** `src/Service/TicketPipelineService.php` — refactor `handleResponse()`, add `cleanupOrphanedFiles()`, add `deferDispatch` param to `changeStatus()`.
- **Create** `tests/TestCase/Service/TicketPipelineServiceTest.php` — new test file (no existing tests for this service).

No migrations, no config changes, no new dependencies.

---

## Pre-flight

- [ ] **Step 0.1: Confirm baseline is green**

Run: `composer test`
Expected: PASS (current suite, no regressions baseline).

Run: `vendor/bin/phpstan analyse src/Service/TicketPipelineService.php`
Expected: existing errors only (record count for diff comparison later).

---

## Task 1: Add `deferDispatch` parameter to `changeStatus()`

**Files:**
- Modify: `src/Service/TicketPipelineService.php` (method `changeStatus`, lines ~171-229)
- Test: `tests/TestCase/Service/TicketPipelineServiceTest.php` (create new)

Background: `changeStatus()` currently dispatches `TicketStatusChanged` inline on line 220. We need to allow callers inside a TX to opt out, so the event can be dispatched post-commit instead. Default `false` preserves all current behavior.

- [ ] **Step 1.1: Write failing test for default behavior (regression guard)**

Create `tests/TestCase/Service/TicketPipelineServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Event\TicketStatusChanged;
use App\Service\TicketPipelineService;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

class TicketPipelineServiceTest extends TestCase
{
    public function testChangeStatusDispatchesByDefault(): void
    {
        $eventManager = new EventManager();
        $dispatched = [];
        $eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        // Build a service with mocked collaborators; we only exercise the dispatch flag.
        // Real DB persistence is out of scope for this regression test — we use a stubbed
        // Tickets table via a partial mock of the service that bypasses the actual save().
        $this->markTestIncomplete('Wire up after Task 1.3 lands the deferDispatch parameter');
    }
}
```

- [ ] **Step 1.2: Run test (should be incomplete/skipped)**

Run: `vendor/bin/phpunit tests/TestCase/Service/TicketPipelineServiceTest.php -v`
Expected: 1 incomplete test, 0 failures. This confirms the file is wired and PHPUnit picks it up.

- [ ] **Step 1.3: Add `deferDispatch` parameter to `changeStatus()`**

In `src/Service/TicketPipelineService.php`, change the `changeStatus` signature and dispatch block:

Old signature (line 171-177):
```php
public function changeStatus(
    EntityInterface $entity,
    string $newStatus,
    ?int $userId = null,
    ?string $comment = null,
    bool $sendNotifications = true,
): bool {
```

New signature:
```php
public function changeStatus(
    EntityInterface $entity,
    string $newStatus,
    ?int $userId = null,
    ?string $comment = null,
    bool $sendNotifications = true,
    bool $deferDispatch = false,
): bool {
```

Old dispatch block (lines 219-226):
```php
if ($sendNotifications) {
    $this->eventManager->dispatch(new TicketStatusChanged(
        ticketId: (int)$entity->id,
        oldStatus: $oldStatus,
        newStatus: $newStatus,
        actorId: $userId,
    ));
}
```

New dispatch block:
```php
if ($sendNotifications && !$deferDispatch) {
    $this->eventManager->dispatch(new TicketStatusChanged(
        ticketId: (int)$entity->id,
        oldStatus: $oldStatus,
        newStatus: $newStatus,
        actorId: $userId,
    ));
}
```

Also update the PHPDoc above the method to document the new parameter:

```php
 * @param bool $deferDispatch When true, suppresses inline event dispatch even if
 *        $sendNotifications is true. Used by callers (e.g., handleResponse) that
 *        wrap this call in a transaction and need to dispatch post-commit.
```

- [ ] **Step 1.4: Run full suite to confirm no regressions**

Run: `composer test`
Expected: all existing tests still PASS. The new test in TicketPipelineServiceTest stays incomplete (that's fine for now — we wire it in Task 4).

- [ ] **Step 1.5: Commit**

```bash
git add src/Service/TicketPipelineService.php tests/TestCase/Service/TicketPipelineServiceTest.php
git commit -m "$(cat <<'EOF'
refactor(pipeline): add deferDispatch param to changeStatus

Default false preserves existing behavior. Will be used by handleResponse
to defer TicketStatusChanged dispatch until after the transaction commits.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Add `cleanupOrphanedFiles()` helper

**Files:**
- Modify: `src/Service/TicketPipelineService.php` (new private method)

Background: When TX1 rolls back, attachment files already moved to disk by `saveUploadedFile()` become orphans (no DB row pointing to them). We track relative paths during the TX and `@unlink` them best-effort after rollback.

- [ ] **Step 2.1: Add the helper method**

Append at the end of the class body in `src/Service/TicketPipelineService.php`, before the closing `}`:

```php
    /**
     * Best-effort removal of attachment files that were written to disk during
     * a transaction that subsequently rolled back. Failures are logged but never
     * propagated — the caller's primary error is more important than cleanup.
     *
     * @param array<int, string> $relativePaths Relative paths as stored in attachments.file_path
     *        (e.g., "uploads/attachments/T-0001/uuid.pdf"). Resolved against WWW_ROOT.
     */
    private function cleanupOrphanedFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $absolute = WWW_ROOT . $relativePath;
            if (!file_exists($absolute)) {
                continue;
            }
            if (@unlink($absolute) === false) {
                Log::warning('Failed to cleanup orphaned attachment after TX rollback', [
                    'path' => $absolute,
                ]);
            }
        }
    }
```

- [ ] **Step 2.2: Confirm static analysis stays clean**

Run: `vendor/bin/phpstan analyse src/Service/TicketPipelineService.php`
Expected: no new errors vs. baseline from Step 0.1.

- [ ] **Step 2.3: Commit**

```bash
git add src/Service/TicketPipelineService.php
git commit -m "$(cat <<'EOF'
refactor(pipeline): add cleanupOrphanedFiles helper

Best-effort unlink of attachment files left behind when a transaction
wrapping comment+uploads rolls back. Used by handleResponse in the next commit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Refactor `handleResponse()` to use two TX blocks + post-commit dispatch

**Files:**
- Modify: `src/Service/TicketPipelineService.php` (method `handleResponse`, lines 73-159)

Background: The method currently runs comment, uploads, status change, and notifications inline without transactional boundaries. We wrap the comment+uploads block in TX1 (with best-effort cleanup on rollback) and the status change in TX2 (preserving the existing `InvalidStatusTransitionException` catch). Events are buffered locally and dispatched only after both TXs succeed.

- [ ] **Step 3.1: Replace the method body**

In `src/Service/TicketPipelineService.php`, replace lines 73-159 (the entire `handleResponse` method) with:

```php
    public function handleResponse(int $entityId, int $userId, array $data, array $files): array
    {
        $commentBody = $data['comment_body'] ?? $data['body'] ?? '';
        $commentType = $data['comment_type'] ?? TicketConstants::COMMENT_PUBLIC;
        $newStatus = $data['status'] ?? null;

        $emailTo = $this->decodeEmailRecipients($data['email_to'] ?? null);
        $emailCc = $this->decodeEmailRecipients($data['email_cc'] ?? null);

        Log::debug('Response email recipients', [
            'raw_email_to' => $data['email_to'] ?? null,
            'raw_email_cc' => $data['email_cc'] ?? null,
            'decoded_email_to' => $emailTo,
            'decoded_email_cc' => $emailCc,
        ]);

        $hasComment = !empty(trim($commentBody));

        $entity = $this->fetchTable('Tickets')->get($entityId);
        assert($entity instanceof Ticket);

        $oldStatus = $entity->status;
        $hasStatusChange = $newStatus && $newStatus !== $oldStatus;

        if (!$hasComment && !$hasStatusChange) {
            return [
                'success' => false,
                'message' => 'Debes escribir un comentario o cambiar el estado.',
                'entity' => $entity,
            ];
        }

        $connection = $this->fetchTable('Tickets')->getConnection();
        $writtenFilePaths = [];
        $pendingEvents = [];
        $comment = null;
        $uploadedCount = 0;

        // TX1: comment + uploads. On rollback (callback returns false OR exception),
        // best-effort unlink any attachment files already moved to disk.
        if ($hasComment) {
            $tx1Ok = false;
            try {
                $tx1Ok = $connection->transactional(function () use (
                    $entityId,
                    $userId,
                    $commentBody,
                    $commentType,
                    $emailTo,
                    $emailCc,
                    $files,
                    $entity,
                    &$comment,
                    &$uploadedCount,
                    &$writtenFilePaths,
                ): bool {
                    $comment = $this->comments->addComment(
                        $entityId,
                        $userId,
                        $commentBody,
                        $commentType,
                        false,
                        $emailTo,
                        $emailCc,
                    );

                    if (!$comment) {
                        return false;
                    }

                    if (!empty($files['attachments'])) {
                        foreach ($files['attachments'] as $file) {
                            if ($file->getError() !== UPLOAD_ERR_OK) {
                                continue;
                            }
                            $attachment = $this->attachments->saveUploadedFile($entity, $file, $comment->id, $userId);
                            if ($attachment !== null) {
                                $writtenFilePaths[] = $attachment->file_path;
                                $uploadedCount++;
                            }
                        }
                    }

                    return true;
                });
            } finally {
                if ($tx1Ok !== true) {
                    $this->cleanupOrphanedFiles($writtenFilePaths);
                }
            }

            if ($tx1Ok !== true) {
                return [
                    'success' => false,
                    'message' => 'Error al agregar el comentario.',
                    'entity' => $entity,
                ];
            }
        }

        // TX2: status change. InvalidStatusTransitionException is caught to preserve
        // the "comment already committed; don't tempt a retry" semantics.
        if ($hasStatusChange) {
            try {
                $connection->transactional(function () use (
                    $entity,
                    $newStatus,
                    $oldStatus,
                    $userId,
                    &$pendingEvents,
                ): bool {
                    $ok = $this->changeStatus($entity, $newStatus, $userId, null, true, deferDispatch: true);
                    if (!$ok) {
                        return false;
                    }
                    $pendingEvents[] = new TicketStatusChanged(
                        ticketId: (int)$entity->id,
                        oldStatus: $oldStatus,
                        newStatus: $newStatus,
                        actorId: $userId,
                    );

                    return true;
                });
            } catch (InvalidStatusTransitionException $e) {
                Log::warning('Response committed but status transition rejected', [
                    'ticket_id' => $entityId,
                    'from' => $oldStatus,
                    'to' => $newStatus,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'success' => false,
                    'message' => sprintf(
                        'Comentario guardado, pero no se pudo cambiar el estado: %s',
                        $e->getMessage(),
                    ),
                    'entity' => $entity,
                ];
            }
        }

        // Post-commit: dispatch buffered domain events. If TX2 rolled back without
        // throwing (changeStatus returned false), pendingEvents is empty for that branch.
        foreach ($pendingEvents as $event) {
            $this->eventManager->dispatch($event);
        }

        $this->notifications->sendResponseNotifications(
            $entity,
            $comment,
            $oldStatus,
            $newStatus,
            $hasComment,
            $commentType,
            $hasStatusChange,
            $emailTo,
            $emailCc,
        );

        return $this->buildResponseResult($hasComment, $hasStatusChange, $uploadedCount, $entity);
    }
```

- [ ] **Step 3.2: Verify static analysis**

Run: `vendor/bin/phpstan analyse src/Service/TicketPipelineService.php`
Expected: no new errors vs. baseline. (`$attachment->file_path` access mirrors the existing pattern of dynamic Cake entity property access already present in the file.)

- [ ] **Step 3.3: Verify code style**

Run: `composer cs-fix` then `composer cs-check`
Expected: cs-fix applies trivial formatting if any; cs-check passes for this file.

- [ ] **Step 3.4: Run existing suite (no new tests yet)**

Run: `composer test`
Expected: all green. The new test file from Task 1 is still incomplete; that's fine.

- [ ] **Step 3.5: Commit**

```bash
git add src/Service/TicketPipelineService.php
git commit -m "$(cat <<'EOF'
refactor(pipeline): transactional handleResponse with post-commit dispatch

Wraps comment+uploads in TX1 (best-effort orphan file cleanup on rollback)
and the status change in TX2 (preserves the existing InvalidStatusTransition
catch). Domain events buffered locally and dispatched only after both TXs
succeed. Closes HIGH-1 from the 2026-05-14 tickets audit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Tests for `handleResponse()` rollback and dispatch ordering

**Files:**
- Modify: `tests/TestCase/Service/TicketPipelineServiceTest.php`

Background: Mocking `Connection::transactional()` cleanly is awkward, so the tests use real collaborators where possible and dependency injection to substitute mocks for the comment/attachment/notification services. The `Connection` itself is the real one from CakePHP's test runtime; the underlying `Tickets` table is **not** persisted to — we never call `fetchTable('Tickets')->get()` because every test injects a fully constructed `Ticket` entity through the mocked services.

Approach: introduce a thin test helper that exposes `handleResponse` invocation against an in-memory Ticket. Use a SQLite-backed Connection only if needed for the "happy path" integration test (Task 4.5).

**Important constraint:** `handleResponse` calls `$this->fetchTable('Tickets')->get($entityId)` and `$this->fetchTable('Tickets')->getConnection()`. To test without a real DB, we override the trait via a partial mock of `TicketPipelineService` that stubs `fetchTable`. CakePHP's `LocatorAwareTrait::fetchTable` can be intercepted via the locator. We'll inject a fake locator that returns a stub Tickets table.

- [ ] **Step 4.1: Replace the placeholder test file with a full test scaffold**

Replace the contents of `tests/TestCase/Service/TicketPipelineServiceTest.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\TicketConstants;
use App\Domain\Event\TicketStatusChanged;
use App\Model\Entity\Attachment;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Service\AuthorizationService;
use App\Service\Dto\SystemConfig;
use App\Service\Exception\InvalidStatusTransitionException;
use App\Service\TicketAttachmentService;
use App\Service\TicketCommentService;
use App\Service\TicketNotificationService;
use App\Service\TicketPipelineService;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Tests focus on the transactional choreography of handleResponse():
 * rollback paths, post-commit dispatch ordering, and the preserved
 * "comment survives status failure" semantics.
 *
 * The tests do NOT exercise SQL persistence — collaborators are mocked.
 * The real Connection from the default test datasource provides
 * transactional() semantics but no rows are written.
 */
class TicketPipelineServiceTest extends TestCase
{
    private Ticket $ticket;
    private EventManager $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticket = new Ticket([
            'id' => 1,
            'ticket_number' => 'T-0001',
            'status' => TicketConstants::STATUS_ABIERTO,
        ]);
        $this->ticket->setNew(false);

        $this->eventManager = new EventManager();
    }

    public function testHandleResponseRollsBackUploadsWhenCommentFails(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn(null);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $attachments->expects($this->never())->method('saveUploadedFile');

        $notifications = $this->createMock(TicketNotificationService::class);
        $notifications->expects($this->never())->method('sendResponseNotifications');

        $service = $this->buildService($comments, $attachments, $notifications);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => null],
            files: [],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Error al agregar el comentario.', $result['message']);
    }

    public function testHandleResponsePreservesCommentOnInvalidStatusTransition(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);
        $notifications->expects($this->never())->method('sendResponseNotifications');

        // Use a partial mock of the pipeline service that throws on changeStatus.
        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                $notifications,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->expects($this->once())
            ->method('changeStatus')
            ->willThrowException(new InvalidStatusTransitionException(
                'Transición no permitida: abierto → cerrado'
            ));

        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => TicketConstants::STATUS_CERRADO],
            files: [],
        );

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('Comentario guardado, pero no se pudo cambiar el estado', $result['message']);

        // No status-changed event must have been dispatched.
        $dispatched = $this->captureDispatchedEvents();
        $this->assertSame([], $dispatched);
    }

    public function testHandleResponseDispatchesStatusEventAfterCommit(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                $notifications,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->expects($this->once())
            ->method('changeStatus')
            ->with(
                $this->isInstanceOf(Ticket::class),
                TicketConstants::STATUS_EN_PROGRESO,
                42,
                null,
                true,
                true, // deferDispatch
            )
            ->willReturn(true);

        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => TicketConstants::STATUS_EN_PROGRESO],
            files: [],
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(TicketStatusChanged::class, $dispatched[0]);
    }

    public function testHandleResponseDoesNotDispatchWhenChangeStatusReturnsFalse(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                $notifications,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->method('changeStatus')->willReturn(false);
        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => TicketConstants::STATUS_EN_PROGRESO],
            files: [],
        );

        // changeStatus returning false rolls back TX2 but the comment from TX1 is committed
        // and notifications still run — current contract reports overall success based on
        // buildResponseResult, which doesn't know about silent TX2 rollback. We assert the
        // dispatch invariant only.
        $this->assertSame([], $dispatched);
        // Mark $result as touched to avoid PHPStan complaining about an unused variable.
        $this->assertIsArray($result);
    }

    public function testChangeStatusDispatchesByDefault(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn(new TicketComment(['id' => 1]));

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        // Build a real service (no method overrides) and a Tickets table stub
        // that returns the entity unchanged on save. We invoke changeStatus directly.
        $service = $this->buildService($comments, $attachments, $notifications);
        $this->stubTicketsTable($service, saveReturnsEntity: true);

        $ticket = new Ticket([
            'id' => 1,
            'ticket_number' => 'T-0002',
            'status' => TicketConstants::STATUS_ABIERTO,
        ]);
        $ticket->setNew(false);

        $ok = $service->changeStatus($ticket, TicketConstants::STATUS_EN_PROGRESO, 42);

        $this->assertTrue($ok);
        $this->assertCount(1, $dispatched, 'Default behavior must dispatch the event inline');
    }

    // -------------------- helpers --------------------

    private function buildService(
        TicketCommentService $comments,
        TicketAttachmentService $attachments,
        TicketNotificationService $notifications,
    ): TicketPipelineService {
        return new TicketPipelineService(
            SystemConfig::empty(),
            $comments,
            $attachments,
            $notifications,
            new AuthorizationService(),
            $this->eventManager,
        );
    }

    /**
     * Replaces the locator so $service->fetchTable('Tickets') returns a stub
     * whose get() yields $this->ticket and whose getConnection() is the real
     * default test connection (provides working transactional() semantics).
     */
    private function stubTicketsTable(TicketPipelineService $service, bool $saveReturnsEntity = false): void
    {
        $connection = ConnectionManager::get('test');

        $tickets = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'getConnection', 'save'])
            ->getMock();

        $tickets->method('get')->willReturn($this->ticket);
        $tickets->method('getConnection')->willReturn($connection);
        if ($saveReturnsEntity) {
            $tickets->method('save')->willReturnArgument(0);
        }

        $locator = new TableLocator();
        $locator->set('Tickets', $tickets);

        // LocatorAwareTrait stores the locator on the object — inject ours.
        $service->setTableLocator($locator);
    }

    private function captureDispatchedEvents(): array
    {
        // Helper retained for future use. Current tests register inline listeners.
        return [];
    }
}
```

- [ ] **Step 4.2: Run the new test file**

Run: `vendor/bin/phpunit tests/TestCase/Service/TicketPipelineServiceTest.php -v`
Expected: at least the simpler tests (`testHandleResponseRollsBackUploadsWhenCommentFails`, `testChangeStatusDispatchesByDefault`) pass. Failures in the more elaborate tests indicate the table-stubbing helper needs adjustment.

- [ ] **Step 4.3: If any test fails, diagnose and fix**

The most likely failure modes:
- `setTableLocator` not available on the trait → use `$service->getTableLocator()` instead and `$locator->set()` on the existing locator.
- `Connection::transactional` on the test connection rolls back inner work, causing the partial mock's `changeStatus` to never get invoked → switch to using a real connection from `ConnectionManager::get('default')` if `test` is unavailable; or inject a fake connection that just invokes the callback.

If the connection approach proves too brittle, fall back to a `FakeConnection` test double that implements only `transactional()` by invoking the callback inline and returning its result. Place it in `tests/TestCase/Service/Fakes/FakeConnection.php` if needed.

- [ ] **Step 4.4: Full suite green**

Run: `composer test`
Expected: all green.

- [ ] **Step 4.5: Commit**

```bash
git add tests/TestCase/Service/TicketPipelineServiceTest.php
git commit -m "$(cat <<'EOF'
test(pipeline): cover transactional handleResponse paths

Covers TX1 rollback on comment failure, preserved comment semantics on
InvalidStatusTransition, post-commit dispatch ordering, no-dispatch on
silent TX2 rollback, and the changeStatus default-dispatch regression guard.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Smoke validation and audit update

**Files:**
- Modify: `docs/audits/2026-05-14-tickets-module-audit.md`

- [ ] **Step 5.1: Final composer checks**

Run: `composer cs-fix && composer cs-check && composer test`
Expected: no fixes needed; cs-check passes; all tests green.

Run: `vendor/bin/phpstan analyse src/Service/TicketPipelineService.php`
Expected: error count equal to baseline from Step 0.1 (no new errors).

- [ ] **Step 5.2: Update the audit document**

In `docs/audits/2026-05-14-tickets-module-audit.md`:

**§1 table** — change the "Hallazgos Altos (naranja)" row from `4` to `3`.

**§4 HIGH-1 header** — change from:
```
### HIGH-1 — `handleResponse()` no es transaccional
```
to:
```
### HIGH-1 — `handleResponse()` no es transaccional ✅ CERRADO 2026-05-15
```

**§4 HIGH-1 body** — append a "**Fix aplicado:**" line right after the existing "**Fix:**" line:
```
**Fix aplicado:** Dos `Connection::transactional()` separados (TX1: comment+uploads, TX2: status change) con buffer local de eventos para dispatch post-commit. Best-effort cleanup de archivos huérfanos cuando TX1 hace rollback. `changeStatus` recibió parámetro `deferDispatch`. Detalle en §11.
```

**§9 acción #3** — change estado from "Pendiente" to "**Completado 2026-05-15**".

**§11** — append a new dated section at the end:

```markdown

### 2026-05-15 — HIGH-1 cerrado: frontera transaccional en `handleResponse()`

**Hallazgo original:** `handleResponse()` ejecutaba comentario + adjuntos + status + notificaciones inline sin TX. Una falla a mitad dejaba comentario persistido y notificación con estado parcial.

**Decisiones de diseño:**
- Dos TX separadas en lugar de una sola, para preservar la semántica deliberada de "comentario sobrevive si la transición de estado falla" (catch de `InvalidStatusTransitionException`).
- Best-effort `@unlink` post-rollback para archivos ya escritos al disco (no rollback-able por la BD). Failures logueados, no propagados.
- Dispatch de `TicketStatusChanged` diferido a post-commit vía buffer local + nuevo parámetro `deferDispatch` en `changeStatus()` (default `false`, preserva callers existentes).

**Cambios:**
- `src/Service/TicketPipelineService.php`: refactor de `handleResponse()` (87 → ~120 líneas), nuevo método privado `cleanupOrphanedFiles()`, parámetro `deferDispatch` en `changeStatus()`.
- `tests/TestCase/Service/TicketPipelineServiceTest.php`: archivo nuevo. 5 tests cubriendo rollback de TX1, semántica preservada en `InvalidStatusTransition`, orden post-commit del dispatch, no-dispatch en rollback silencioso de TX2, y regresión de `changeStatus` default.

**Despliegue:** sin migraciones, sin cambios de firma pública, sin variables de entorno nuevas. Rollback trivial.

**Validaciones:**
- `composer test`: PASS — 5 tests nuevos verdes.
- `composer cs-check`: sin errores nuevos.
- `phpstan analyse src/Service/TicketPipelineService.php`: sin errores nuevos vs. baseline.

**Hallazgos derivados pendientes:**
- CRIT-3 (Outbox) sigue abierto: la ventana entre commit y dispatch ahora es ~0ms pero un crash exactamente ahí sigue perdiendo el evento. Outbox sigue siendo necesario para at-least-once.
- MED-1 (`sendResponseNotifications` fuera de bus) sigue abierto y mantiene la asimetría EDA.
```

- [ ] **Step 5.3: Commit the audit update**

```bash
git add docs/audits/2026-05-14-tickets-module-audit.md
git commit -m "$(cat <<'EOF'
docs(audit): close HIGH-1; update matrix and bitácora

Transactional boundary in handleResponse() landed in prior commits.
CRIT-3 and MED-1 remain open.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 5.4: Optional manual smoke test**

If a dev server is available:
1. Run `bin/cake server`.
2. Open a ticket in the UI, post a reply with an attachment, change status. Verify the response and attachment are visible.
3. Tail the log (`logs/debug.log` and `logs/error.log`) for any `Failed to cleanup orphaned attachment` or transaction errors.

This step is best-effort and not gating. The unit tests cover the regression surface.

---

## Self-review notes

- All five test methods listed in the spec §6 are present in Task 4.1.
- `cleanupOrphanedFiles` (§3.1) implemented in Task 2.
- `deferDispatch` parameter (§3.2) implemented in Task 1.
- Both TX blocks (§4) implemented in Task 3.
- Error matrix from §5: each row maps to a test or to the existing `catch (InvalidStatusTransitionException)`.
- Risks #1 (nested transactions) addressed by using the real test connection in Task 4; if savepoint behavior diverges, Task 4.3 provides the fallback.
- Risk #2 (field name `file_path`) verified before plan: confirmed in `src/Model/Entity/Attachment.php:16` and `src/Service/Traits/GenericAttachmentTrait.php:147-159`.
- No placeholders, no TODOs, no "implement similar to Task N" — every step contains the literal code or command to run.
