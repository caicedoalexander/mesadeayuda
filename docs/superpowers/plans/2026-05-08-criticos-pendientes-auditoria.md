# Plan de Implementación — Cierre de críticos pendientes de la auditoría 2026-05-07

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los 3 críticos pendientes de la auditoría (3.3 `$entityType`, 3.5 entidad `Ticket` rica, 3.2 traits del controller) en ese orden.

**Architecture:** Refactor in-place sobre `src/Controller/TicketsController.php`, `src/Model/Entity/Ticket.php` y nueva carpeta `src/Controller/Trait/`. Sin tests automatizados (el proyecto no los tiene — verificación manual + `composer cs-check`). Cada fase termina en estado verificable; cada tarea termina en commit.

**Tech Stack:** PHP 8.1+, CakePHP 5.x, MySQL/MariaDB. Coding standard CakePHP CodeSniffer (`phpcs.xml`). Ejecución vía `bin/cake server` o `docker compose exec web …`.

**Spec origen:** `docs/superpowers/specs/2026-05-08-criticos-pendientes-auditoria-design.md`.

---

## File Structure

**Modificar:**
- `src/Controller/TicketsController.php` — 1.122 LOC → ~150 LOC al final.
- `src/Model/Entity/Ticket.php` — 73 LOC → ~200 LOC con métodos de dominio.
- `src/Service/TicketService.php` — adoptar `$ticket->canTransitionTo()` en `changeStatus`.
- `CLAUDE.md` — sincronizar al cierre de cada fase.

**Crear:**
- `src/Service/Exception/InvalidStatusTransitionException.php`.
- `src/Controller/Trait/TicketServiceInitializerTrait.php`.
- `src/Controller/Trait/TicketHistoryTrait.php`.
- `src/Controller/Trait/TicketBulkTrait.php`.
- `src/Controller/Trait/TicketActionsTrait.php`.
- `src/Controller/Trait/TicketViewTrait.php`.
- `src/Controller/Trait/TicketListingTrait.php`.

**Eliminar:**
- `src/Controller/Component/` (carpeta vacía heredada de bake — hallazgo 4.6 que se cierra de paso).

---

## Convenciones de verificación manual

Como el proyecto no tiene tests automatizados, cada tarea de modificación incluye los **flujos a ejercitar en navegador** antes del commit. Asume servidor local vía `bin/cake server` (puerto 8765) o Docker (`docker compose up -d`, puerto 8082). Login con un usuario admin.

**Flujo de regresión "smoke" (referenciado como SMOKE en tareas):**
1. Abrir `/` (lista de tickets) — la lista carga con filtros laterales.
2. Click en un ticket — la vista de detalle carga, comentarios y history visibles.
3. Cambiar estado del ticket — el cambio persiste y aparece en history.
4. Reasignar el ticket — la reasignación persiste y aparece en history.
5. Crear comentario público — aparece en la lista de comentarios.
6. Volver a la lista, seleccionar ≥2 tickets, hacer bulk-asignar — todos cambian de assignee.
7. Click en "Historial" del ticket — la página de history carga.

Si cualquier paso falla, NO hacer commit; revertir y diagnosticar.

**`composer cs-check`** debe pasar limpio antes de cada commit. Si falla, ejecutar `composer cs-fix` y re-verificar.

---

# Phase A — Crítico 3.3: eliminar `$entityType`

**Objetivo:** quitar el parámetro `string $entityType` (80 ocurrencias) de `TicketsController`, dejando los métodos operando directamente sobre Tickets.

**Heurística común a todas las tareas de la Phase A:**
- Para cada método con `match ($entityType) { 'ticket' => X, default => throw … }`: eliminar el parámetro y devolver `X` directamente.
- Para cada callsite que pasaba `'ticket'`: quitar el argumento.
- Para cada callsite con `if ($entityType === 'ticket') { … }`: el `if` siempre es verdadero, se simplifica el cuerpo.

---

### Task A1: Eliminar `$entityType` de los helpers de tabla/historia

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~402-434, ~1037-1042)

**Métodos afectados:** `getEntityComponents`, `getHistoryTable`, `getTagsTableName`.

- [ ] **Step 1: Reescribir `getEntityComponents`**

Reemplazar el bloque actual (líneas ~396-420) por:

```php
/**
 * Get ticket-related components (table, service, display name).
 *
 * @return array{table: \Cake\ORM\Table, service: ?\App\Service\TicketService, displayName: string, tableName: string, foreignKey: string}
 */
private function getEntityComponents(): array
{
    $components = [
        'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
        'service' => $this->ticketService ?? null,
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

- [ ] **Step 2: Reescribir `getHistoryTable`**

Reemplazar (líneas ~422-434):

```php
private function getHistoryTable(): Table
{
    return $this->fetchTable('TicketHistory');
}
```

- [ ] **Step 3: Reescribir `getTagsTableName`**

Reemplazar (líneas ~1037-1042) — este queda en una sola línea, se puede inlinar en sus callsites en lugar de mantener el helper. Buscar callsites primero:

```bash
grep -n "getTagsTableName" src/Controller/TicketsController.php
```

Si el helper se llama en múltiples sitios, reescribir como:

```php
private function getTagsTableName(): string
{
    return 'TicketTags';
}
```

Si se llama en un único sitio, eliminar el método y reemplazar el callsite por el literal `'TicketTags'`.

- [ ] **Step 4: Actualizar callsites**

Buscar callsites de los 3 métodos que se acaban de simplificar:

```bash
grep -n "getEntityComponents\|getHistoryTable\|getTagsTableName" src/Controller/TicketsController.php
```

En cada uno, eliminar el argumento `'ticket'` o `$entityType`.

Ejemplo: `$components = $this->getEntityComponents($entityType);` → `$components = $this->getEntityComponents();`.

- [ ] **Step 5: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

Ejercitar SMOKE pasos 1, 2, 7 (history toca `getHistoryTable`).

- [ ] **Step 6: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): drop \$entityType from table/history helpers"
```

---

### Task A2: Eliminar `$entityType` de los getters informativos del Listing

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~551-629)

**Métodos afectados:** `getDefaultContain`, `getValidSortFields`, `getEntityVariable`, `getStatusesForEntity`, `getDefaultUsersRoleFilter`, `getUsersVariableName`.

- [ ] **Step 1: Reescribir los 6 getters**

Reemplazar el bloque ~551-629 por:

```php
private function getDefaultContain(): array
{
    return ['Requesters', 'Assignees'];
}

private function getValidSortFields(): array
{
    return ['created', 'modified', 'status', 'priority', 'subject', 'ticket_number'];
}

private function getEntityVariable(): string
{
    return 'tickets';
}

private function getDefaultUsersRoleFilter(): array
{
    return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT];
}

private function getUsersVariableName(): string
{
    return 'agents';
}

private function getStatusesForEntity(): array
{
    return TicketConstants::STATUS_LABELS;
}
```

Nota: `getStatusesForEntity` retornaba un array hardcodeado idéntico a `TicketConstants::STATUS_LABELS` — se reutiliza la constante (cierra parcialmente 4.x DRY).

- [ ] **Step 2: Eliminar callsites — paso intermedio mecánico**

Estos getters se llaman desde `indexEntity` y `getFilterDataForView`. Buscar:

```bash
grep -n "getDefaultContain\|getValidSortFields\|getEntityVariable\|getStatusesForEntity\|getDefaultUsersRoleFilter\|getUsersVariableName" src/Controller/TicketsController.php
```

En cada callsite, eliminar el argumento. Ej: `$this->getDefaultContain($entityType)` → `$this->getDefaultContain()`.

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE pasos 1 (lista carga, filtros laterales correctos, ordenación funciona).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): drop \$entityType from listing getters"
```

---

### Task A3: Eliminar `$entityType` de los getters de View

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~678-721)

**Métodos afectados:** `getDefaultViewContain`, `getDefaultAgentsRoleFilter`, `getSingleEntityVariable`.

- [ ] **Step 1: Reescribir los 3 getters**

```php
private function getDefaultViewContain(bool $lazyLoadHistory = false): array
{
    $contain = [
        'Requesters',
        'Assignees',
        'TicketComments' => ['Users'],
        'Attachments',
        'Tags',
        'TicketFollowers' => ['Users'],
    ];
    if (!$lazyLoadHistory) {
        $contain['TicketHistory'] = [
            'Users',
            'sort' => ['TicketHistory.created' => 'DESC'],
        ];
    }

    return $contain;
}

private function getDefaultAgentsRoleFilter(): array
{
    return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT];
}

private function getSingleEntityVariable(): string
{
    return 'ticket';
}
```

- [ ] **Step 2: Actualizar callsites**

```bash
grep -n "getDefaultViewContain\|getDefaultAgentsRoleFilter\|getSingleEntityVariable" src/Controller/TicketsController.php
```

Eliminar el argumento `$entityType` en cada llamada. Conservar `$lazyLoadHistory` donde aplique.

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE pasos 2 (vista carga con todos los containments correctos, agentes en dropdown).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): drop \$entityType from view getters"
```

---

### Task A4: Eliminar `$entityType` de `applyRoleBasedFilters` y `getFilterDataForView`

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~540-549, ~577-600)

- [ ] **Step 1: Reescribir `applyRoleBasedFilters`**

```php
private function applyRoleBasedFilters($query, $user, ?string $userRole, string $tableAlias): void
{
    if (!$user || !$userRole) {
        return;
    }
    if ($userRole === RoleConstants::ROLE_REQUESTER) {
        $query->where([$tableAlias . '.requester_id' => $user->get('id')]);
    }
}
```

Nota: el literal `'requester'` se reemplaza por `RoleConstants::ROLE_REQUESTER` (mejora DRY de paso).

- [ ] **Step 2: Reescribir `getFilterDataForView`**

```php
private function getFilterDataForView(array $config): array
{
    $data = [];
    $usersRoleFilter = $config['usersRoleFilter'] ?? $this->getDefaultUsersRoleFilter();
    if ($usersRoleFilter !== null) {
        $usersVarName = $this->getUsersVariableName();
        $data[$usersVarName] = $this->fetchTable('Users')
            ->find('list')
            ->where(['role IN' => $usersRoleFilter, 'is_active' => true])
            ->toArray();
    }
    $data['priorities'] = TicketConstants::PRIORITY_LABELS;
    $data['statuses'] = $this->getStatusesForEntity();
    $data['tags'] = $this->fetchTable('Tags')->find()->toArray();

    return $data;
}
```

Nota: `$data['priorities']` ahora reusa `TicketConstants::PRIORITY_LABELS` (eliminando un duplicado más); el `if ($entityType === 'ticket')` que envolvía `$data['tags']` se elimina porque siempre es verdadero.

- [ ] **Step 3: Actualizar callsites de ambos métodos**

```bash
grep -n "applyRoleBasedFilters\|getFilterDataForView" src/Controller/TicketsController.php
```

Eliminar argumento `$entityType`.

- [ ] **Step 4: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE pasos 1 (filtros laterales completos: estados, prioridades, agentes, tags).

Adicional: loguearse como usuario `requester` y verificar que solo ve sus propios tickets.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): drop \$entityType from role filter and filter data"
```

---

### Task A5: Eliminar `$entityType` de `indexEntity` y renombrarlo

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~440-538 + acción pública `index`)

- [ ] **Step 1: Localizar la acción pública `index`**

```bash
grep -n "public function index\|->indexEntity" src/Controller/TicketsController.php
```

Hay una acción pública `index` que llama a `$this->indexEntity('ticket', [...])`.

- [ ] **Step 2: Reescribir la firma de `indexEntity`**

Cambiar:

```php
protected function indexEntity(string $entityType, array $config = []): void
```

Por:

```php
protected function indexTicketList(array $config = []): void
```

Dentro del cuerpo, eliminar todas las apariciones de `$entityType`:
- En llamadas a getters: ya no llevan argumento (se hicieron en A1-A4).
- En `if ($entityType === 'ticket')`: simplificar.
- En `applyRoleBasedFilters($query, $entityType, ...)`: eliminar el argumento `$entityType`.

- [ ] **Step 3: Actualizar la acción pública `index`**

Cambiar:

```php
$this->indexEntity('ticket', [...]);
```

Por:

```php
$this->indexTicketList([...]);
```

- [ ] **Step 4: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 1 + filtros laterales + búsqueda + paginación + ordenación por columnas.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): rename indexEntity to indexTicketList"
```

---

### Task A6: Eliminar `$entityType` de `viewEntity` y renombrarlo

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~635-676 + acción pública `view`)

- [ ] **Step 1: Reescribir la firma**

Cambiar:

```php
protected function viewEntity(string $entityType, int $id, array $config = []): ?Response
```

Por:

```php
protected function viewTicket(int $id, array $config = []): ?Response
```

Dentro del cuerpo, eliminar `$entityType` de todas las llamadas a getters (ya no requieren argumento) y de `getEntityComponents`.

- [ ] **Step 2: Actualizar la acción pública `view`**

```bash
grep -n "->viewEntity" src/Controller/TicketsController.php
```

Cambiar `$this->viewEntity('ticket', $id, [...])` por `$this->viewTicket($id, [...])`.

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 2 + verificar que `isLocked`, dropdown de estados, dropdown de agentes están correctos en la vista.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): rename viewEntity to viewTicket"
```

---

### Task A7: Eliminar `$entityType` de las acciones de Actions

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~727-883)

**Métodos afectados:** `assignEntity`, `addEntityComment`, `downloadEntityAttachment`.

- [ ] **Step 1: Identificar acciones públicas que las invocan**

```bash
grep -n "->assignEntity\|->addEntityComment\|->downloadEntityAttachment" src/Controller/TicketsController.php
```

- [ ] **Step 2: Reescribir cada método**

`assignEntity` → `assignTicket`. Firma:

```php
protected function assignTicket(
    int $entityId,
    $assigneeId,
    string $redirectAction = 'index',
): Response
```

Dentro del cuerpo: `$components = $this->getEntityComponents();` (sin argumento).

`addEntityComment` → `addTicketComment(int $entityId): Response`.

`downloadEntityAttachment` → `downloadTicketAttachment(int $attachmentId): Response`.

En cada caso, eliminar el parámetro `string $entityType` y todas sus referencias internas.

- [ ] **Step 3: Actualizar callsites en acciones públicas**

Cada acción pública (`assign`, `addComment`, `downloadAttachment` o nombres equivalentes) que llamaba con `'ticket'` ahora llama sin él.

- [ ] **Step 4: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE pasos 4 (asignar), 5 (comentar). Adicional: descargar un attachment desde la vista.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): rename action methods to ticket-specific"
```

---

### Task A8: Eliminar `$entityType` de los métodos Bulk

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~887-1035)

**Métodos afectados:** `bulkAssignEntity`, `bulkChangeEntityPriority`, `bulkAddTagEntity`, `bulkDeleteEntity`.

- [ ] **Step 1: Renombrar y simplificar**

| Antes | Después |
|---|---|
| `bulkAssignEntity(string $entityType): Response` | `bulkAssignTickets(): Response` |
| `bulkChangeEntityPriority(string $entityType): Response` | `bulkChangeTicketPriority(): Response` |
| `bulkAddTagEntity(string $entityType): Response` | `bulkAddTicketTag(): Response` |
| `bulkDeleteEntity(string $entityType): Response` | `bulkDeleteTickets(): Response` |

En cada cuerpo: eliminar el parámetro y simplificar `$this->getEntityComponents($entityType)` → `$this->getEntityComponents()`.

- [ ] **Step 2: Actualizar acciones públicas**

```bash
grep -n "->bulkAssignEntity\|->bulkChangeEntityPriority\|->bulkAddTagEntity\|->bulkDeleteEntity" src/Controller/TicketsController.php
```

Cambiar callsites.

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 6 + bulk-cambiar prioridad + bulk-agregar tag + bulk-eliminar (si la UI lo expone).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): rename bulk methods to ticket-specific"
```

---

### Task A9: Eliminar `$entityType` de `historyEntity` y cierre de Phase A

**Files:**
- Modify: `src/Controller/TicketsController.php` (líneas ~1046-1100)
- Modify: `CLAUDE.md`

- [ ] **Step 1: Renombrar `historyEntity` → `historyTicket`**

Firma:

```php
protected function historyTicket(int $id): void
```

Cuerpo: usar `$this->getHistoryTable()` (sin argumento).

- [ ] **Step 2: Actualizar acción pública `history`**

```bash
grep -n "->historyEntity" src/Controller/TicketsController.php
```

Cambiar callsite.

- [ ] **Step 3: Verificación final de Phase A**

```bash
grep -n "entityType" src/Controller/TicketsController.php
composer cs-check
wc -l src/Controller/TicketsController.php
```

Esperado:
- `grep` → 0 resultados.
- `cs-check` → limpio.
- LOC entre 700 y 800.

- [ ] **Step 4: Sincronizar `CLAUDE.md`**

Editar la sección que describe `TicketsController`. Texto actual (alrededor de la sección "src/Controller/"):

> "TicketsController is currently a single ~1100-line file organized internally by `// region:` markers (Listing, View, Actions, Bulk, History). The original intent was to split these into traits under `src/Controller/Trait/`, but that extraction has not been done. The methods still expose an `$entityType` parameter from a removed second module — today only `'ticket'` is supported, and the parameter is dead abstraction pending removal."

Reemplazar por:

> "TicketsController is currently a single ~750-line file organized internally by `// region:` markers (Listing, View, Actions, Bulk, History). The original intent was to split these into traits under `src/Controller/Trait/`; the extraction is the next pending refactor. Methods are now ticket-specific (`indexTicketList`, `viewTicket`, `assignTicket`, etc.) — el parámetro `$entityType` heredado del módulo Compras fue eliminado en mayo 2026."

- [ ] **Step 5: Verificación SMOKE completa**

Ejecutar todos los pasos del SMOKE (1-7).

- [ ] **Step 6: Commit final de Phase A**

```bash
git add src/Controller/TicketsController.php CLAUDE.md
git commit -m "refactor(tickets): close phase A — drop \$entityType abstraction"
```

---

# Phase B — Crítico 3.5: enriquecer la entidad `Ticket`

**Objetivo:** mover predicados, transición de estado y reglas de reasignación desde controller/service hacia `Ticket`.

---

### Task B1: Agregar excepción `InvalidStatusTransitionException`

**Files:**
- Create: `src/Service/Exception/InvalidStatusTransitionException.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when an attempt is made to transition a ticket to a status
 * that is not allowed by the domain state machine.
 */
class InvalidStatusTransitionException extends RuntimeException
{
    public static function for(string $from, string $to): self
    {
        return new self(sprintf(
            'Invalid ticket status transition: "%s" -> "%s"',
            $from,
            $to
        ));
    }
}
```

- [ ] **Step 2: Verificar cs-check**

```bash
composer cs-check
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/Exception/InvalidStatusTransitionException.php
git commit -m "feat(domain): add InvalidStatusTransitionException"
```

---

### Task B2: Agregar predicados de estado en `Ticket`

**Files:**
- Modify: `src/Model/Entity/Ticket.php`

- [ ] **Step 1: Agregar el `use` de `TicketConstants`**

Justo después de `use Cake\ORM\Entity;` insertar:

```php
use App\Constants\TicketConstants;
```

- [ ] **Step 2: Agregar los predicados al final de la clase**

Insertar antes del cierre `}` de la clase:

```php
// region: Domain predicates — status

public function isResolved(): bool
{
    return $this->status === TicketConstants::STATUS_RESUELTO;
}

public function isOpen(): bool
{
    return in_array($this->status, TicketConstants::OPEN_STATUSES, true);
}

public function isNew(): bool
{
    return $this->status === TicketConstants::STATUS_NUEVO;
}

public function isPending(): bool
{
    return $this->status === TicketConstants::STATUS_PENDIENTE;
}

/**
 * A locked ticket cannot be mutated by normal flows (assignment, comments
 * from non-staff, status downgrade by requester, etc.).
 */
public function isLocked(): bool
{
    return $this->isResolved();
}

// endregion
```

- [ ] **Step 3: Verificar cs-check**

```bash
composer cs-check
```

- [ ] **Step 4: Verificación funcional rápida**

Abrir un ticket en estado `nuevo` y otro en `resuelto`. La lógica aún no consume estos métodos, pero `composer cs-check` valida la sintaxis.

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/Ticket.php
git commit -m "feat(ticket-entity): add status predicates"
```

---

### Task B3: Agregar predicados de relación en `Ticket`

**Files:**
- Modify: `src/Model/Entity/Ticket.php`

- [ ] **Step 1: Determinar el campo de origen**

El spec menciona `wasCreatedFromEmail()`. Verificar el schema:

```bash
grep -n "channel\|gmail_message_id" src/Model/Entity/Ticket.php
```

Hay dos campos relevantes: `channel` (string) y `gmail_message_id` (string|null). El predicado se basa en `gmail_message_id !== null` (más específico que `channel`).

- [ ] **Step 2: Agregar predicados**

Insertar después del bloque agregado en B2:

```php
// region: Domain predicates — relationships

public function hasAssignee(): bool
{
    return $this->assignee_id !== null;
}

public function belongsTo(int $userId): bool
{
    return $this->requester_id === $userId;
}

public function isAssignedTo(int $userId): bool
{
    return $this->assignee_id === $userId;
}

public function wasCreatedFromEmail(): bool
{
    return $this->gmail_message_id !== null;
}

// endregion
```

Nota: NO agregar `isOverdue()` — el schema de `tickets` (líneas 25-27 de `Ticket.php`) no tiene un campo `due_at`. El spec marca este método como condicional ("verificar antes de implementar; si no existe, omitir").

- [ ] **Step 3: Verificar cs-check**

```bash
composer cs-check
```

- [ ] **Step 4: Commit**

```bash
git add src/Model/Entity/Ticket.php
git commit -m "feat(ticket-entity): add relationship predicates"
```

---

### Task B4: Agregar `canTransitionTo` en `Ticket`

**Files:**
- Modify: `src/Model/Entity/Ticket.php`

- [ ] **Step 1: Agregar el método y la matriz**

Insertar después del bloque B3:

```php
// region: Domain transitions

/**
 * Legal status transitions per the ticket state machine.
 *
 * - nuevo     → abierto, pendiente, resuelto
 * - abierto   → pendiente, resuelto, nuevo (revertir)
 * - pendiente → abierto, resuelto
 * - resuelto  → abierto (reapertura)
 *
 * @var array<string, list<string>>
 */
private const TRANSITIONS = [
    TicketConstants::STATUS_NUEVO     => [
        TicketConstants::STATUS_ABIERTO,
        TicketConstants::STATUS_PENDIENTE,
        TicketConstants::STATUS_RESUELTO,
    ],
    TicketConstants::STATUS_ABIERTO   => [
        TicketConstants::STATUS_PENDIENTE,
        TicketConstants::STATUS_RESUELTO,
        TicketConstants::STATUS_NUEVO,
    ],
    TicketConstants::STATUS_PENDIENTE => [
        TicketConstants::STATUS_ABIERTO,
        TicketConstants::STATUS_RESUELTO,
    ],
    TicketConstants::STATUS_RESUELTO  => [
        TicketConstants::STATUS_ABIERTO,
    ],
];

public function canTransitionTo(string $newStatus): bool
{
    if (!in_array($newStatus, TicketConstants::STATUSES, true)) {
        return false;
    }
    if ($this->status === $newStatus) {
        return false;
    }
    $allowed = self::TRANSITIONS[$this->status] ?? [];

    return in_array($newStatus, $allowed, true);
}

// endregion
```

- [ ] **Step 2: Verificar cs-check**

```bash
composer cs-check
```

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/Ticket.php
git commit -m "feat(ticket-entity): add canTransitionTo state machine"
```

---

### Task B5: Agregar `canBeAssignedTo` en `Ticket`

**Files:**
- Modify: `src/Model/Entity/Ticket.php`

- [ ] **Step 1: Agregar el método**

Insertar después del bloque B4:

```php
// region: Domain transitions — assignment

public function canBeAssignedTo(User $user): bool
{
    if ($this->isLocked()) {
        return false;
    }
    if (!$user->isStaff()) {
        return false;
    }
    if (!$user->is_active) {
        return false;
    }

    return true;
}

// endregion
```

- [ ] **Step 2: Importar `User`**

Agregar arriba en los `use`:

```php
use App\Model\Entity\User;
```

- [ ] **Step 3: Verificar que `User::isStaff()` existe**

```bash
grep -n "function isStaff" src/Model/Entity/User.php
```

Si NO existe, agregarlo en la entidad `User`:

```php
// En src/Model/Entity/User.php, antes del cierre de la clase:

use App\Constants\RoleConstants;

public function isStaff(): bool
{
    return in_array($this->role, RoleConstants::STAFF_ROLES, true);
}
```

- [ ] **Step 4: Verificar cs-check**

```bash
composer cs-check
```

- [ ] **Step 5: Commit**

```bash
git add src/Model/Entity/Ticket.php src/Model/Entity/User.php
git commit -m "feat(ticket-entity): add canBeAssignedTo with User::isStaff helper"
```

---

### Task B6: Adoptar `Ticket::isLocked()` en `TicketsController`

**Files:**
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Localizar `isEntityLocked` y sus usos**

```bash
grep -n "isEntityLocked\|getResolvedStatuses" src/Controller/TicketsController.php
```

Usos esperados (de la auditoría inicial): líneas ~387-390 (definición), 670, 741, 770, 799 (callsites).

- [ ] **Step 2: Reemplazar callsites**

En cada uno:

```php
$this->isEntityLocked($entity)
```

Por:

```php
$entity->isLocked()
```

- [ ] **Step 3: Eliminar la definición de `isEntityLocked`**

Borrar el bloque ~385-390 (4 líneas + comentario).

- [ ] **Step 4: Revisar `getResolvedStatuses`**

Si `getResolvedStatuses` solamente era consumido por `isEntityLocked` y por algún view-var, considerar reemplazar el view-var:

```php
'resolvedStatuses' => $this->getResolvedStatuses(),
```

por:

```php
'resolvedStatuses' => TicketConstants::RESOLVED_STATUSES,
```

y eliminar `getResolvedStatuses` si queda sin callers. Verificar primero:

```bash
grep -n "getResolvedStatuses" src/Controller/TicketsController.php src/View src/templates 2>/dev/null
```

- [ ] **Step 5: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 2 (la vista todavía marca `isLocked` correctamente cuando el ticket está resuelto) + paso 3 (cambio de estado funciona) + paso 4 (asignación funciona).

- [ ] **Step 6: Commit**

```bash
git add src/Controller/TicketsController.php
git commit -m "refactor(tickets): use Ticket::isLocked instead of isEntityLocked"
```

---

### Task B7: Adoptar `canTransitionTo` y `canBeAssignedTo`

**Files:**
- Modify: `src/Service/TicketService.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Localizar `changeStatus`**

```bash
grep -n "function changeStatus" src/Service/TicketService.php
```

- [ ] **Step 2: Agregar la validación al inicio del método**

Inmediatamente después de cargar `$ticket` y conocer `$newStatus` (antes de cualquier `patchEntity`/`save`/`audit`), insertar:

```php
if (!$ticket->canTransitionTo($newStatus)) {
    throw \App\Service\Exception\InvalidStatusTransitionException::for(
        $ticket->status,
        $newStatus
    );
}
```

- [ ] **Step 3: Importar la excepción**

Arriba del archivo agregar:

```php
use App\Service\Exception\InvalidStatusTransitionException;
```

Y simplificar el `throw`:

```php
if (!$ticket->canTransitionTo($newStatus)) {
    throw InvalidStatusTransitionException::for($ticket->status, $newStatus);
}
```

- [ ] **Step 4: Capturar la excepción en el controller**

Localizar la acción que invoca `changeStatus` (probablemente en `// region: Actions`):

```bash
grep -n "changeStatus" src/Controller/TicketsController.php
```

Envolver la llamada en try/catch:

```php
try {
    $this->ticketService->changeStatus(/* args */);
} catch (\App\Service\Exception\InvalidStatusTransitionException $e) {
    $this->Flash->error(__('Transición de estado no permitida: {0}', [$e->getMessage()]));
    return $this->redirect($this->referer(['action' => 'index']));
}
```

(Adaptar `$this->referer(...)` al patrón existente del controlador para errores en acciones POST.)

- [ ] **Step 5: Adoptar `canBeAssignedTo` en el flujo de asignación**

Localizar `assignTicket` (renombrado en Phase A desde `assignEntity`):

```bash
grep -n "function assignTicket" src/Controller/TicketsController.php
```

Dentro del método, después de cargar `$entity` y `$assigneeUser` (el usuario destino) y antes del `patchEntity`/`save`:

```php
if ($assigneeId !== null) {
    $assigneeUser = $this->fetchTable('Users')->get($assigneeId);
    if (!$entity->canBeAssignedTo($assigneeUser)) {
        $this->Flash->error(__('No es posible asignar este ticket a ese usuario.'));
        return $this->redirect($this->referer(['action' => 'index']));
    }
}
```

Adaptar la posición exacta al flujo existente: la validación debe ocurrir antes de mutar `$entity->assignee_id`. Si el método ya carga `$assigneeUser` por otro camino, reutilizarlo.

Si `assignTicket` también soporta desasignar (`assigneeId === null`), saltar la validación en ese caso (`if ($assigneeId !== null)` ya lo cubre).

- [ ] **Step 6: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 3 (cambio de estado válido funciona) + intentar una transición inválida (forzando vía DevTools un valor de status fuera de la matriz) — debe rechazarse con flash de error.

Casos explícitos a probar:
- Ticket `nuevo` → `resuelto` ✓ permitido
- Ticket `resuelto` → `pendiente` ✗ rechazado (solo permite `abierto`)
- Ticket `resuelto` → `abierto` ✓ permitido (reapertura)

SMOKE paso 4 (asignación válida funciona) + intentar asignar a un usuario con `role = 'requester'` — debe rechazarse con flash de error. Asignar un ticket `resuelto` a un agente — debe rechazarse (ticket bloqueado).

- [ ] **Step 7: Commit**

```bash
git add src/Service/TicketService.php src/Controller/TicketsController.php
git commit -m "feat(tickets): adopt canTransitionTo and canBeAssignedTo at callsites"
```

---

### Task B8: Cierre de Phase B

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Verificación final**

```bash
composer cs-check
wc -l src/Model/Entity/Ticket.php
```

Esperado: `Ticket.php` ≥ 200 LOC.

- [ ] **Step 2: SMOKE completo**

Ejecutar pasos 1-7 del SMOKE. Adicional: ejercitar transición ilegal de estado (debe rechazarse con flash); reasignar a usuario no-staff (debe rechazarse).

- [ ] **Step 3: Sincronizar `CLAUDE.md`**

En la sección que describe la entidad `Ticket` (o crearla bajo "Cross-cutting conventions"), agregar:

> **Domain methods en `Ticket`:** la entidad expone predicados (`isResolved`, `isOpen`, `isLocked`, `hasAssignee`, `belongsTo`, `isAssignedTo`, `wasCreatedFromEmail`) y reglas de transición (`canTransitionTo`, `canBeAssignedTo`). Estos métodos son la fuente de verdad — controllers y services deben consumirlos en lugar de comparar `status` o `assignee_id` directamente.

- [ ] **Step 4: Commit final de Phase B**

```bash
git add CLAUDE.md
git commit -m "docs(claude): document Ticket domain methods"
```

---

# Phase C — Crítico 3.2: trocear `TicketsController` en traits

**Objetivo:** distribuir las regiones de `TicketsController` (~750 LOC tras Phase A) en 6 traits cohesivos bajo `src/Controller/Trait/`.

**Reglas de extracción comunes:**
1. Cada trait declara `declare(strict_types=1)` y vive en namespace `App\Controller\Trait`.
2. Los traits NO declaran propiedades públicas — solo métodos. Las propiedades compartidas (`$this->Tickets`, `$this->ticketService`, etc.) las consume cada trait asumiendo que `initialize()` corrió.
3. Métodos privados se mantienen privados dentro del trait. Si dos traits requieren el mismo helper, ese helper sube a `TicketServiceInitializerTrait`.
4. Cada trait recibe su bloque `// region: <nombre>` correspondiente **copiado textual del controller, sin reordenar ni modificar lógica**. Los placeholders `<CONTENIDO MOVIDO …>` en las tareas siguientes describen exactamente qué bloque mover; el dev debe abrir `TicketsController.php`, identificar la región por sus marcadores `// region: X` / `// endregion`, cortar y pegar tal cual.
5. Los `use` de clases (Cake\ORM\Table, RoleConstants, TicketConstants, AuthorizationService, etc.) se replican en cada trait que los necesite — no se "limpian" durante el movimiento; si el método usa una clase, el trait la importa.
6. Tras cada extracción, `grep -n "// region: <nombre>" src/Controller/TicketsController.php` debe devolver 0; el bloque entero quedó migrado.

---

### Task C1: Crear `TicketServiceInitializerTrait`

**Files:**
- Create: `src/Controller/Trait/TicketServiceInitializerTrait.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Identificar el contenido a mover**

Bloques `// region: ServiceInitializer` (~líneas 312-342) y `// region: ViewDataNormalizer` (~líneas 343-393) y `// region: TicketSystemController helpers` (~líneas 394-436 — lo que queda tras Phase A).

```bash
grep -n "// region:\|// endregion" src/Controller/TicketsController.php
```

- [ ] **Step 2: Crear el trait**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use Cake\ORM\Table;

/**
 * Initialization, view-data normalization, and table/history helpers
 * shared by all Tickets controller regions.
 */
trait TicketServiceInitializerTrait
{
    // <CONTENIDO MOVIDO TEXTUAL DESDE TicketsController.php:
    //   - Toda la región ServiceInitializer
    //   - Toda la región ViewDataNormalizer
    //   - Toda la región TicketSystemController helpers (getEntityComponents, getHistoryTable, getTagsTableName si quedó)
    // Mantener el orden y los marcadores // region: / // endregion. >
}
```

Mover los métodos sin reordenarlos. Los `use` necesarios (probablemente `Cake\ORM\Table`, `App\Service\TicketService`, etc.) se replican arriba del trait.

- [ ] **Step 3: Quitar los métodos movidos del controller**

Borrar las 3 regiones del controller. Agregar el `use` del trait al inicio de la clase:

```php
use App\Controller\Trait\TicketServiceInitializerTrait;

class TicketsController extends AppController
{
    use TicketServiceInitializerTrait;
    // …
}
```

- [ ] **Step 4: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE completo (1-7).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Trait/TicketServiceInitializerTrait.php src/Controller/TicketsController.php
git commit -m "refactor(tickets): extract TicketServiceInitializerTrait"
```

---

### Task C2: Crear `TicketHistoryTrait`

**Files:**
- Create: `src/Controller/Trait/TicketHistoryTrait.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Crear el trait con la región History**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

trait TicketHistoryTrait
{
    // region: History

    // <CONTENIDO MOVIDO: historyTicket() y cualquier acción pública relacionada como `history()`>

    // endregion
}
```

Mover `historyTicket` (renombrado en Phase A desde `historyEntity`) + la acción pública `history` que la invoca.

- [ ] **Step 2: Quitar la región History del controller, agregar `use`**

```php
use App\Controller\Trait\TicketHistoryTrait;

class TicketsController extends AppController
{
    use TicketServiceInitializerTrait;
    use TicketHistoryTrait;
    // …
}
```

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 7 (página de history carga).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Trait/TicketHistoryTrait.php src/Controller/TicketsController.php
git commit -m "refactor(tickets): extract TicketHistoryTrait"
```

---

### Task C3: Crear `TicketBulkTrait`

**Files:**
- Create: `src/Controller/Trait/TicketBulkTrait.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Crear el trait con la región Bulk**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

trait TicketBulkTrait
{
    // region: Bulk

    // <CONTENIDO MOVIDO: bulkAssignTickets, bulkChangeTicketPriority, bulkAddTicketTag, bulkDeleteTickets + acciones públicas que las invocan>

    // endregion
}
```

- [ ] **Step 2: Quitar la región Bulk del controller, agregar `use`**

```php
use App\Controller\Trait\TicketBulkTrait;
```

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 6 + bulk-cambiar prioridad + bulk-tag.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Trait/TicketBulkTrait.php src/Controller/TicketsController.php
git commit -m "refactor(tickets): extract TicketBulkTrait"
```

---

### Task C4: Crear `TicketActionsTrait`

**Files:**
- Create: `src/Controller/Trait/TicketActionsTrait.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Crear el trait con la región Actions**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

trait TicketActionsTrait
{
    // region: Actions

    // <CONTENIDO MOVIDO: assignTicket, addTicketComment, downloadTicketAttachment, cualquier helper privado de Actions, + acciones públicas (`assign`, `addComment`, `downloadAttachment`, `changeStatus`, `addFollower`, `removeFollower` si existen)>

    // endregion
}
```

Importante: este trait usa `Ticket::canTransitionTo` y `Ticket::canBeAssignedTo` (de Phase B). Verificar que las llamadas se mantienen.

- [ ] **Step 2: Quitar la región Actions del controller, agregar `use`**

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE pasos 3, 4, 5 + intentar transición de estado inválida (debe seguir rechazándose).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Trait/TicketActionsTrait.php src/Controller/TicketsController.php
git commit -m "refactor(tickets): extract TicketActionsTrait"
```

---

### Task C5: Crear `TicketViewTrait`

**Files:**
- Create: `src/Controller/Trait/TicketViewTrait.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Crear el trait con la región View**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\RoleConstants;
use App\Service\AuthorizationService;
use Cake\Http\Response;

trait TicketViewTrait
{
    // region: View

    // <CONTENIDO MOVIDO: viewTicket, getDefaultViewContain, getDefaultAgentsRoleFilter, getSingleEntityVariable, getStatusConfig, getPriorityConfig, getResolvedStatuses si quedó + acción pública `view`>

    // endregion
}
```

- [ ] **Step 2: Quitar la región View del controller, agregar `use`**

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
```

SMOKE paso 2.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Trait/TicketViewTrait.php src/Controller/TicketsController.php
git commit -m "refactor(tickets): extract TicketViewTrait"
```

---

### Task C6: Crear `TicketListingTrait`

**Files:**
- Create: `src/Controller/Trait/TicketListingTrait.php`
- Modify: `src/Controller/TicketsController.php`

- [ ] **Step 1: Crear el trait con la región Listing**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;

trait TicketListingTrait
{
    // region: Listing

    // <CONTENIDO MOVIDO: indexTicketList, applyRoleBasedFilters, getDefaultContain, getValidSortFields, getEntityVariable, getFilterDataForView, getDefaultUsersRoleFilter, getUsersVariableName, getStatusesForEntity + acción pública `index`>

    // endregion
}
```

- [ ] **Step 2: Quitar la región Listing del controller, agregar `use`**

Tras este paso, `TicketsController.php` debe quedar en ~150 LOC: solo `initialize`, `beforeFilter`, los `use` de los 6 traits, y posiblemente alguna acción pública pequeña.

- [ ] **Step 3: Verificar cs-check y SMOKE**

```bash
composer cs-check
wc -l src/Controller/TicketsController.php
```

SMOKE completo (1-7).

LOC esperado: ≤ 200.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Trait/TicketListingTrait.php src/Controller/TicketsController.php
git commit -m "refactor(tickets): extract TicketListingTrait"
```

---

### Task C7: Eliminar `src/Controller/Component/` y verificación final

**Files:**
- Delete: `src/Controller/Component/`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Confirmar que la carpeta está vacía**

```bash
ls src/Controller/Component/ 2>&1
```

Si la salida lista archivos, NO borrar — investigar primero.

Si está vacía:

```bash
rmdir src/Controller/Component
```

- [ ] **Step 2: Sincronizar `CLAUDE.md`**

En la sección que describe `src/Controller/`:

Reemplazar:

> "TicketsController is currently a single ~750-line file organized internally by `// region:` markers (Listing, View, Actions, Bulk, History). The original intent was to split these into traits under `src/Controller/Trait/`; the extraction is the next pending refactor. Methods are now ticket-specific (`indexTicketList`, `viewTicket`, `assignTicket`, etc.) — el parámetro `$entityType` heredado del módulo Compras fue eliminado en mayo 2026."

Por:

> "`TicketsController` (~150 LOC) compone seis traits en `src/Controller/Trait/`:
> - `TicketServiceInitializerTrait` — inicialización de servicios y normalizadores de view-data.
> - `TicketListingTrait` — `index` y filtros laterales.
> - `TicketViewTrait` — `view` y configuración de pantalla de detalle.
> - `TicketActionsTrait` — `assign`, `addComment`, `downloadAttachment`, `changeStatus`, followers.
> - `TicketBulkTrait` — operaciones masivas.
> - `TicketHistoryTrait` — pantalla de historial.
>
> Las reglas de dominio (estados válidos, transiciones, reasignación) viven en la entidad `Ticket` y son consumidas desde los traits."

- [ ] **Step 3: Verificación final completa**

```bash
composer cs-check
wc -l src/Controller/TicketsController.php
ls src/Controller/Trait/
ls src/Controller/Component/ 2>&1   # debe fallar
grep -rn "entityType" src/Controller/   # debe ser cero
```

Esperado:
- `cs-check` limpio.
- `TicketsController.php` ≤ 200 LOC.
- 6 traits en `src/Controller/Trait/`.
- `Component/` no existe.
- 0 ocurrencias de `entityType`.

SMOKE completo (1-7) + bulk + history + transición ilegal de estado (rechazada) + reasignar a no-staff (rechazada).

- [ ] **Step 4: Commit final de Phase C**

```bash
git add -A
git commit -m "refactor(tickets): close phase C — trait extraction + remove empty Component dir"
```

---

# Cierre global

### Task Z: Push y resumen

- [ ] **Step 1: Push de la rama**

```bash
git status
git log --oneline -25
```

Confirmar que los commits son atómicos y revertibles. Push:

```bash
git push origin <branch>
```

- [ ] **Step 2: Anotar progreso en la auditoría**

Editar `docs/audits/2026-05-07-architecture-audit.md` agregando al final una nota de cierre:

```markdown
---

## Anexo — Cierre de críticos pendientes (2026-05-08)

Críticos cerrados en commits posteriores:
- 3.2 ✅ TicketsController troceado en 6 traits bajo src/Controller/Trait/
- 3.3 ✅ Abstracción `$entityType` eliminada
- 3.5 ✅ Entidad Ticket enriquecida con predicados, canTransitionTo, canBeAssignedTo

Detalles en `docs/superpowers/specs/2026-05-08-criticos-pendientes-auditoria-design.md` y plan asociado.

Pendientes para próxima fase: altos 4.1-4.8 + medios 5.1-5.7.
```

- [ ] **Step 3: Commit del anexo**

```bash
git add docs/audits/2026-05-07-architecture-audit.md
git commit -m "docs(audit): mark críticos 3.2/3.3/3.5 as closed"
git push
```

---

## Criterios de éxito globales (resumen)

| Criterio | Cómo verificar |
|---|---|
| `$entityType` eliminado | `grep -rn "entityType" src/` → 0 |
| Controller delgado | `wc -l src/Controller/TicketsController.php` ≤ 200 |
| Entidad rica | `wc -l src/Model/Entity/Ticket.php` ≥ 200 |
| 6 traits creados | `ls src/Controller/Trait/` lista los 6 archivos |
| Component/ eliminado | `ls src/Controller/Component/` falla |
| `cs-check` limpio | `composer cs-check` exit 0 |
| `CLAUDE.md` sincronizado | Sin menciones a `$entityType` ni "extracción no realizada" |
| Flujos manuales OK | SMOKE 1-7 + transición ilegal rechazada + reasignación a no-staff rechazada |

---

## Riesgos conocidos y rollback

- **Cada commit es atómico:** si una tarea introduce regresión, `git revert <hash>` deja el repo en estado funcional.
- **Phase A → Phase B → Phase C son secuenciales:** no intercalar pasos. Si una phase queda incompleta, no comenzar la siguiente.
- **Si Phase B revela campos faltantes** (ej. `wasCreatedFromEmail` apunta a un campo que no existe): el plan documenta la verificación previa en B3. Si el campo no existe, omitir el método y continuar; documentar el cambio en el commit message de B3.
- **Si la matriz de transiciones rechaza un caso legítimo** descubierto en producción: ampliar `Ticket::TRANSITIONS` (un solo punto de verdad) en un commit posterior — no hace falta tocar nada más.
