# Auditoría Fase 3 (riesgo) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar items 5.4 (mass-assignment + autorización server-side de asignación) y 5.7 (auditoría de tipos de FK) del documento `docs/audits/2026-05-07-architecture-audit.md`.

**Architecture:** Defense in depth para 5.4 — guard temprano en controller (UX), guard final en `TicketPipelineService::assign` (invariante para todo caller), y `assignee_id => false` en el `_accessible` de la entidad para cerrar mass-assignment. 5.7 es lectura de migrations + tabla comparativa anexada al doc de auditoría.

**Tech Stack:** PHP 8.1+, CakePHP 5.x, MySQL/MariaDB. Sin tests automáticos: verificación manual con servidor local + browser/cURL.

**Spec:** `docs/superpowers/specs/2026-05-08-audit-fase3-riesgo-design.md`

---

## File Structure

**Crear:**
- `src/Service/Exception/UnauthorizedAssignmentException.php` — excepción de dominio para asignación no autorizada.

**Modificar:**
- `src/Model/Entity/Ticket.php:61` — `'assignee_id' => false`.
- `src/Service/TicketPipelineService.php:35-45` — constructor con DI de `AuthorizationService`.
- `src/Service/TicketPipelineService.php:194-241` — firma + guards en `assign()`.
- `src/Controller/Trait/TicketActionsTrait.php:108-144` — guard de actor + propagar actor + capturar excepción.
- `src/Controller/Trait/TicketBulkTrait.php:56-85` — guard de actor + propagar actor + capturar excepción.
- `docs/audits/2026-05-07-architecture-audit.md` — Anexo 5 con cierres 5.4 y 5.7.

**Sin cambios de schema** (5.7 es read-only en este plan).

---

## Task 1: Crear `UnauthorizedAssignmentException`

**Files:**
- Create: `src/Service/Exception/UnauthorizedAssignmentException.php`

- [ ] **Step 1: Crear el archivo de la excepción**

Crear `src/Service/Exception/UnauthorizedAssignmentException.php` con:

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use DomainException;

/**
 * Thrown when an attempt is made to assign a ticket without
 * the required authorization, either because the actor lacks
 * the role or because the target user/ticket cannot accept it.
 */
class UnauthorizedAssignmentException extends DomainException
{
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

Ejecutar:

```bash
php -l src/Service/Exception/UnauthorizedAssignmentException.php
```

Expected: `No syntax errors detected in src/Service/Exception/UnauthorizedAssignmentException.php`

- [ ] **Step 3: Verificar autoload**

Ejecutar:

```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\\Service\\Exception\\UnauthorizedAssignmentException'));"
```

Expected: `bool(true)`

- [ ] **Step 4: Commit**

```bash
git add src/Service/Exception/UnauthorizedAssignmentException.php
git commit -m "feat(exception): add UnauthorizedAssignmentException for ticket assignment guards"
```

---

## Task 2: Cerrar mass-assignment de `assignee_id`

**Files:**
- Modify: `src/Model/Entity/Ticket.php:61`

- [ ] **Step 1: Verificar que ningún flujo patchea Tickets desde request data**

Ejecutar:

```bash
grep -rn "patchEntity" src/Controller src/Service | grep -i ticket
```

Expected: solo aparece en docblocks de `TicketsTable`, `TicketCommentsTable`, `TicketHistoryTable`, `TicketTagsTable`, `TicketFollowersTable` (anotaciones IDE-style autogeneradas), **ningún caller real**. Si aparece otra cosa, detener y revisar.

- [ ] **Step 2: Cambiar `_accessible` de `assignee_id`**

Editar `src/Model/Entity/Ticket.php` línea 61:

```diff
-        'assignee_id' => true,
+        'assignee_id' => false,
```

Resultado esperado del bloque completo:

```php
    protected array $_accessible = [
        'ticket_number' => false,
        'gmail_message_id' => false,
        'gmail_thread_id' => false,
        'email_to' => true,
        'email_cc' => true,
        'subject' => true,
        'description' => true,
        'status' => false,
        'priority' => true,
        'requester_id' => false,
        'assignee_id' => false,
        'channel' => false,
        ...
    ];
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Model/Entity/Ticket.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke en runtime**

Levantar el servidor (`bin/cake server` o `docker compose up -d`), abrir un ticket en la UI con sesión de admin, asignarlo desde el dropdown.

Expected: la asignación sigue funcionando (porque `TicketPipelineService::assign` setea `$entity->assignee_id` por asignación directa, no por mass-assignment).

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/Ticket.php
git commit -m "refactor(entity): close mass-assignment of Ticket::assignee_id

Assignment is a domain operation with rules (actor authorization,
target validity, ticket lock) that cannot be expressed via mass-assignment.
Only TicketPipelineService::assign is the legitimate mutation point.
"
```

---

## Task 3: DI de `AuthorizationService` + guards en `TicketPipelineService::assign`

**Files:**
- Modify: `src/Service/TicketPipelineService.php` (constructor + método `assign`)

- [ ] **Step 1: Añadir use statements**

En `src/Service/TicketPipelineService.php`, añadir tras los `use` existentes (líneas 6-12):

```php
use App\Model\Entity\User;
use App\Service\Exception\UnauthorizedAssignmentException;
```

- [ ] **Step 2: Añadir propiedad y constructor parameter**

Modificar la sección de propiedades y constructor (líneas 24-45). Resultado esperado:

```php
    private TicketCommentService $comments;
    private TicketAttachmentService $attachments;
    private TicketNotificationService $notifications;
    private AuthorizationService $authService;
    private ?array $systemConfig;

    /**
     * @param array|null $systemConfig System settings snapshot
     * @param \App\Service\TicketCommentService|null $comments Optional injected comment service
     * @param \App\Service\TicketAttachmentService|null $attachments Optional injected attachment service
     * @param \App\Service\TicketNotificationService|null $notifications Optional injected notification service
     * @param \App\Service\AuthorizationService|null $authService Optional injected authorization service
     */
    public function __construct(
        ?array $systemConfig = null,
        ?TicketCommentService $comments = null,
        ?TicketAttachmentService $attachments = null,
        ?TicketNotificationService $notifications = null,
        ?AuthorizationService $authService = null,
    ) {
        $this->systemConfig = $systemConfig;
        $this->comments = $comments ?? new TicketCommentService($systemConfig);
        $this->attachments = $attachments ?? new TicketAttachmentService();
        $this->notifications = $notifications ?? new TicketNotificationService($systemConfig);
        $this->authService = $authService ?? new AuthorizationService();
    }
```

- [ ] **Step 3: Reemplazar método `assign()` completo**

Reemplazar el método `assign()` actual (líneas ~186-241). Resultado esperado:

```php
    /**
     * Assign ticket to a user.
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param int|null $assigneeId New assignee user ID (0 or null clears)
     * @param int|null $userId User performing the change (for history)
     * @param mixed $actor Actor identity (User entity or Authentication identity)
     * @return bool
     * @throws \App\Service\Exception\UnauthorizedAssignmentException When actor lacks role or target is invalid
     */
    public function assign(
        EntityInterface $entity,
        ?int $assigneeId,
        ?int $userId = null,
        mixed $actor = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $usersTable = $this->fetchTable('Users');

        // Guard 1: actor must be allowed to assign tickets
        if ($actor !== null && $this->authService->isAssignmentDisabled($actor)) {
            throw new UnauthorizedAssignmentException(
                'El usuario no tiene permisos para asignar tickets.',
            );
        }

        // Guard 2: target must be a valid assignee for this ticket (only when assigning, not clearing)
        $normalizedAssigneeId = $assigneeId === 0 || $assigneeId === '0' ? null : $assigneeId;
        if ($normalizedAssigneeId !== null) {
            $targetUser = $usersTable->get($normalizedAssigneeId);
            assert($targetUser instanceof User);
            if (!$entity->canBeAssignedTo($targetUser)) {
                throw new UnauthorizedAssignmentException(
                    'No es posible asignar este ticket a ese usuario.',
                );
            }
        }

        $oldAssigneeId = $entity->assignee_id;
        $entity->assignee_id = $normalizedAssigneeId;

        if (!$table->save($entity)) {
            $errors = $entity->getErrors();
            Log::error("Failed to assign ticket - ID: {$entity->id}");
            Log::error("Assignment details - New assignee: {$assigneeId}, Old assignee: {$oldAssigneeId}");
            Log::error('Validation errors: ' . print_r($errors, true));
            Log::error('Dirty fields: ' . print_r($entity->getDirty(), true));

            return false;
        }

        $oldAssigneeName = 'Sin asignar';
        if ($oldAssigneeId) {
            $oldUser = $usersTable->get($oldAssigneeId);
            $oldAssigneeName = $oldUser->first_name . ' ' . $oldUser->last_name;
        }

        $newAssigneeName = 'Sin asignar';
        if ($normalizedAssigneeId) {
            $newUser = $usersTable->get($normalizedAssigneeId);
            $newAssigneeName = $newUser->first_name . ' ' . $newUser->last_name;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'assignee_id',
            $oldAssigneeName,
            $newAssigneeName,
            $userId,
            "Asignado a {$newAssigneeName}",
        );

        $this->comments->addComment($entity->id, $userId, "Asignado a {$newAssigneeName}", 'internal', true);

        return true;
    }
```

Notas:
- Se mantiene la mecánica de save + history + comment interno intacta.
- La normalización de `$assigneeId` (0/'0' → null) se hace una sola vez antes del guard 2 y se reutiliza después.
- `$actor = null` deja a CLI/jobs sin chequeo de actor; mantienen el chequeo de target.

- [ ] **Step 4: Verificar sintaxis**

```bash
php -l src/Service/TicketPipelineService.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Smoke**

Levantar servidor, asignar un ticket desde la UI como `admin`.

Expected: asignación funciona, audit en `ticket_history` se crea, comentario interno aparece en la vista del ticket.

- [ ] **Step 6: Commit**

```bash
git add src/Service/TicketPipelineService.php
git commit -m "feat(pipeline): inject AuthorizationService and add assign guards

- Constructor accepts optional AuthorizationService (defaults to new instance).
- assign() accepts optional \$actor parameter and throws
  UnauthorizedAssignmentException when actor cannot assign or target
  cannot be assigned (locked ticket, inactive or non-staff user).
- Invariant guard runs server-side regardless of caller (controller, CLI, future webhooks).
"
```

---

## Task 4: Guard de actor en `TicketActionsTrait::assignTicket`

**Files:**
- Modify: `src/Controller/Trait/TicketActionsTrait.php`

- [ ] **Step 1: Añadir use statements**

En `src/Controller/Trait/TicketActionsTrait.php`, añadir tras los `use` existentes (líneas 6-9):

```php
use App\Service\AuthorizationService;
use App\Service\Exception\UnauthorizedAssignmentException;
```

- [ ] **Step 2: Reemplazar método `assignTicket` completo**

Reemplazar el método `assignTicket` (líneas 108-144). Resultado esperado:

```php
    protected function assignTicket(
        int $entityId,
        $assigneeId,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $assigneeId = $this->normalizeAssigneeId($assigneeId);
        $userId = $this->getCurrentUserId();
        $actor = $this->Authentication->getIdentity();

        $components = $this->getEntityComponents();
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        // Early actor guard: better UX than tripping the service exception
        $authService = new AuthorizationService();
        if ($authService->isAssignmentDisabled($actor)) {
            $this->Flash->error(__('No tienes permisos para asignar tickets.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        if ($entity->isLocked()) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $components['service']->assign($entity, $assigneeId, $userId, $actor);
        } catch (UnauthorizedAssignmentException $e) {
            $this->Flash->error($e->getMessage());

            return $this->redirect(['action' => $redirectAction]);
        }

        if ($result) {
            $this->Flash->success(__("{$entityName} asignada correctamente."));
        } else {
            $this->Flash->error(__("No se pudo asignar la {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }
```

Notas:
- Se elimina el chequeo manual previo de `canBeAssignedTo` (líneas 127-134 del original) — ahora vive dentro del service y se propaga vía excepción. Defense in depth: el chequeo está en el service para todos los callers.
- `$actor` se obtiene una vez y se pasa al service.

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Controller/Trait/TicketActionsTrait.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke**

Levantar servidor, asignar un ticket como `admin` (debe funcionar) y como `user` (botón oculto, pero forzar POST con cURL desde sesión `user` debe redirigir con flash error).

Comando de prueba con sesión activa de un usuario sin permisos (reemplazar `<COOKIE>` con el cookie de sesión de un usuario `role=user`):

```bash
curl -i -X POST http://localhost:8765/tickets/assign/1 \
  -H "Cookie: <COOKIE>" \
  -d "assignee_id=2&_csrfToken=<TOKEN>"
```

Expected: HTTP 302 redirect, y al seguirlo aparece flash "No tienes permisos para asignar tickets."

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Trait/TicketActionsTrait.php
git commit -m "feat(controller): early actor guard in assignTicket + propagate actor to service

Removes manual canBeAssignedTo check from controller (now lives in service
as invariant). Adds early isAssignmentDisabled check to short-circuit
unauthorized actors before hitting the pipeline service, with clear flash
error and redirect.
"
```

---

## Task 5: Guard de actor en `TicketBulkTrait::bulkAssignTickets`

**Files:**
- Modify: `src/Controller/Trait/TicketBulkTrait.php`

- [ ] **Step 1: Añadir use statements**

En `src/Controller/Trait/TicketBulkTrait.php`, añadir tras los `use` existentes (líneas 6-8):

```php
use App\Service\AuthorizationService;
use App\Service\Exception\UnauthorizedAssignmentException;
```

- [ ] **Step 2: Reemplazar método `bulkAssignTickets` completo**

Reemplazar el método `bulkAssignTickets` (líneas 56-85). Resultado esperado:

```php
    /**
     * Bulk assign tickets to an agent.
     */
    protected function bulkAssignTickets(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $agentId = $this->request->getData('agent_id') ?? $this->request->getData('assignee_id');
        $agentId = $this->normalizeAssigneeId($agentId);
        $actor = $this->Authentication->getIdentity();
        $userId = $actor ? (int)$actor->get('id') : 1;
        [$table, $service, $entityName] = $this->getEntityComponents();

        // Early actor guard: abort whole batch if actor cannot assign
        $authService = new AuthorizationService();
        if ($authService->isAssignmentDisabled($actor)) {
            $this->Flash->error(__('No tienes permisos para asignar tickets.'));

            return $this->redirect(['action' => 'index']);
        }

        $successCount = 0;
        $errorCount = 0;
        $unauthorizedCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                $service->assign($entity, $agentId, $userId, $actor);
                $successCount++;
            } catch (UnauthorizedAssignmentException $e) {
                $unauthorizedCount++;
                Log::warning("Bulk assign blocked for ticket {$entityId}: " . $e->getMessage());
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk assign ticket {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} {$entityName}(s) asignado(s) correctamente."));
        }
        if ($unauthorizedCount > 0) {
            $this->Flash->warning(__("{$unauthorizedCount} {$entityName}(s) no se asignaron por reglas de autorización (lockeado, usuario inactivo o no-staff)."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} {$entityName}(s) no pudieron ser asignados."));
        }

        return $this->redirect(['action' => 'index']);
    }
```

Notas:
- Decisión de comportamiento: actor sin permisos aborta el lote entero (early return). Tickets individuales que fallen el guard de target (locked, inactivo, no-staff) se contabilizan en `$unauthorizedCount` y se logean como warning, sin abortar el lote.
- `Log::warning` ya está importado vía `use Cake\Log\Log;` (línea 7 actual).

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Controller/Trait/TicketBulkTrait.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke**

Levantar servidor. Como `admin`, seleccionar 2-3 tickets en index, hacer bulk assign a un agente.

Expected: success flash con conteo correcto. Revisar `ticket_history` por cada ticket asignado.

Como `user` (forzando POST con cURL):

```bash
curl -i -X POST http://localhost:8765/tickets/bulk-assign \
  -H "Cookie: <COOKIE_USER>" \
  -d "entity_ids=1,2,3&assignee_id=2&_csrfToken=<TOKEN>"
```

Expected: redirect, flash "No tienes permisos para asignar tickets.", ningún ticket modificado.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Trait/TicketBulkTrait.php
git commit -m "feat(controller): early actor guard in bulkAssignTickets + propagate actor

Aborts whole batch if actor cannot assign. Per-ticket UnauthorizedAssignmentException
(target locked/inactive/non-staff) is counted separately and logged as warning,
keeping the batch flowing for valid targets.
"
```

---

## Task 6: Verificación manual end-to-end de 5.4

**Files:** ninguno (solo testing).

Esta tarea ejecuta los 6 escenarios del spec sección 6 contra el código modificado.

Prerequisito: levantar el servidor (`bin/cake server` o `docker compose up -d`), tener al menos un usuario por cada rol (`admin`, `agent`, `user`) en la base.

- [ ] **Step 1: Caso 1 — POST de assign desde rol `user`**

Iniciar sesión como `user` en el browser. Copiar cookie de sesión.

```bash
curl -i -X POST http://localhost:8765/tickets/assign/1 \
  -H "Cookie: <COOKIE_USER>" \
  -d "assignee_id=2&_csrfToken=<TOKEN>"
```

Expected: HTTP 302; al seguir el redirect aparece flash "No tienes permisos para asignar tickets." Verificar en DB que `tickets.assignee_id` para el ticket 1 NO cambió.

- [ ] **Step 2: Caso 2 — Asignar ticket abierto como `agent`**

Iniciar sesión como `agent` en el browser. En la vista del ticket en estado `abierto`, asignar a otro agente desde el dropdown.

Expected: flash success "Ticket asignada correctamente." Nueva fila en `ticket_history` con `field_name='assignee_id'`. Comentario interno visible en la vista del ticket: "Asignado a {nombre}".

- [ ] **Step 3: Caso 3 — Asignar ticket resuelto como `agent`**

Como `agent`, abrir un ticket en estado `resuelto`. La UI normalmente esconde el dropdown (botón de assign deshabilitado en tickets locked); forzar POST con cURL:

```bash
curl -i -X POST http://localhost:8765/tickets/assign/<ID_RESUELTO> \
  -H "Cookie: <COOKIE_AGENT>" \
  -d "assignee_id=2&_csrfToken=<TOKEN>"
```

Expected: redirect, flash "No se puede modificar una Ticket en estado final." (early ticket-locked guard del controller). Si por alguna razón el lock check no atrapa, debería caer al guard del service y aparecer "No es posible asignar este ticket a ese usuario."

- [ ] **Step 4: Caso 4 — Bulk assign desde rol `user`**

Sesión como `user`:

```bash
curl -i -X POST http://localhost:8765/tickets/bulk-assign \
  -H "Cookie: <COOKIE_USER>" \
  -d "entity_ids=1,2,3&assignee_id=2&_csrfToken=<TOKEN>"
```

Expected: redirect, flash "No tienes permisos para asignar tickets.", `tickets.assignee_id` sin cambios para 1, 2, 3.

- [ ] **Step 5: Caso 5 — Asignar a usuario inactivo**

Como `agent`, intentar asignar un ticket a un usuario con `is_active = false`. Si la UI filtra usuarios inactivos del dropdown, forzar con cURL:

```bash
curl -i -X POST http://localhost:8765/tickets/assign/1 \
  -H "Cookie: <COOKIE_AGENT>" \
  -d "assignee_id=<ID_USER_INACTIVO>&_csrfToken=<TOKEN>"
```

Expected: redirect, flash "No es posible asignar este ticket a ese usuario." `tickets.assignee_id` sin cambios.

- [ ] **Step 6: Caso 6 — Asignar a usuario `role=user`**

Como `agent`, forzar asignación a un usuario con rol `user`:

```bash
curl -i -X POST http://localhost:8765/tickets/assign/1 \
  -H "Cookie: <COOKIE_AGENT>" \
  -d "assignee_id=<ID_USER_ROL_USER>&_csrfToken=<TOKEN>"
```

Expected: redirect, flash "No es posible asignar este ticket a ese usuario." `tickets.assignee_id` sin cambios.

- [ ] **Step 7: UI smoke — botones de asignar**

Como `user`, abrir el listado de tickets y la vista de un ticket individual.

Expected: el botón/dropdown de asignar NO se renderiza (regresión visual de los chequeos previos en `TicketListingTrait::index` y `TicketViewTrait::view`, que llaman a `isAssignmentDisabled` para `isAssignmentDisabled` en view-data).

- [ ] **Step 8: Documentar resultado**

Si todos los pasos pasaron, anotar "✅" en el plan checkbox. Si alguno falla, no continuar al Task 8 hasta resolver.

- [ ] **Step 9: cs-fix + cs-check**

Asegurar que los cambios de código pasan el linter:

```bash
composer cs-fix
composer cs-check
```

Expected: `No errors found.` Si hay cambios automáticos, commit:

```bash
git add -A
git commit -m "style: cs-fix on phase 3 risk changes"
```

---

## Task 7: Auditoría de tipos de FK (5.7)

**Files:**
- Read-only: `config/Migrations/20260430213127_Initial.php`

- [ ] **Step 1: Listar todas las FKs declaradas**

Ejecutar:

```bash
grep -nE "addForeignKey|addColumn.*'[a-z_]+_id'" config/Migrations/20260430213127_Initial.php
```

Anotar en un archivo temporal cada par (tabla origen, columna FK, tipo declarado).

- [ ] **Step 2: Listar todas las primary keys (`id`) y sus tipos**

```bash
grep -B1 -A6 "addColumn('id'" config/Migrations/20260430213127_Initial.php
```

Para cada tabla, anotar el tipo de la columna `id` (esperado: `integer` con `signed => false`).

- [ ] **Step 3: Construir tabla comparativa**

Para cada FK del paso 1, completar la fila con el tipo de la PK referenciada del paso 2. Columnas: `Tabla origen | Columna FK | Tipo FK | Tabla destino | Tipo PK destino | Coincide`.

Ejemplo de fila esperada:

| Tabla origen | Columna FK | Tipo FK | Tabla destino | Tipo PK destino | Coincide |
|---|---|---|---|---|---|
| `attachments` | `ticket_id` | `integer` (unsigned) | `tickets` | `integer` (unsigned) | ✅ |

- [ ] **Step 4: Identificar mismatches**

Cualquier fila donde "Coincide" sea ❌ debe destacarse. Posibles causas:
- Tipos distintos (`integer` vs `bigint`).
- Signedness distinta (`signed=true` vs `signed=false`).
- Limit distinto (`limit=11` vs `limit=20`).

- [ ] **Step 5: Decisión de cierre**

- **Si todos coinciden:** marcar 5.7 como cerrado ✅ y la tabla queda como evidencia para el anexo del Task 8.
- **Si hay mismatch:** documentar el conflicto en la tabla, marcar 5.7 como **pendiente** en el anexo, y NO crear migration de corrección en este plan. Cambiar tipo de FK en producción es alto riesgo y merece sesión dedicada con análisis de impacto, downtime, y plan de rollback.

- [ ] **Step 6: Guardar la tabla**

La tabla del paso 3 se incorpora directamente al Anexo 5 en Task 8. No commitear nada en este task — el commit se hace junto con el anexo.

---

## Task 8: Anexo 5 al documento de auditoría

**Files:**
- Modify: `docs/audits/2026-05-07-architecture-audit.md` (añadir al final).

- [ ] **Step 1: Añadir Anexo 5**

Al final de `docs/audits/2026-05-07-architecture-audit.md`, después del Anexo 4, añadir:

```markdown
### Anexo 5 — Cierre fase 3 riesgo (2026-05-08)

Cerrados:

- **5.4 ✅** Mass-assignment de `Ticket::assignee_id` cerrado (`_accessible: false`). Autorización defense in depth:
  - **Controller:** `TicketActionsTrait::assignTicket` y `TicketBulkTrait::bulkAssignTickets` chequean `AuthorizationService::isAssignmentDisabled($actor)` antes de delegar al service. UX: flash error + redirect inmediato.
  - **Service:** `TicketPipelineService::assign` recibe `$actor` (opcional, retro-compatible) e invoca el mismo chequeo + `Ticket::canBeAssignedTo($targetUser)` como invariante. Lanza `UnauthorizedAssignmentException` (en `src/Service/Exception/`) ante violación.
  - **Casos cubiertos:** POST directo desde rol no-staff (controller + service), bulk assign no autorizado (controller aborta lote), ticket locked / target inactivo / target no-staff (service por entidad), `patchEntity` futuro con `assignee_id` (entity rechaza).
  - **DI:** `TicketPipelineService::__construct` acepta `?AuthorizationService $authService = null` (patrón SGI ya adoptado).

- **5.7 ✅ / pendiente** Tabla comparativa de tipos de FK (lectura de `Initial.php`):

  <!-- pegar aquí la tabla del Task 7 step 3 -->

  - **Si todos coinciden →** "Resultado: todos los pares FK/PK coinciden en tipo, signedness y limit. Cerrado ✅."
  - **Si hay mismatch →** "Resultado: detectados N mismatches (ver tabla). Pendiente: corrección requiere migration con análisis de impacto separado. **Escalado a sesión dedicada.**"

**Diferidos a fase posterior:**
- 5.5 (config tipada) — requiere decisión arquitectónica (Value Object `SystemConfig` vs. inyección de `SettingsService`); toca múltiples servicios.
- 5.1 (domain events), 5.2 (tests mínimos), 5.3, 5.6.

Plan ejecutado: `docs/superpowers/plans/2026-05-08-audit-fase3-riesgo.md`.
Diseño: `docs/superpowers/specs/2026-05-08-audit-fase3-riesgo-design.md`.
```

- [ ] **Step 2: Pegar la tabla real del Task 7**

Reemplazar el comentario `<!-- pegar aquí la tabla del Task 7 step 3 -->` por la tabla efectiva construida en Task 7. Y mantener solo la línea de "Resultado:" que aplique según el caso (todos coinciden vs. hay mismatch).

- [ ] **Step 3: Actualizar tabla resumen del Anexo "Pendientes ahora reales"**

Buscar en el doc la sección donde dice "**Pendientes restantes:** medios 5.1–5.7." (al final del Anexo 4) y reemplazar por:

```markdown
**Pendientes restantes:** medios 5.1, 5.2, 5.3, 5.5, 5.6 (5.4 y 5.7 cerrados en Anexo 5).
```

- [ ] **Step 4: Commit**

```bash
git add docs/audits/2026-05-07-architecture-audit.md
git commit -m "docs(audit): close 5.4 and 5.7 in Anexo 5

5.4: defense-in-depth assignment authorization (entity _accessible:false,
controller early guard, service invariant guard with UnauthorizedAssignmentException).
5.7: FK type audit table for Initial migration.
"
```

---

## Self-Review

**Spec coverage:**
- §2.1 (entity `_accessible`) → Task 2 ✅
- §2.2 (service firma + DI + guards) → Task 3 ✅
- §2.3 (excepción `UnauthorizedAssignmentException`) → Task 1 ✅
- §2.4 (controller traits) → Tasks 4 y 5 ✅
- §2.5 (casos cubiertos) → verificados en Task 6 ✅
- §3 (5.7 plan operativo) → Task 7 ✅
- §4 (entregables) → Tasks 1-8 cubren todos ✅
- §6 (verificación manual) → Task 6 mapea 1:1 los 7 pasos ✅
- §7 (próximos pasos) → mencionados en Task 8 step 1 ✅

**Placeholder scan:** revisado — no hay TBD/TODO sin contenido. Todos los code blocks contienen el código completo a editar. Único marcador es `<!-- pegar aquí la tabla del Task 7 step 3 -->` que es deliberado: la tabla se construye dinámicamente en Task 7.

**Type consistency:** `mixed $actor` consistente entre Task 3 (service) y Tasks 4-5 (controller); `UnauthorizedAssignmentException` con namespace correcto en todos los `use`. `AuthorizationService::isAssignmentDisabled(mixed)` ya acepta `null` (línea 24-26 retorna `true` si `!$user`), por lo que `$actor === null` no es problemático en el guard del service (de hecho ese branch nunca se alcanza, porque el `if ($actor !== null && ...)` lo descarta). Verificado.
