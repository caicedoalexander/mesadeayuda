# Gmail-Ingest Fix + Audit Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reparar la regresión runtime de la ingesta de Gmail introducida por el refactor del 2026-05-16 y cerrar 3 hallazgos medios de la auditoría (MED-4, MED-6, MED-7).

**Architecture:** Cuatro commits independientes en orden de menor a mayor riesgo: (1) fix de regresión con test que la bloquea para siempre, (2) limpieza mecánica de un helper residual, (3) factory pattern para construcción de Ticket desde email, (4) actualización del documento de auditoría.

**Tech Stack:** PHP 8.5, CakePHP 5.3, PHPUnit 13.1.8, PHPStan 2.1, CakePHP CodeSniffer 5.3.

**Spec:** `docs/superpowers/specs/2026-05-16-gmail-ingest-fix-and-cleanup-design.md`

---

## File Structure

### Archivos creados

- `tests/TestCase/Service/TicketIngestionServiceTest.php` — test de construcción del servicio (bloquea regresión).

### Archivos modificados

- `src/Service/TicketIngestionService.php` — constructor cambia dependencia `TicketNotificationService` → `N8nService`; adopción del factory.
- `src/Model/Entity/Ticket.php` — agrega método estático `fromEmailIngest()`.
- `tests/TestCase/Model/Entity/TicketTest.php` — agrega tests del factory.
- `src/Controller/Trait/TicketServiceInitializerTrait.php` — elimina método `getEntityComponents()`.
- `src/Controller/Trait/TicketActionsTrait.php` — 4 callsites reemplazados.
- `src/Controller/Trait/TicketBulkTrait.php` — 4 callsites reemplazados.
- `src/Controller/Trait/TicketViewTrait.php` — 1 callsite reemplazado.
- `src/Controller/Trait/TicketListingTrait.php` — 1 callsite reemplazado.
- `src/Controller/Trait/TicketHistoryTrait.php` — 1 callsite reemplazado.
- `docs/audits/2026-05-14-tickets-module-audit.md` — registro de cierres en §11 + actualización de §1 (tabla resumen) y §9 (acciones priorizadas).

---

## Task 1: Fix Gmail-Ingest Regression

**Files:**
- Create: `tests/TestCase/Service/TicketIngestionServiceTest.php`
- Modify: `src/Service/TicketIngestionService.php` (lines 1-51, 142-155)

### - [ ] Step 1.1: Write failing test for construction

Create `tests/TestCase/Service/TicketIngestionServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\Dto\SystemConfig;
use App\Service\TicketIngestionService;
use PHPUnit\Framework\TestCase;

final class TicketIngestionServiceTest extends TestCase
{
    /**
     * Regression guard for the 2026-05-16 notification refactor: the
     * constructor used to fall back to `new TicketNotificationService($config)`,
     * which after the refactor takes (array $strategies, array $channels) —
     * passing a SystemConfig as first arg throws TypeError. Every Gmail
     * ingest call went through this path because GmailImportService::fromSettings
     * never injects the optional dependency.
     */
    public function testConstructsWithoutOptionalDependencies(): void
    {
        $service = new TicketIngestionService(SystemConfig::empty());

        self::assertInstanceOf(TicketIngestionService::class, $service);
    }

    public function testConstructsWithNullConfig(): void
    {
        $service = new TicketIngestionService();

        self::assertInstanceOf(TicketIngestionService::class, $service);
    }
}
```

### - [ ] Step 1.2: Run test to verify it fails

Run:
```bash
vendor/bin/phpunit tests/TestCase/Service/TicketIngestionServiceTest.php
```

Expected: **FAIL** con `TypeError: TicketNotificationService::__construct(): Argument #1 ($strategies) must be of type array, App\Service\Dto\SystemConfig given`.

Si falla por otra razón (e.g. autoload, namespace), corregir antes de seguir.

### - [ ] Step 1.3: Modify TicketIngestionService constructor and N8n call

Replace the imports section (`src/Service/TicketIngestionService.php`, líneas 1-19) — cambiar import:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;
use App\Domain\Event\TicketCreated;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Service\Dto\SystemConfig;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Util\EmailHeaderParser;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
```

(No cambia — los imports ya estaban bien; `TicketNotificationService` no se importaba explícitamente.)

Replace property declaration block (líneas 30-33):

```php
    private TicketAttachmentService $attachments;
    private N8nService $n8n;
    private SystemConfig $config;
    private EventManagerInterface $eventManager;
```

Replace constructor (líneas 35-51):

```php
    /**
     * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
     * @param \App\Service\TicketAttachmentService|null $attachments Optional injected attachment service
     * @param \App\Service\N8nService|null $n8n Optional injected n8n webhook service
     * @param \Cake\Event\EventManagerInterface|null $eventManager Optional injected event manager
     */
    public function __construct(
        ?SystemConfig $config = null,
        ?TicketAttachmentService $attachments = null,
        ?N8nService $n8n = null,
        ?EventManagerInterface $eventManager = null,
    ) {
        $this->config = $config ?? SystemConfig::empty();
        $this->attachments = $attachments ?? new TicketAttachmentService();
        $this->n8n = $n8n ?? new N8nService($this->config);
        $this->eventManager = $eventManager ?? EventManager::instance();
    }
```

Replace the n8n webhook block (líneas 149-155):

```php
        // Send n8n webhook for AI tag assignment (lazy loaded only when creating tickets)
        try {
            $this->n8n->sendTicketCreatedWebhook($ticket);
        } catch (Exception $e) {
            Log::warning('n8n webhook failed (non-blocking): ' . $e->getMessage());
            // Don't block ticket creation if webhook fails
        }
```

### - [ ] Step 1.4: Run test to verify it passes

Run:
```bash
vendor/bin/phpunit tests/TestCase/Service/TicketIngestionServiceTest.php
```

Expected: **PASS** — 2 tests, 2 assertions.

### - [ ] Step 1.5: Run full test suite

Run:
```bash
composer test
```

Expected: PASS — sin nuevos fallos respecto al baseline (~176 tests, 439 asserts antes; ahora 178 tests). Si algún test pre-existente falla, verificar si referenciaba la propiedad `notifications` que se renombró a `n8n` — no debería haber callers externos.

### - [ ] Step 1.6: Run static analysis

Run:
```bash
vendor/bin/phpstan analyse src/Service/TicketIngestionService.php
```

Expected: sin nuevos errores. Errores pre-existentes (e.g. acceso a propiedades dinámicas de Entity) son aceptables si ya estaban.

### - [ ] Step 1.7: Run code style check

Run:
```bash
composer cs-fix && composer cs-check
```

Expected: `cs-fix` no encuentra fixes para los archivos tocados (o aplica sólo formatting trivial); `cs-check` pasa.

### - [ ] Step 1.8: Commit

```bash
git add src/Service/TicketIngestionService.php tests/TestCase/Service/TicketIngestionServiceTest.php
git commit -m "$(cat <<'EOF'
fix(ingestion): repair Gmail-ingest TypeError after notification refactor

The 2026-05-16 notification refactor rewrote TicketNotificationService
as a strategies+channels orchestrator and removed getN8nService(), but
TicketIngestionService kept calling the old API. Two crashes:

  - Constructor fallback: `new TicketNotificationService($config)`
    passed SystemConfig where the new signature expects array — TypeError.
  - createFromEmail line 151: `$this->notifications->getN8nService()`
    referenced a method that no longer exists.

Both paths fired on every `bin/cake import_gmail` and `POST /webhooks/
gmail/import` invocation, because GmailImportService::fromSettings
never injects the optional dependency.

Fix: inject N8nService directly. n8n is a webhook-for-tagging, not a
user-facing notification channel, so the dependency through
TicketNotificationService was an accidental facade.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Remove `getEntityComponents()` Helper (MED-4)

**Files:**
- Modify: `src/Controller/Trait/TicketActionsTrait.php` (líneas 119, 166, 205, 233)
- Modify: `src/Controller/Trait/TicketBulkTrait.php` (líneas 65, 116, 159, 205)
- Modify: `src/Controller/Trait/TicketViewTrait.php` (línea 57)
- Modify: `src/Controller/Trait/TicketListingTrait.php` (línea 57)
- Modify: `src/Controller/Trait/TicketHistoryTrait.php` (línea 43)
- Modify: `src/Controller/Trait/TicketServiceInitializerTrait.php` (líneas 81-99)

### - [ ] Step 2.1: Replace callsite in TicketActionsTrait — assignTicket

`src/Controller/Trait/TicketActionsTrait.php` líneas 119-137. Reemplazar:

```php
        $components = $this->getEntityComponents();
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        // Early actor guard: better UX than tripping the service exception
        if ($this->authService->isAssignmentDisabled($actor)) {
            $this->Flash->error(__('No tienes permisos para asignar tickets.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        if ($entity->isLocked()) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $components['service']->assign($entity, $assigneeId, $userId, $actor);
```

por:

```php
        $entity = $this->fetchTable('Tickets')->get($entityId);

        // Early actor guard: better UX than tripping the service exception
        if ($this->authService->isAssignmentDisabled($actor)) {
            $this->Flash->error(__('No tienes permisos para asignar tickets.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        if ($entity->isLocked()) {
            $this->Flash->error(__('No se puede modificar una Ticket en estado final.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $this->ticketPipeline->assign($entity, $assigneeId, $userId, $actor);
```

Y reemplazar las dos restantes en este método (líneas 145-147):

```php
        if ($result) {
            $this->Flash->success(__('Ticket asignada correctamente.'));
        } else {
            $this->Flash->error(__('No se pudo asignar la Ticket.'));
        }
```

### - [ ] Step 2.2: Replace callsite in TicketActionsTrait — changeTicketStatus

`src/Controller/Trait/TicketActionsTrait.php` líneas 166-187. Reemplazar:

```php
        $components = $this->getEntityComponents();
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        if ($entity->isLocked()) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $components['service']->changeStatus($entity, $newStatus, $userId);
        } catch (InvalidStatusTransitionException $e) {
            $this->Flash->error(__('Transición de estado no permitida: {0}', [$e->getMessage()]));

            return $this->redirect(['action' => $redirectAction]);
        }
        if ($result) {
            $this->Flash->success(__("Estado de {$entityName} actualizado."));
        } else {
            $this->Flash->error(__("Error al cambiar el estado de {$entityName}."));
        }
```

por:

```php
        $entity = $this->fetchTable('Tickets')->get($entityId);

        if ($entity->isLocked()) {
            $this->Flash->error(__('No se puede modificar una Ticket en estado final.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $this->ticketPipeline->changeStatus($entity, $newStatus, $userId);
        } catch (InvalidStatusTransitionException $e) {
            $this->Flash->error(__('Transición de estado no permitida: {0}', [$e->getMessage()]));

            return $this->redirect(['action' => $redirectAction]);
        }
        if ($result) {
            $this->Flash->success(__('Estado de Ticket actualizado.'));
        } else {
            $this->Flash->error(__('Error al cambiar el estado de Ticket.'));
        }
```

### - [ ] Step 2.3: Replace callsite in TicketActionsTrait — changeTicketPriority

`src/Controller/Trait/TicketActionsTrait.php` líneas 205-222. Reemplazar:

```php
        $components = $this->getEntityComponents();
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        if ($entity->isLocked()) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $components['service']->changePriority($entity, $newPriority, $userId);
        if ($result) {
            $this->Flash->success(__("Prioridad de {$entityName} actualizada."));
        } else {
            $this->Flash->error(__("Error al cambiar la prioridad de {$entityName}."));
        }
```

por:

```php
        $entity = $this->fetchTable('Tickets')->get($entityId);

        if ($entity->isLocked()) {
            $this->Flash->error(__('No se puede modificar una Ticket en estado final.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $this->ticketPipeline->changePriority($entity, $newPriority, $userId);
        if ($result) {
            $this->Flash->success(__('Prioridad de Ticket actualizada.'));
        } else {
            $this->Flash->error(__('Error al cambiar la prioridad de Ticket.'));
        }
```

### - [ ] Step 2.4: Replace callsite in TicketActionsTrait — addTicketComment

`src/Controller/Trait/TicketActionsTrait.php` líneas 233-246. Reemplazar:

```php
        $components = $this->getEntityComponents();
        $entityName = $components['displayName'];
        $service = $components['service'];

        $data = $this->request->getData();
        $files = $this->request->getUploadedFiles();

        $result = $service->handleResponse($entityId, $userId, $data, $files);

        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __("Comentario agregado a {$entityName}."));
        } else {
            $this->Flash->error($result['message'] ?? __("Error al agregar comentario a {$entityName}."));
        }
```

por:

```php
        $data = $this->request->getData();
        $files = $this->request->getUploadedFiles();

        $result = $this->ticketPipeline->handleResponse($entityId, $userId, $data, $files);

        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __('Comentario agregado a Ticket.'));
        } else {
            $this->Flash->error($result['message'] ?? __('Error al agregar comentario a Ticket.'));
        }
```

### - [ ] Step 2.5: Replace callsite in TicketBulkTrait — bulkAssignTickets

`src/Controller/Trait/TicketBulkTrait.php` líneas 57-100. Reemplazar la línea 65:

```php
        [$table, $service, $entityName] = $this->getEntityComponents();
```

por:

```php
        $table = $this->fetchTable('Tickets');
        $service = $this->ticketPipeline;
```

Y reemplazar los usos de `{$entityName}` (líneas 91, 94, 97) por literal `Ticket`:

```php
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} Ticket(s) asignado(s) correctamente."));
        }
        if ($unauthorizedCount > 0) {
            $this->Flash->warning(__("{$unauthorizedCount} Ticket(s) no se asignaron por reglas de autorización (lockeado, usuario inactivo o no-staff)."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser asignados."));
        }
```

### - [ ] Step 2.6: Replace callsite in TicketBulkTrait — bulkChangeTicketPriority

`src/Controller/Trait/TicketBulkTrait.php` línea 116. Reemplazar:

```php
        [$table, $service, $entityName] = $this->getEntityComponents();
```

por:

```php
        $table = $this->fetchTable('Tickets');
        $service = $this->ticketPipeline;
```

Y reemplazar `{$entityName}` (líneas 139, 142, 145):

```php
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} Ticket(s) actualizado(s) correctamente."));
        }
        if ($lockedCount > 0) {
            $this->Flash->warning(__("{$lockedCount} Ticket(s) en estado final no fueron modificados."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser actualizados."));
        }
```

### - [ ] Step 2.7: Replace callsite in TicketBulkTrait — bulkAddTicketTag

`src/Controller/Trait/TicketBulkTrait.php` línea 159. Reemplazar:

```php
        [, , $entityName] = $this->getEntityComponents();
```

por: borrar la línea completamente (no se usa nada más del helper).

Reemplazar `{$entityName}` en líneas 189, 192:

```php
        if ($successCount > 0) {
            $this->Flash->success(__("Etiqueta agregada a {$successCount} Ticket(s)."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser etiquetados."));
        }
```

### - [ ] Step 2.8: Replace callsite in TicketBulkTrait — bulkDeleteTickets

`src/Controller/Trait/TicketBulkTrait.php` línea 205. Reemplazar:

```php
        [$table, , $entityName] = $this->getEntityComponents();
```

por:

```php
        $table = $this->fetchTable('Tickets');
```

Y reemplazar `{$entityName}` en líneas 222, 225:

```php
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} Ticket(s) eliminado(s) correctamente."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser eliminados."));
        }
```

### - [ ] Step 2.9: Replace callsite in TicketViewTrait

`src/Controller/Trait/TicketViewTrait.php` líneas 55-61. Reemplazar:

```php
    protected function viewTicket(int $id, array $config = []): ?Response
    {
        $components = $this->getEntityComponents();
        $tableName = $components['tableName'];
        $variableName = $this->getSingleEntityVariable();
        $contain = $config['contain'] ?? $this->getDefaultViewContain($config['lazyLoadHistory'] ?? false);
        $entity = $this->fetchTable($tableName)->get($id, compact('contain'));
```

por:

```php
    protected function viewTicket(int $id, array $config = []): ?Response
    {
        $variableName = $this->getSingleEntityVariable();
        $contain = $config['contain'] ?? $this->getDefaultViewContain($config['lazyLoadHistory'] ?? false);
        $entity = $this->fetchTable('Tickets')->get($id, compact('contain'));
```

### - [ ] Step 2.10: Replace callsite in TicketListingTrait

`src/Controller/Trait/TicketListingTrait.php` línea 57. Reemplazar:

```php
        [$table, , ] = $this->getEntityComponents();
```

por:

```php
        $table = $this->fetchTable('Tickets');
```

### - [ ] Step 2.11: Replace callsite in TicketHistoryTrait

`src/Controller/Trait/TicketHistoryTrait.php` líneas 43-46. Reemplazar:

```php
            $components = $this->getEntityComponents();
            $tableName = $components['tableName'];
            $foreignKey = $components['foreignKey'];
            $this->fetchTable($tableName)->get($id);
```

por:

```php
            $foreignKey = 'ticket_id';
            $this->fetchTable('Tickets')->get($id);
```

Y reemplazar el uso de `$foreignKey` en la query de la línea 50 — ya está parametrizado, queda igual:

```php
                ->where([$foreignKey => $id])
```

(Alternativamente, inlinear: `->where(['ticket_id' => $id])` y borrar la línea `$foreignKey = ...`. Es preferencia de estilo; mantenerlo si reduce diff.)

### - [ ] Step 2.12: Delete the helper from TicketServiceInitializerTrait

`src/Controller/Trait/TicketServiceInitializerTrait.php` líneas 79-100. Borrar completamente:

```php
    // region: TicketSystemController helpers

    /**
     * @return array{table: \Cake\ORM\Table, service: ?\App\Service\TicketPipelineService, displayName: string, tableName: string, foreignKey: string, 0: \Cake\ORM\Table, 1: ?\App\Service\TicketPipelineService, 2: string}
     */
    private function getEntityComponents(): array
    {
        $components = [
            'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
            'service' => $this->ticketPipeline ?? null,
            'displayName' => 'Ticket',
            'tableName' => 'Tickets',
            'foreignKey' => 'ticket_id',
        ];

        return array_merge($components, [
            0 => $components['table'],
            1 => $components['service'],
            2 => $components['displayName'],
        ]);
    }
```

**Conservar** el método `getHistoryTable()` que está justo después en el mismo region. Solo se borra `getEntityComponents()` y el comentario `// region: TicketSystemController helpers` puede quedar si hay otros helpers dentro de la región (sí: `getHistoryTable`).

### - [ ] Step 2.13: Verify no remaining references

Run:
```bash
grep -rn "getEntityComponents" src/ tests/
```

Expected: **0 hits**. Si aparece alguna referencia que el plan no cubrió, reemplazar antes de seguir.

### - [ ] Step 2.14: Run test suite

Run:
```bash
composer test
```

Expected: PASS — mismo número de tests, sin fallos nuevos.

### - [ ] Step 2.15: Run static analysis on touched files

Run:
```bash
vendor/bin/phpstan analyse src/Controller/Trait
```

Expected: sin nuevos errores. Si PHPStan se queja de tipo en `$this->ticketPipeline` (por ejemplo, "property not declared on trait"), verificar que el property exists en `TicketsController` o documentar como `@property` en el trait.

### - [ ] Step 2.16: Run code style

Run:
```bash
composer cs-fix && composer cs-check
```

Expected: `cs-check` sin errores nuevos.

### - [ ] Step 2.17: Commit

```bash
git add src/Controller/Trait/
git commit -m "$(cat <<'EOF'
refactor(controller): drop unused getEntityComponents helper (MED-4)

The helper was a residue from an earlier attempt at supporting multiple
entity types via $entityType. All 11 callsites resolved the same five
literal values (table=Tickets, service=ticketPipeline, displayName=
'Ticket', tableName='Tickets', foreignKey='ticket_id'), so the
indirection abstracted nothing.

Inlined literals improve gettext extraction (interpolation of
$entityName previously bypassed catalog scanning) and let PHPStan
infer fetchTable('Tickets') as Cake\ORM\Table instead of mixed.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `Ticket::fromEmailIngest()` Factory (MED-7)

**Files:**
- Modify: `src/Model/Entity/Ticket.php` (agregar método estático al final, antes del cierre de clase)
- Modify: `tests/TestCase/Model/Entity/TicketTest.php` (agregar tests)
- Modify: `src/Service/TicketIngestionService.php` (líneas 109-129)

### - [ ] Step 3.1: Write failing test for the factory

Agregar al final de `tests/TestCase/Model/Entity/TicketTest.php` (antes del cierre de clase):

```php
    public function testFromEmailIngestSetsInitialStatusAndPriority(): void
    {
        $ticket = Ticket::fromEmailIngest(
            ticketNumber: 'T-0001',
            requesterId: 42,
            subject: 'Mi pedido',
            sanitizedDescription: '<p>cuerpo limpio</p>',
            channel: 'email',
            sourceEmail: 'cliente@example.com',
        );

        self::assertSame('nuevo', $ticket->status);
        self::assertSame('media', $ticket->priority);
        self::assertSame('T-0001', $ticket->ticket_number);
        self::assertSame(42, $ticket->requester_id);
        self::assertSame('Mi pedido', $ticket->subject);
        self::assertSame('<p>cuerpo limpio</p>', $ticket->description);
        self::assertSame('email', $ticket->channel);
        self::assertSame('cliente@example.com', $ticket->source_email);
    }

    public function testFromEmailIngestFallsBackToSinAsuntoWhenSubjectEmpty(): void
    {
        $ticket = Ticket::fromEmailIngest(
            ticketNumber: 'T-0002',
            requesterId: 1,
            subject: '',
            sanitizedDescription: '',
            channel: 'email',
            sourceEmail: 'x@y.z',
        );

        self::assertSame('(Sin asunto)', $ticket->subject);
    }

    public function testFromEmailIngestPassesThroughGmailIdsAndRecipients(): void
    {
        $emailTo = [['email' => 'a@b.com', 'name' => 'A B']];
        $emailCc = [['email' => 'c@d.com', 'name' => 'C D']];

        $ticket = Ticket::fromEmailIngest(
            ticketNumber: 'T-0003',
            requesterId: 1,
            subject: 'x',
            sanitizedDescription: '',
            channel: 'email',
            sourceEmail: 'x@y.z',
            gmailMessageId: 'gm-msg-1',
            gmailThreadId: 'gm-thr-1',
            emailTo: $emailTo,
            emailCc: $emailCc,
        );

        self::assertSame('gm-msg-1', $ticket->gmail_message_id);
        self::assertSame('gm-thr-1', $ticket->gmail_thread_id);
        self::assertSame($emailTo, $ticket->email_to);
        self::assertSame($emailCc, $ticket->email_cc);
    }
```

### - [ ] Step 3.2: Run tests to verify they fail

Run:
```bash
vendor/bin/phpunit tests/TestCase/Model/Entity/TicketTest.php --filter testFromEmailIngest
```

Expected: **FAIL** con `Error: Call to undefined method App\Model\Entity\Ticket::fromEmailIngest()` para los 3 tests.

### - [ ] Step 3.3: Add factory to Ticket entity

`src/Model/Entity/Ticket.php` — agregar después del último método (`canBeAssignedTo`, línea 253) y antes del cierre de clase:

```php
    // endregion

    // region: Domain factories

    /**
     * Construye un Ticket nuevo a partir de un email ingestado (Gmail / WA bot).
     *
     * Encapsula la decisión de qué status y priority iniciales aplicar, y el
     * fallback de subject vacío. Bypasea el cierre de $_accessible — legítimo
     * porque es la entidad construyéndose a sí misma; el cierre sigue
     * protegiendo mass-assign desde controllers / marshalling.
     *
     * @param string $ticketNumber Number generated by NumberGenerationService
     * @param int $requesterId Resolved requester user id
     * @param string $subject Email subject (trim ya aplicado por el caller); usa '(Sin asunto)' si vacío
     * @param string $sanitizedDescription Body ya pasado por HtmlSanitizerTrait
     * @param string $channel TicketConstants::CHANNEL_EMAIL | CHANNEL_WHATSAPP
     * @param string $sourceEmail From-address del remitente
     * @param string|null $gmailMessageId Gmail message id si vino por Gmail API
     * @param string|null $gmailThreadId Gmail thread id si vino por Gmail API
     * @param mixed $emailTo Recipients array (To); se persiste tal cual
     * @param mixed $emailCc Recipients array (Cc); se persiste tal cual
     */
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

        return $ticket;
    }

    // endregion
```

### - [ ] Step 3.4: Run tests to verify they pass

Run:
```bash
vendor/bin/phpunit tests/TestCase/Model/Entity/TicketTest.php --filter testFromEmailIngest
```

Expected: **PASS** — 3 tests, 11+ assertions.

### - [ ] Step 3.5: Adopt the factory in TicketIngestionService

`src/Service/TicketIngestionService.php` líneas 109-129. Reemplazar:

```php
        // Create ticket
        $ticket = $ticketsTable->newEntity([
            'ticket_number' => $ticketNumber,
            'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
            'gmail_thread_id' => $emailData['gmail_thread_id'] ?? null,
            'subject' => $subject,
            'description' => $description,
            'status' => TicketConstants::STATUS_NUEVO,
            'priority' => TicketConstants::PRIORITY_MEDIA,
            'requester_id' => $user->id,
            'channel' => $channel,
            'source_email' => $fromEmail,
        ], ['accessibleFields' => [
            'ticket_number' => true, 'gmail_message_id' => true, 'gmail_thread_id' => true,
            'status' => true, 'requester_id' => true, 'channel' => true, 'source_email' => true,
        ]]);
        assert($ticket instanceof Ticket);

        // Set email recipients directly (bypass marshalling to avoid validation issues)
        $ticket->email_to = !empty($emailData['email_to']) ? $emailData['email_to'] : null;
        $ticket->email_cc = !empty($emailData['email_cc']) ? $emailData['email_cc'] : null;
```

por:

```php
        // Build ticket via domain factory: status/priority defaults and
        // (Sin asunto) fallback live in the entity, not in this IO layer.
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
        );
```

Nota: el factory aplica el fallback `(Sin asunto)` cuando `$subject === ''`. El código pre-factory ya hacía `if (empty($subject)) { $subject = '(Sin asunto)'; }` en líneas 95-99 — borrar ese bloque también porque el factory ahora lo hace:

```php
        // Ensure subject is not empty   <-- BORRAR ESTE BLOQUE
        $subject = trim($emailData['subject'] ?? '');
        if (empty($subject)) {
            $subject = '(Sin asunto)';
        }
```

Reemplazar por una sola línea (solo trim, sin fallback):

```php
        $subject = trim($emailData['subject'] ?? '');
```

### - [ ] Step 3.6: Run full test suite

Run:
```bash
composer test
```

Expected: PASS — todos los tests pasan, incluidos los 3 nuevos del factory.

### - [ ] Step 3.7: Run static analysis

Run:
```bash
vendor/bin/phpstan analyse src/Model/Entity/Ticket.php src/Service/TicketIngestionService.php
```

Expected: sin nuevos errores. El factory usa solo property writes, que PHPStan acepta en CakePHP entities.

### - [ ] Step 3.8: Run code style

Run:
```bash
composer cs-fix && composer cs-check
```

Expected: `cs-check` sin errores nuevos.

### - [ ] Step 3.9: Commit

```bash
git add src/Model/Entity/Ticket.php src/Service/TicketIngestionService.php tests/TestCase/Model/Entity/TicketTest.php
git commit -m "$(cat <<'EOF'
refactor(domain): introduce Ticket::fromEmailIngest factory (MED-7)

Encapsulates the construction of a new Ticket from ingested email data.
Moves the initial-status / initial-priority decisions and the
(Sin asunto) fallback into the entity, where they belong. The factory
bypasses $_accessible (safe — the entity is constructing itself; the
accessible map still guards mass-assign from controllers).

Removes 18 lines of newEntity + accessibleFields override + direct
property bypass from TicketIngestionService::createFromEmail.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Update Audit Document

**Files:**
- Modify: `docs/audits/2026-05-14-tickets-module-audit.md`

### - [ ] Step 4.1: Update §1 summary table

`docs/audits/2026-05-14-tickets-module-audit.md` líneas 14-20. Reemplazar:

```markdown
| Indicador | Valor inicial | Estado actual (2026-05-16) |
|---|---|---|
| Salud arquitectónica global | **68%** | **~85%** — 5 altos + 2 críticos + 1 medio cerrados |
| Hallazgos Críticos (rojo) | 3 | 1 (CRIT-1 y CRIT-2 cerrados) |
| Hallazgos Altos (naranja) | 6 | 1 (HIGH-1/2/3/5/6 cerrados; HIGH-4 abierto) |
| Hallazgos Medios (amarillo) | 7 | 6 (MED-1 cerrado) |
| Hallazgos Bajos (verde) | 4 | 4 |
```

por:

```markdown
| Indicador | Valor inicial | Estado actual (2026-05-16) |
|---|---|---|
| Salud arquitectónica global | **68%** | **~90%** — 5 altos + 2 críticos + 4 medios cerrados + 1 regresión cerrada |
| Hallazgos Críticos (rojo) | 3 | 1 (CRIT-1 y CRIT-2 cerrados) |
| Hallazgos Altos (naranja) | 6 | 1 (HIGH-1/2/3/5/6 cerrados; HIGH-4 abierto) |
| Hallazgos Medios (amarillo) | 7 | 3 (MED-1/4/6/7 cerrados) |
| Hallazgos Bajos (verde) | 4 | 4 |
```

### - [ ] Step 4.2: Update §5 — mark MED-4/6/7 as closed

`docs/audits/2026-05-14-tickets-module-audit.md` líneas 171-180. Reemplazar la tabla por:

```markdown
| ID | Hallazgo | Archivo | Estado |
|---|---|---|---|
| MED-1 | Notificaciones de "response" llamadas directo, no por bus (asimetría EDA) | `TicketPipelineService.php:156` | **Cerrado 2026-05-16** |
| MED-2 | `DomainEvent extends Cake\Event\Event` + `FrozenTime::now()` en service: fugas de framework en dominio | `Domain/Event/DomainEvent.php:19`, `TicketPipelineService.php:19` | Abierto |
| MED-3 | Logs sin `correlation_id` / `event_id` / `actor_id` consistentes | múltiples | Abierto |
| MED-4 | `getEntityComponents()` residual del refactor de `$entityType` | `TicketServiceInitializerTrait.php:81-96` | **Cerrado 2026-05-16** |
| MED-5 | Chain of Responsibility ausente en ingesta de email (filtros concatenados imperativamente) | `GmailImportService.php:103-148`, `TicketIngestionService.php:59-164` | Abierto |
| MED-6 | Lazy DI inconsistente: N8n lazy, Email/WhatsApp eager | `TicketNotificationService.php:36-49` | **Cerrado obsoleto 2026-05-16** |
| MED-7 | Builder ausente; `Ticket` se construye con array marshalling + bypass mixto | `TicketIngestionService.php:110-129` | **Cerrado 2026-05-16** |
```

### - [ ] Step 4.3: Update §9 — acciones priorizadas medio

`docs/audits/2026-05-14-tickets-module-audit.md` líneas 243-252. Reemplazar:

```markdown
### Medio (mes 2)

| # | Acción | Estado |
|---|---|---|
| 9 | `TicketResponded` event + handler (cierra asimetría del bus) | **Completado 2026-05-16** |
| 10 | Estandarizar contexto de logs (`ticket_id`, `actor_id`, `event_id`, `occurred_at`) | Pendiente |
| 11 | Eliminar `getEntityComponents()` residual | Pendiente |
| 12 | Lazy DI uniforme para Email/WhatsApp en `TicketNotificationService` | Pendiente |
| 13 | Factory `Ticket::fromEmailIngest()` | Pendiente |
| 14 | Rate Limiter outbound WhatsApp/n8n | Pendiente |
| 15 | Inyectar `ClockInterface` en `TicketPipelineService` | Pendiente |
```

por:

```markdown
### Medio (mes 2)

| # | Acción | Estado |
|---|---|---|
| 9 | `TicketResponded` event + handler (cierra asimetría del bus) | **Completado 2026-05-16** |
| 10 | Estandarizar contexto de logs (`ticket_id`, `actor_id`, `event_id`, `occurred_at`) | Pendiente |
| 11 | Eliminar `getEntityComponents()` residual | **Completado 2026-05-16** |
| 12 | Lazy DI uniforme para Email/WhatsApp en `TicketNotificationService` | **Cerrado obsoleto 2026-05-16** (refactor previo eliminó la instanciación interna) |
| 13 | Factory `Ticket::fromEmailIngest()` | **Completado 2026-05-16** |
| 14 | Rate Limiter outbound WhatsApp/n8n | Pendiente |
| 15 | Inyectar `ClockInterface` en `TicketPipelineService` | Pendiente |
```

### - [ ] Step 4.4: Append §11 entry

`docs/audits/2026-05-14-tickets-module-audit.md` — agregar al final del archivo (después del último bloque de progreso, el del 2026-05-16 notification refactor):

```markdown
### 2026-05-16 (bis) — MED-4 + MED-6 + MED-7 cerrados + regresión Gmail-ingest reparada

**Hallazgos cubiertos:** MED-4 (helper residual), MED-6 (lazy DI obsoleto), MED-7 (factory `Ticket::fromEmailIngest()`). Más una regresión runtime que el auditor original no detectó.

**Regresión descubierta:** El refactor del 2026-05-16 (notification layer) eliminó el método `getN8nService()` y cambió la firma del constructor de `TicketNotificationService` a `(array $strategies, array $channels)`. Pero `TicketIngestionService` líneas 49 y 151 seguían usando el API viejo, así que `bin/cake import_gmail` y el webhook `POST /webhooks/gmail/import` crasheaban con `TypeError` antes de procesar el primer mensaje. El path estaba sin cobertura de tests, por eso el auditor no lo capturó.

**Cambios:**
- `src/Service/TicketIngestionService.php`: reemplazada dependencia indirecta vía `TicketNotificationService` por inyección directa de `N8nService`. N8n no es un canal de notificación a personas — es un webhook event-to-system; la dependencia anterior era una fachada accidental. Adoptado `Ticket::fromEmailIngest()` para construir tickets desde email.
- `src/Model/Entity/Ticket.php`: nuevo método estático `fromEmailIngest()`. Encapsula los defaults de status/priority y el fallback `(Sin asunto)`. Bypasea `$_accessible` (legítimo desde dentro de la entidad).
- `src/Controller/Trait/*`: eliminado helper `getEntityComponents()` y sus 11 callsites en 5 traits. El helper era residuo de un intento previo de soportar múltiples entity types — todos los callsites resolvían los mismos 5 literales.
- `tests/TestCase/Service/TicketIngestionServiceTest.php`: nuevo. 2 tests de construcción bloquean la regresión.
- `tests/TestCase/Model/Entity/TicketTest.php`: +3 tests del factory.

**Despliegue:** sin migraciones, sin cambios de firma pública (`createFromEmail` y `createCommentFromEmail` mantienen sus signatures). Rollback granular vía commits separados.

**Validaciones:**
- `composer test`: PASS — 5 tests nuevos, suite completa verde respecto al baseline.
- `phpstan analyse src`: sin nuevos errores.
- `composer cs-check`: solo errores baseline en archivos no tocados.
- `grep -r "getEntityComponents" src/`: 0 hits.

**Lección operativa:** El refactor del 2026-05-16 tocó la API pública de `TicketNotificationService` sin grep transversal de callers. Para refactors similares, agregar a la checklist un grep del método/propiedad removido y un test de smoke que reconstruya las dependencias top-level (e.g. `GmailImportService::fromSettings`).
```

### - [ ] Step 4.5: Commit

```bash
git add docs/audits/2026-05-14-tickets-module-audit.md
git commit -m "$(cat <<'EOF'
docs(audit): close MED-4/MED-6/MED-7 + Gmail-ingest regression in §11

Updates the 2026-05-14 tickets module audit:
  - §1 summary: health 85% → 90%, mediums open 6 → 3.
  - §5 finding statuses for MED-1/4/6/7.
  - §9 priority actions 11/12/13.
  - §11: new bitácora entry describing the 2026-05-16 cleanup batch
    and the Gmail-ingest regression discovered during recon (and the
    operational lesson for future refactors that touch public APIs).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final Verification

### - [ ] Step F.1: Full sanity check

Run, in order:

```bash
composer test
composer cs-fix && composer cs-check
vendor/bin/phpstan analyse src
grep -rn "getEntityComponents" src/ tests/
git log --oneline -5
```

Expected:
- All tests pass.
- `cs-check`: solo errores baseline en archivos no tocados.
- `phpstan`: sin nuevos errores.
- `grep`: 0 hits.
- `git log`: 4 commits nuevos en orden Task 1 → 2 → 3 → 4 sobre `61e693e` (spec commit).

### - [ ] Step F.2: Manual smoke (opcional)

Si hay credenciales Gmail configuradas en dev:

```bash
bin/cake import_gmail --max 1
```

Expected: el comando arranca y procesa (o reporta "no new messages"); no crashea en construcción del servicio.

---

## Done When

- [ ] 4 commits en main (o branch dedicada) en el orden Task 1-2-3-4.
- [ ] `composer test`, `composer cs-check`, `phpstan analyse src` todos verdes respecto al baseline.
- [ ] `grep getEntityComponents src/ tests/` retorna 0 hits.
- [ ] §11 de la auditoría tiene la nueva entrada del 2026-05-16 (bis).
- [ ] Si se hace smoke manual de `import_gmail`, no produce TypeError.
