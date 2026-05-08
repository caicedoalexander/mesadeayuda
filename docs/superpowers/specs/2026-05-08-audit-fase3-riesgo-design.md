# Diseño — Auditoría Fase 3 (subset de riesgo): 5.4 + 5.7

**Fecha:** 2026-05-08
**Auditoría origen:** `docs/audits/2026-05-07-architecture-audit.md`
**Items cubiertos:** medios 5.4 (mass-assignment + autorización de asignación) y 5.7 (auditoría de tipos de FK).
**Items diferidos:** 5.5 (config tipada — sesión dedicada), 5.1, 5.2, 5.3, 5.6.

---

## 1. Contexto

Tras cerrar críticos 3.1–3.6 y altos 4.1–4.8, los pendientes residuales son los medios 5.1–5.7. Se prioriza riesgo de seguridad: 5.4 es el único bug latente real; 5.7 es auditoría documental de bajo costo. 5.5 se difiere porque su solución correcta (Value Object `SystemConfig` o inyección de `SettingsService`) toca múltiples servicios y merece análisis arquitectónico separado.

### Estado actual verificado (2026-05-08)

- **`Ticket::$_accessible`** declara `'assignee_id' => true`. Ningún flujo actual hace `patchEntity` sobre `Ticket` desde `request->getData()`, pero la puerta queda abierta para futuros bugs.
- **`TicketPipelineService::assign()`** muta `$entity->assignee_id` directamente y guarda. **No invoca** `Ticket::canBeAssignedTo()` ni `AuthorizationService::isAssignmentDisabled()`. Ambos métodos existen pero solo se consumen desde la capa de presentación (`TicketListingTrait`, `TicketViewTrait`) para esconder botones.
- **Vector de ataque:** un usuario autenticado con `role=user` puede emitir `POST /tickets/assign/{id}` con `assignee_id=N` y reasignar el ticket. La UI no expone el botón, pero el endpoint no valida.
- **`AuthorizationService::isAssignmentDisabled`** define la regla canónica: solo `admin` y `agent` pueden asignar.
- **Migrations existentes:** 4 archivos (`Initial.php`, `AddGmailWebhookToken`, `MigrateGmailClientSecretToDatabase`, `ConsolidateLegacyTicketStatuses`). Solo `Initial.php` declara FKs.

---

## 2. Diseño — 5.4 Mass-assignment + autorización server-side

### 2.1 Entity

`src/Model/Entity/Ticket.php`:

```diff
-        'assignee_id' => true,
+        'assignee_id' => false,
```

Justificación: la asignación es una operación de dominio con reglas (autorización del actor, validez del target, lock del ticket) que no pueden expresarse vía mass-assignment. El único punto de mutación legítimo es `TicketPipelineService::assign()`.

### 2.2 Servicio — `TicketPipelineService`

#### Firma ampliada (backwards-compatible)

```php
public function assign(
    EntityInterface $entity,
    ?int $assigneeId,
    ?int $userId = null,
    mixed $actor = null,           // nuevo, opcional — User entity o IdentityInterface
): bool
```

**Nota de tipo:** se usa `mixed` por simetría con `AuthorizationService::isAssignmentDisabled(mixed $user)`, que ya tolera tanto `User` entity como objetos identidad de CakePHP Authentication. El controller pasará directamente `$this->Authentication->getIdentity()` sin desempaquetar — el service no necesita una `User` entity para chequear el actor (solo lee el `role`). Sí necesita `User` para el target, que se carga vía `UsersTable::get($assigneeId)`.

#### Constructor — DI de `AuthorizationService`

Siguiendo el patrón SGI ya adoptado en fase 4.3:

```php
public function __construct(
    ?array $systemConfig = null,
    ?TicketCommentService $comments = null,
    ?TicketNotificationService $notifications = null,
    ?AuthorizationService $authService = null,   // nuevo
) {
    // ...
    $this->authService = $authService ?? new AuthorizationService();
}
```

#### Guards en `assign()` (orden)

Antes de mutar `$entity->assignee_id`:

1. **Chequeo de actor** (si se provee):
   - Si `$actor !== null` y `$this->authService->isAssignmentDisabled($actor)` → `throw new UnauthorizedAssignmentException("El usuario no tiene permisos para asignar tickets")`.
2. **Chequeo de target** (si se asigna a alguien):
   - Si `$assigneeId !== null && $assigneeId !== 0`: cargar el `User` target.
   - Si `!$entity->canBeAssignedTo($targetUser)` → `throw new UnauthorizedAssignmentException("El ticket no puede ser asignado a este usuario")`.
   - Esto cubre tres invariantes ya codificados en la entidad: (a) ticket locked (resuelto), (b) target inactivo, (c) target no-staff.
3. **Limpiar asignación** (`$assigneeId === null || === 0`): permitida si el actor pasó el paso 1. No hay target que validar.

#### Bulk

`TicketPipelineService` también expone `bulkAssign` (consumido por `TicketBulkTrait`). Aplicar los mismos guards: el actor se chequea una vez al inicio del lote; el target se chequea por cada ticket.

### 2.3 Excepción nueva

`src/Service/Exception/UnauthorizedAssignmentException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use DomainException;

class UnauthorizedAssignmentException extends DomainException
{
}
```

Hereda de `DomainException` (no `RuntimeException`) porque representa una violación de regla de negocio, no un fallo de infraestructura.

### 2.4 Controller traits

#### `TicketActionsTrait::assign`

```php
$user = $this->Authentication->getIdentity();

// Guard temprano (mejor UX, evita exception trip al service)
if ($this->authorizationService->isAssignmentDisabled($user)) {
    $this->Flash->error('No tienes permisos para asignar tickets.');
    return $this->redirect(['action' => 'view', $id]);
}

try {
    $ok = $this->ticketPipeline->assign($ticket, $assigneeId, $user->id, $user);
} catch (UnauthorizedAssignmentException $e) {
    $this->Flash->error($e->getMessage());
    return $this->redirect(['action' => 'view', $id]);
}
```

#### `TicketBulkTrait::bulkAssignTickets`

Análogo: guard temprano del actor antes del lote, captura de `UnauthorizedAssignmentException` por iteración (skipping del ticket bloqueado, no abortando el lote).

### 2.5 Casos cubiertos

| Vector | Bloqueado por |
|---|---|
| POST directo `/tickets/assign/{id}` con `role=user` | Controller (early redirect) + Service (defense) |
| Bulk assign desde `role=user` | idem |
| Ticket resuelto (locked) | `Ticket::canBeAssignedTo` (existente) |
| Target inactivo | `Ticket::canBeAssignedTo` (existente) |
| Target no-staff | `Ticket::canBeAssignedTo` (existente) |
| Futuro `patchEntity($ticket, $request->getData())` con `assignee_id` | `_accessible: false` |

### 2.6 No cubierto (out of scope)

- Tests automáticos (item 5.2 — diferido).
- Otros campos sensibles: `status` ya es `_accessible: false`. `priority` queda `true` (asume que cualquier staff puede cambiarla; no hay regla restrictiva documentada).
- Auditoría de logs de intentos bloqueados — el `Flash::error` ya queda en logs estándar; no se añade tracking dedicado.

---

## 3. Diseño — 5.7 Auditoría de tipos de FK

Plan operativo (sin cambios de código en este plan):

1. Leer `config/Migrations/20260430213127_Initial.php` y registrar todas las FKs declaradas con `addForeignKey()` o columnas tipo `*_id`.
2. Para cada FK, comparar: tipo de la columna FK contra tipo de la columna primaria referenciada (típicamente `id` en la tabla destino).
3. Producir una tabla en el anexo de auditoría con columnas: `Tabla origen`, `Columna FK`, `Tipo FK`, `Tabla destino`, `Tipo PK destino`, `Coincide`.
4. Decisión basada en hallazgos:
   - **Todos coinciden →** cerrar 5.7 ✅ con el reporte como evidencia.
   - **Hay mismatch →** documentar conflictos, marcar 5.7 como pendiente y escalar a sesión separada para crear migration de corrección. **No** se crea migration en este plan: cambiar tipo de FK en producción es alto riesgo (locks de tabla, downtime, posibles datos huérfanos) y merece análisis dedicado de impacto.

---

## 4. Entregables

- **Spec:** este archivo (`docs/superpowers/specs/2026-05-08-audit-fase3-riesgo-design.md`).
- **Plan de implementación:** posterior, vía writing-plans skill.
- **Cambios de código (estimados):**
  - `src/Model/Entity/Ticket.php` — 1 línea.
  - `src/Service/TicketPipelineService.php` — firma + DI + guards en `assign` + `bulkAssign`.
  - `src/Service/Exception/UnauthorizedAssignmentException.php` — archivo nuevo.
  - `src/Controller/Trait/TicketActionsTrait.php` — guard + propagación de actor + manejo de excepción.
  - `src/Controller/Trait/TicketBulkTrait.php` — guard + propagación de actor + manejo de excepción.
- **Documentación:**
  - `docs/audits/2026-05-07-architecture-audit.md` — Anexo 5 cerrando 5.4 y 5.7 (con nota explícita sobre diferimiento de 5.5).

---

## 5. Riesgos y mitigación

| # | Riesgo | Probabilidad | Mitigación |
|---|---|---|---|
| R1 | Callers que no pasen `$actor` pierden el chequeo de actor. | Baja | El único caller en producción es el controller; va a pasar `$actor`. `$actor = null` se reserva para CLI/jobs futuros que opten por confiar en su propio contexto. |
| R2 | `assignee_id => false` rompe algún flujo de `patchEntity` no detectado. | Muy baja | `grep` confirmó que no hay `patchEntity` sobre `Ticket` desde request data. Reverificar antes del merge. |
| R3 | El guard temprano en controller redirige sin loguear el intento. | Media | Aceptable para esta fase. Si se materializa una necesidad de auditoría de intentos bloqueados, añadir `Log::warning` en una iteración futura. |
| R4 | 5.7 detecta mismatches no triviales y bloquea el cierre del ítem. | Media | Plan ya prevé escalar a sesión separada si aparece mismatch; el spec no se compromete a corregirlos aquí. |

---

## 6. Verificación manual (no hay tests automáticos)

Pasos para validar tras la implementación. Cada paso debe pasar antes de cerrar el plan.

1. **Login como `user` (no admin/agent).**
   - POST a `/tickets/assign/{id}` con `assignee_id=N` → flash error "No tienes permisos…", redirect a la view del ticket. Sin cambio en `assignee_id`.
2. **Login como `agent`.**
   - Asignar ticket en estado `abierto` → OK, audit en `ticket_history`.
   - Asignar ticket en estado `resuelto` → flash error "El ticket no puede ser asignado…".
3. **Bulk assign desde rol `user`** → flash error global, ningún ticket modificado.
4. **Asignar a un usuario inactivo** (`is_active = false`) desde `agent` → flash error.
5. **Asignar a un usuario con `role=user`** desde `agent` → flash error.
6. **UI smoke:** botones de asignar siguen ocultos para roles bloqueados (regresión visual). Revisar listing y view.
7. **5.7:** correr lectura de migrations, completar tabla y anexar al documento de auditoría.

---

## 7. Próximos pasos tras esta fase

- 5.5 (config tipada): sesión dedicada con design propio. Decidir entre Value Object `SystemConfig` vs. inyectar `SettingsService` en cada servicio.
- 5.2 (tests mínimos): habilitaría cobertura de los guards introducidos en 5.4.
- 5.1 (domain events): permitiría desacoplar notificaciones del pipeline; deuda estructural pero sin bug latente.
- 5.3, 5.6: housekeeping menor.
