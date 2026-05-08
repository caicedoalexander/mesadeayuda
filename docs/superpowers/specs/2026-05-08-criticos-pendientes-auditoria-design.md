# Diseño — Cierre de críticos pendientes de la auditoría 2026-05-07

**Fecha:** 2026-05-08
**Origen:** `docs/audits/2026-05-07-architecture-audit.md` (críticos 3.2, 3.3, 3.5)
**Alcance:** sólo los 3 críticos pendientes. Los altos (4.x) y medios (5.x) quedan para una fase posterior.

---

## 1. Contexto

Tres de los seis críticos de la auditoría ya están resueltos en commits recientes:

- **3.1** `src/Utility/` eliminado → `src/Constants/` con `TicketConstants`, `RoleConstants`, `CacheConstants`, `SettingKeys`.
- **3.4** Estados unificados en `TicketConstants::STATUSES` + migration `ConsolidateLegacyTicketStatuses`.
- **3.6** `CLAUDE.md` sincronizado con la realidad post-refactor.

Quedan tres críticos abiertos:

- **3.2** `TicketsController` god-controller (1.122 LOC).
- **3.3** Abstracción muerta `$entityType` (80 ocurrencias).
- **3.5** Entidad `Ticket` anémica (73 LOC, sin métodos de dominio).

## 2. Decisiones tomadas en brainstorming

| Pregunta | Respuesta |
|---|---|
| Alcance de la fase | Solo los 3 críticos pendientes |
| Estrategia para 3.2 | Trocear en traits reales bajo `src/Controller/Trait/` (singular, estilo SGI) |
| Nivel de enriquecimiento de 3.5 | Predicados + transiciones + reasignación |
| Orden de ejecución | 3.3 → 3.5 → 3.2 |

## 3. Plan secuenciado

El orden no es negociable: cada paso prepara el siguiente. Si se trocea el controller antes de eliminar `$entityType`, los traits arrastran la abstracción muerta. Si se enriquece la entidad después de trocear, los traits se reescriben dos veces.

### 3.1 Paso 1 — Eliminar `$entityType` (crítico 3.3)

**Objetivo:** quitar el parámetro `string $entityType` que aparece 80 veces y siempre se resuelve al case `'ticket'`.

**Métodos afectados** (todos privados/protegidos en `TicketsController`):
`indexEntity`, `viewEntity`, `assignEntity`, `bulkAssignEntity`, `getEntityComponents`, `getDefaultContain`, `getValidSortFields`, `getEntityVariable`, `getStatusesForEntity`, `getDefaultUsersRoleFilter`, `getUsersVariableName`, `getDefaultViewContain`, `getDefaultAgentsRoleFilter`, `getSingleEntityVariable`, `getTagsTableName`, `getHistoryTable`.

**Transformación:**
1. Eliminar el parámetro `string $entityType` de las firmas.
2. Eliminar los `match` y los `throw new InvalidArgumentException` para casos imposibles.
3. Inlinar los valores del case `'ticket'` en cada método. Los helpers de una sola línea (p. ej. `getTagsTableName(): string => 'TicketTags'`) se inlinan en su único callsite y se borran.
4. Renombrar:
   - Métodos privados/protegidos `…Entity` que pierden el parámetro: simplificar a `index`, `view`, `assign`, `bulkAssign` (el archivo `TicketsController.php` ya define el contexto, no hace falta el sufijo `Ticket`).
   - Helpers `getEntity*` / `…ForEntity` cuyo nombre dejaría de tener sentido: renombrar al concepto que realmente exponen (p. ej. `getStatusesForEntity` → `getStatuses`, `getDefaultUsersRoleFilter` queda igual).
   - Si tras inlinar un helper queda en una sola línea, eliminarlo y usar el literal en el callsite.
5. Sincronizar `CLAUDE.md` removiendo la nota sobre `$entityType` como abstracción pendiente.

**Verificación manual:**
- `grep -r "entityType" src/` → cero resultados.
- Ejercitar en navegador: listar tickets, ver detalle, asignar, bulk-asignar, filtrar por estado/prioridad, paginar.
- `composer cs-check` limpio.

**Reducción estimada:** ~350-400 LOC en `TicketsController.php`.

**Riesgo:** bajo. Refactor mecánico, sin cambio de comportamiento.

---

### 3.2 Paso 2 — Enriquecer la entidad `Ticket` (crítico 3.5)

**Objetivo:** mover predicados, transiciones de estado y reglas de reasignación desde el controller/service hacia `Ticket`.

**Métodos a agregar** (todos puros, sin I/O, sin dependencias de Tables):

**Predicados de estado** (leen `$this->status` contra `TicketConstants`):
- `isResolved(): bool` — `status === TicketConstants::STATUS_RESUELTO`
- `isOpen(): bool` — `in_array($this->status, TicketConstants::OPEN_STATUSES, true)`
- `isNew(): bool` — `status === TicketConstants::STATUS_NUEVO`
- `isPending(): bool` — `status === TicketConstants::STATUS_PENDIENTE`
- `isLocked(): bool` — alias semántico de `isResolved()`. Reemplaza `TicketsController::isEntityLocked()`.

**Predicados de relación:**
- `hasAssignee(): bool` — `assignee_id !== null`
- `belongsTo(int $userId): bool` — `requester_id === $userId`
- `isAssignedTo(int $userId): bool` — `assignee_id === $userId`
- `wasCreatedFromEmail(): bool` — basado en el campo/flag existente que indica origen Gmail (verificar nombre exacto antes de implementar).

**Predicado de overdue:**
- `isOverdue(?DateTimeImmutable $now = null): bool` — sólo si existe un campo equivalente a `due_at` en la tabla `tickets`. **Verificar antes de implementar**; si no existe, omitir este método.

**Transición de estado:**
- `canTransitionTo(string $newStatus): bool` — encapsula la matriz aprobada:
  - `nuevo` → `abierto`, `pendiente`, `resuelto`
  - `abierto` → `pendiente`, `resuelto`, `nuevo` (revertir)
  - `pendiente` → `abierto`, `resuelto`
  - `resuelto` → `abierto` (reapertura)
- La transición real (persistir + audit + notificar) sigue en `TicketService::changeStatus`, que ahora valida con `if (!$ticket->canTransitionTo($newStatus)) { throw new InvalidStatusTransitionException(...); }` antes de mutar.

**Reasignación:**
- `canBeAssignedTo(User $user): bool` — encapsula reglas del lado del ticket: no permitir asignar si `isLocked()`, validar que el `User` tenga rol staff (consultando `RoleConstants::STAFF_ROLES`). La validación de autorización del actor (¿quién puede asignar?) se mantiene en `AuthorizationService`.

**Adopción (callsites a actualizar):**
- `TicketsController::isEntityLocked()` → eliminar; usar `$ticket->isLocked()`.
- `TicketService::changeStatus()` → primer paso `$ticket->canTransitionTo(...)`.
- Todo `if ($ticket->status === 'resuelto')` en controllers/services/templates → `if ($ticket->isResolved())`.
- Toda comparación `$ticket->assignee_id === $user->id` en autorización → `$ticket->isAssignedTo($user->id)`.

**Excepción nueva:**
- `App\Service\Exception\InvalidStatusTransitionException` (extiende `RuntimeException`) — coherente con el patrón de la carpeta `Service/Exception/` ya existente (5.3 alto).

**Verificación manual:**
- Crear ticket nuevo → confirmar estados disponibles en el dropdown.
- Cambiar estado siguiendo y violando la matriz → confirmar que las violaciones se rechazan con mensaje claro.
- Asignar/reasignar a usuarios staff y no-staff (no-staff debe ser rechazado).
- Reabrir un ticket resuelto.

**Riesgo:** medio. Toca lógica de autorización. Mitigación: los métodos son puros, se pueden probar con datos sintéticos antes de tocar flujos reales.

---

### 3.3 Paso 3 — Trocear `TicketsController` en traits (crítico 3.2)

**Objetivo:** llevar el controller de ~700 LOC (post-3.3) a ~150 LOC distribuyendo regiones a traits cohesivos en `src/Controller/Trait/` (singular, estilo SGI).

**Estructura objetivo:**

```
src/Controller/Trait/
   TicketServiceInitializerTrait.php  ← initializeServices() y helpers compartidos
   TicketListingTrait.php             ← region: Listing
   TicketViewTrait.php                ← region: View
   TicketActionsTrait.php             ← region: Actions (assign, status change, comment, follow)
   TicketBulkTrait.php                ← region: Bulk
   TicketHistoryTrait.php             ← region: History
```

**Composición final de `TicketsController` (~150 LOC):**
```php
class TicketsController extends AppController
{
    use TicketServiceInitializerTrait;
    use TicketListingTrait;
    use TicketViewTrait;
    use TicketActionsTrait;
    use TicketBulkTrait;
    use TicketHistoryTrait;

    public function initialize(): void { … }
    public function beforeFilter(EventInterface $event): void { … }
    // Acciones públicas delegan a los traits.
}
```

**Reglas de extracción:**
1. Cada trait declara `declare(strict_types=1)` y vive en namespace `App\Controller\Trait`.
2. Los traits **no** declaran propiedades públicas — sólo métodos. Las dependencias compartidas (`$this->Tickets`, servicios) las consume cada trait asumiendo que `initializeServices` ya corrió.
3. Métodos privados se mantienen privados dentro del trait. Helpers compartidos por varios traits se mueven a `TicketServiceInitializerTrait` o a una clase dedicada en `src/Service/`.
4. Cada trait recibe su bloque `// region: <nombre>` correspondiente sin reordenar lógica.

**Orden de extracción (un trait por commit):**
1. `TicketServiceInitializerTrait` — sin lógica HTTP, baja superficie de regresión.
2. `TicketHistoryTrait` — pequeño, autocontenido.
3. `TicketBulkTrait` — autocontenido.
4. `TicketActionsTrait` — consume la entidad rica del paso 2 (`canTransitionTo`, `canBeAssignedTo`).
5. `TicketViewTrait`.
6. `TicketListingTrait` — el más grande, al final.

**Documentación a sincronizar al cierre:**
- `CLAUDE.md` — eliminar la nota "no se ha realizado la extracción"; documentar los traits reales y su responsabilidad.
- Borrar `src/Controller/Component/` (carpeta vacía heredada de bake; era el hallazgo 4.6 alto, trivial cerrarlo aquí).

**Verificación manual tras cada commit de extracción:**
- Listar tickets, abrir uno, cambiar estado, asignar, agregar comentario, hacer bulk, ver history.
- `composer cs-check` limpio.
- Al final: `grep -n "// region:" src/Controller/TicketsController.php` → cero coincidencias.

**Riesgo:** bajo-medio. Extracción mecánica preservando firmas. Cada trait queda en commit independiente para rollback puntual.

**LOC final esperado:**
- `TicketsController.php`: ~150 LOC.
- 6 traits sumando ~700 LOC repartidos por responsabilidad.
- Net change: 0 LOC, pero distribuidos por dominio (efecto buscado).

## 4. Criterios de éxito globales

Al cierre de los 3 pasos:

- `grep -r "entityType" src/` devuelve cero.
- `wc -l src/Controller/TicketsController.php` ≤ 200.
- `wc -l src/Model/Entity/Ticket.php` ≥ 200 (entidad con métodos de dominio).
- `ls src/Controller/Trait/` lista los 6 traits.
- `ls src/Controller/Component/` falla (carpeta eliminada).
- `composer cs-check` limpio.
- `CLAUDE.md` no menciona `$entityType` ni "extracción no realizada".
- Flujos manuales: crear, listar, ver, comentar, asignar, bulk, cambiar estado, reabrir, history — todos funcionan.

## 5. Fuera de alcance

- **Altos (4.x):** trocear `TicketService`, decidir entre `EmailTemplateRenderer` vs `NotificationRenderer`, mover query inline de `TicketsSidebarCell`, inyección de dependencias en `TicketService`, `StatusHelper` (datos+HTML), redirección OAuth fuera del controller, cache de settings.
- **Medios (5.x):** `src/Event/`, suite de tests, mass-assignment de `assignee_id`, etc.

Estos quedan para una fase posterior con su propio brainstorming + spec.

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Regresión en flujos no cubiertos por testing manual | Cada paso en su propia rama/PR; cada commit del paso 3 es un trait individual revertible |
| Matriz de transición de estados rechaza casos legítimos no anticipados | La matriz fue confirmada por el usuario; ante una excepción real en producción, ampliar la matriz en `Ticket::canTransitionTo` (un solo punto de verdad) |
| `wasCreatedFromEmail` o `isOverdue` referencian campos inexistentes | Verificar el schema de `tickets` antes de codificar; si el campo no existe, omitir el método (documentado en §3.2) |
| Traits que comparten estado oculto via `$this` | Las reglas de extracción §3.3.2 prohíben propiedades en traits; solo métodos. La inicialización de servicios se centraliza en `TicketServiceInitializerTrait` |

## 7. Próximo paso

Tras aprobación de este spec por el usuario, invocar la skill `superpowers:writing-plans` para producir un plan de implementación con checkpoints de revisión.
