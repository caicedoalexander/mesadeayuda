# Spec — Resolución Críticos 1-3 de la Auditoría de Arquitectura

**Fecha:** 2026-05-07
**Auditoría origen:** [`docs/audits/2026-05-07-architecture-audit.md`](../../audits/2026-05-07-architecture-audit.md)
**Alcance:** Críticos 1, 2 y 3 — sincronización de `CLAUDE.md`, reorganización `src/Utility/` → `src/Constants/`, unificación de estados/prioridades.
**Fuera de alcance:** Críticos 4-6 (eliminación `$entityType`, enriquecimiento entidad `Ticket`, trocear `TicketsController` en traits). Ver §6.

---

## 1. Layout de directorios objetivo

```
src/
├── Constants/                       ← NUEVO (reemplaza Utility/)
│   ├── TicketConstants.php          ← estados, prioridades, comment types, labels, colores
│   ├── RoleConstants.php            ← ROLE_*, ROLES, STAFF_ROLES
│   ├── CacheConstants.php           ← CACHE_SETTINGS, CACHE_CONFIG, DEFAULT_SYSTEM_TITLE
│   └── SettingKeys.php              ← claves de system_settings (mover sin cambios)
├── Service/
│   └── Traits/
│       └── SettingsEncryptionTrait.php  ← MOVIDO desde Utility/
├── Utility/                         ← ELIMINAR (carpeta vacía)
```

**Cambios de namespace:**

| Origen | Destino |
|---|---|
| `App\Utility\SettingKeys` | `App\Constants\SettingKeys` |
| `App\Utility\ValidationConstants` | **eliminada**; símbolos redistribuidos en `App\Constants\{TicketConstants, RoleConstants, CacheConstants}` |
| `App\Utility\SettingsEncryptionTrait` | `App\Service\Traits\SettingsEncryptionTrait` |

---

## 2. Contenido de `TicketConstants.php`

Set canónico de estados: **4 activos** (`nuevo`, `abierto`, `pendiente`, `resuelto`). `convertido`, `cerrado`, `en_progreso` se eliminan del código (no hay migración de datos; tickets huérfanos en BD se manejan en runtime — ver §5).

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class TicketConstants
{
    // ── Estados ───────────────────────────────────────────────────────
    public const STATUS_NUEVO     = 'nuevo';
    public const STATUS_ABIERTO   = 'abierto';
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_RESUELTO  = 'resuelto';

    public const STATUSES = [
        self::STATUS_NUEVO,
        self::STATUS_ABIERTO,
        self::STATUS_PENDIENTE,
        self::STATUS_RESUELTO,
    ];

    public const RESOLVED_STATUSES = [
        self::STATUS_RESUELTO,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_NUEVO,
        self::STATUS_ABIERTO,
        self::STATUS_PENDIENTE,
    ];

    /**
     * Valores de estado huérfanos en BD por módulos removidos.
     * NO usar para asignar; SOLO para filtrar tickets legacy en queries.
     * Sin labels ni colores: el helper hace fallback visual genérico.
     */
    public const LEGACY_STATUSES = [
        'convertido',   // módulo Compras (eliminado)
        'cerrado',      // nunca completó implementación
        'en_progreso',  // nunca se usó
    ];

    public const STATUS_LABELS = [
        self::STATUS_NUEVO     => 'Nuevo',
        self::STATUS_ABIERTO   => 'Abierto',
        self::STATUS_PENDIENTE => 'Pendiente',
        self::STATUS_RESUELTO  => 'Resuelto',
    ];

    public const STATUS_COLORS = [
        self::STATUS_NUEVO     => '#dc3545',
        self::STATUS_ABIERTO   => '#fd7e14',
        self::STATUS_PENDIENTE => '#0d6efd',
        self::STATUS_RESUELTO  => '#198754',
    ];

    public const STATUS_ICONS = [
        self::STATUS_NUEVO     => 'bi-circle-fill',
        self::STATUS_ABIERTO   => 'bi-circle-fill',
        self::STATUS_PENDIENTE => 'bi-circle-fill',
        self::STATUS_RESUELTO  => 'bi-circle-fill',
    ];

    // ── Prioridades ───────────────────────────────────────────────────
    public const PRIORITY_BAJA    = 'baja';
    public const PRIORITY_MEDIA   = 'media';
    public const PRIORITY_ALTA    = 'alta';
    public const PRIORITY_URGENTE = 'urgente';

    public const PRIORITIES = [
        self::PRIORITY_BAJA,
        self::PRIORITY_MEDIA,
        self::PRIORITY_ALTA,
        self::PRIORITY_URGENTE,
    ];

    public const PRIORITY_LABELS = [
        self::PRIORITY_BAJA    => 'Baja',
        self::PRIORITY_MEDIA   => 'Media',
        self::PRIORITY_ALTA    => 'Alta',
        self::PRIORITY_URGENTE => 'Urgente',
    ];

    public const PRIORITY_COLORS = [
        self::PRIORITY_BAJA    => '#6c757d',
        self::PRIORITY_MEDIA   => '#0dcaf0',
        self::PRIORITY_ALTA    => '#fd7e14',
        self::PRIORITY_URGENTE => '#dc3545',
    ];

    // ── Comment types ─────────────────────────────────────────────────
    public const COMMENT_PUBLIC   = 'public';
    public const COMMENT_INTERNAL = 'internal';
    public const COMMENT_SYSTEM   = 'system';

    /** Permitidos en input de usuario */
    public const COMMENT_TYPES = [self::COMMENT_PUBLIC, self::COMMENT_INTERNAL];

    /** Permitidos en BD (incluye los autogenerados por el sistema) */
    public const ALL_COMMENT_TYPES = [self::COMMENT_PUBLIC, self::COMMENT_INTERNAL, self::COMMENT_SYSTEM];
}
```

### `RoleConstants.php`

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class RoleConstants
{
    public const ROLE_ADMIN             = 'admin';
    public const ROLE_AGENT             = 'agent';
    public const ROLE_SERVICIO_CLIENTE  = 'servicio_cliente';
    public const ROLE_REQUESTER         = 'requester';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_AGENT,
        self::ROLE_SERVICIO_CLIENTE,
        self::ROLE_REQUESTER,
    ];

    /** Roles con acceso al panel interno staff */
    public const STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_AGENT,
        self::ROLE_SERVICIO_CLIENTE,
    ];
}
```

### `CacheConstants.php`

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class CacheConstants
{
    public const CACHE_SETTINGS = 'system_settings';
    public const CACHE_CONFIG   = '_cake_core_';

    public const DEFAULT_SYSTEM_TITLE = 'Mesa de Ayuda';
}
```

### `SettingKeys.php`

Sin cambios funcionales: copia 1:1 desde `src/Utility/SettingKeys.php`, cambiando `namespace App\Utility;` → `namespace App\Constants;`.

---

## 3. Estrategia de migración (4 fases)

### Fase A — Crear `src/Constants/` (aditivo)

1. Crear los 4 archivos en `src/Constants/` con el contenido de §2.
2. Crear `src/Service/Traits/SettingsEncryptionTrait.php` (copia desde `src/Utility/`, cambiando namespace y ajustando el `use App\Utility\SettingKeys;` → `use App\Constants\SettingKeys;`).
3. `composer dump-autoload`.
4. **No** eliminar todavía nada en `src/Utility/`.

**Verificación A:** `php -l` sobre los archivos nuevos. App sigue funcionando (nadie los consume aún).

### Fase B — Migrar consumidores (24 archivos)

Reemplazos de `use` declaration:

| Origen | Destino | Archivos |
|---|---|---|
| `use App\Utility\SettingKeys;` | `use App\Constants\SettingKeys;` | 13 archivos |
| `use App\Utility\SettingsEncryptionTrait;` | `use App\Service\Traits\SettingsEncryptionTrait;` | `AppController`, `GmailImportService` |
| `use App\Utility\ValidationConstants;` | uno o varios entre `use App\Constants\{TicketConstants, RoleConstants, CacheConstants};` | 11 archivos |

Reemplazos de símbolos:

| Patrón actual | Patrón nuevo |
|---|---|
| `ValidationConstants::ROLE_*` | `RoleConstants::ROLE_*` |
| `ValidationConstants::ROLES` | `RoleConstants::ROLES` |
| `ValidationConstants::STAFF_ROLES` | `RoleConstants::STAFF_ROLES` |
| `ValidationConstants::CACHE_*` | `CacheConstants::CACHE_*` |
| `ValidationConstants::DEFAULT_SYSTEM_TITLE` | `CacheConstants::DEFAULT_SYSTEM_TITLE` |
| `ValidationConstants::TICKET_COMMENT_TYPES` | `TicketConstants::ALL_COMMENT_TYPES` |
| `ValidationConstants::COMMENT_TYPES` | `TicketConstants::COMMENT_TYPES` |
| `ValidationConstants::TICKET_STATUSES` | `TicketConstants::STATUSES` |
| `ValidationConstants::PRIORITIES` | `TicketConstants::PRIORITIES` |
| `ValidationConstants::STATUS_*` | `TicketConstants::STATUS_*` (excluyendo `STATUS_EN_PROGRESO`/`STATUS_CERRADO` que se eliminan) |

Archivos confirmados con consumo (lista exhaustiva):

- `src/Controller/AppController.php`
- `src/Controller/TicketsController.php`
- `src/Controller/WebhooksController.php`
- `src/Controller/Admin/SettingsController.php`
- `src/Controller/Admin/TagsController.php`
- `src/Controller/Admin/EmailTemplatesController.php`
- `src/Model/Table/TicketCommentsTable.php`
- `src/Model/Table/UsersTable.php`
- `src/Model/Table/TicketsTable.php`
- `src/Service/EmailService.php`
- `src/Service/EmailTemplateRenderer.php`
- `src/Service/N8nService.php`
- `src/Service/WhatsappService.php`
- `src/Service/AuthorizationService.php`
- `src/Service/SettingsService.php`
- `src/Service/SidebarCountsService.php`
- `src/Service/GmailImportService.php`
- `src/Service/GmailService.php`
- `src/Service/Traits/ConfigResolutionTrait.php`
- `src/View/Cell/TicketsSidebarCell.php`
- `templates/Admin/Settings/index.php`

**Verificación B:** `composer cs-check` + smoke test manual: home, `/admin/settings`, detalle de ticket, crear comentario, sidebar.

### Fase C — Eliminar referencias legacy y mapas duplicados

**1. `src/View/Helper/StatusHelper.php`** — reescribir como capa delgada:
   - Eliminar las constantes privadas `TICKET_STATUS_LABELS`, `TICKET_STATUS_COLORS`, `PRIORITY_LABELS`, `PRIORITY_COLORS`.
   - `label($status)`, `color($status)`, `priorityLabel($p)`, `priorityColor($p)` leen de `TicketConstants::*` con null-coalescing fallback (`?? ucfirst(str_replace('_', ' ', $status))` y `?? '#6c757d'`).
   - El HTML inline que genera (estilos en `<span style="...">`) **no se toca** este ciclo.

**2. `src/Controller/TicketsController.php`:**
   - Línea 339-341: `getStatusConfig()` con array literal — refactorizar para construirlo desde `TicketConstants::STATUS_LABELS` + `STATUS_COLORS` + `STATUS_ICONS`. Mantener el método como wrapper (no eliminar — el desmonte es Crítico 4, fuera de scope).
   - Línea 363: `getResolvedStatuses()` retorna `['resuelto', 'convertido']` → `return TicketConstants::RESOLVED_STATUSES;`.
   - Línea 606: array `['cerrado' => 'Cerrado']` en `getStatusesForEntity` — eliminar la entrada `cerrado`.
   - Línea 646: `return $key !== 'convertido';` — eliminar el filtro.

**3. `src/Service/SidebarCountsService.php:28`:**
   - `$resolvedStatuses = ['resuelto', 'convertido'];` → `$resolvedStatuses = TicketConstants::RESOLVED_STATUSES;`.

**4. `src/Service/TicketService.php:630-635`:**
   - Eliminar el bloque `if ($newStatus === 'cerrado' && isset($entity->closed_at) && !$entity->closed_at)` — código muerto, `closed_at` no existe en BD.
   - Conservar la rama `'resuelto' && resolved_at`.

**5. `src/Service/Renderer/NotificationRenderer.php:59-61`:**
   - Reemplazar el array literal por uso directo de `TicketConstants::STATUS_LABELS` (ya sin `cerrado`).

**6. `src/Model/Table/TicketsTable.php`:**
   - Líneas 227, 234, 247: `'Tickets.status NOT IN' => ['resuelto', 'convertido']` → `'Tickets.status NOT IN' => array_merge(TicketConstants::RESOLVED_STATUSES, TicketConstants::LEGACY_STATUSES)`. Mantiene comportamiento: tickets `resuelto`/`convertido`/`cerrado`/`en_progreso` quedan fuera del listado por defecto.
   - Líneas 242, 282, 302-303: `'Tickets.status !=' => 'convertido'` → reemplazar por `'Tickets.status NOT IN' => TicketConstants::LEGACY_STATUSES`. Razón: la lógica original solo excluía un valor legacy; ahora extendemos a todos.
   - Líneas 276-277: eliminar `case 'convertidos': $query->where(['Tickets.status' => 'convertido']);` (el case completo desaparece).
   - Líneas 301-303: el `if ($view !== 'convertidos')` se reemplaza por una exclusión **incondicional** de `LEGACY_STATUSES`. Bookmarks `?view=convertidos` caen al listado por defecto silenciosamente, donde los tickets `convertido` ya no son visibles.

**7. `src/View/Cell/TicketsSidebarCell.php`:**
   - Línea 42: `'status IN' => ['nuevo', 'abierto', 'pendiente']` → `'status IN' => TicketConstants::OPEN_STATUSES`.
   - Línea 56: eliminar `'convertidos' => $statusCounts['convertido'] ?? 0`.

**8. `templates/Tickets/index.php`:**
   - Línea 41: eliminar `'convertidos' => 'Tickets convertidos'`.
   - Línea 122: `$isLocked = in_array($ticket->status, ['resuelto', 'convertido'])` → `... TicketConstants::RESOLVED_STATUSES, true)`.

**9. `templates/cell/TicketsSidebar/display.php:82-86`:**
   - Eliminar el bloque del link "Convertidos".

**10. `templates/element/tickets/left_sidebar.php:7`:**
   - `in_array($ticket->status, ['resuelto', 'convertido'])` → `... TicketConstants::RESOLVED_STATUSES, true)`.

**Verificación C** (smoke test manual):
- Home: sidebar muestra Nuevos / Abiertos / Pendientes / Resueltos. **No** debe aparecer "Convertidos".
- Filtros del listado: dropdown de estados muestra solo los 4 activos.
- Crear ticket: solo se puede asignar uno de los 4 estados.
- Cambiar estado de un ticket existente a `resuelto` y volver a `abierto`: ambas transiciones funcionan.
- Si existe ticket con `status='convertido'` en BD: abrirlo por URL directa renderiza el detalle con badge gris "Convertido", sin warnings en logs.

### Fase D — Eliminar `src/Utility/`

1. Verificar `grep -rn "App\\\\Utility" src/ config/ templates/ webroot/ tests/` retorna **cero** resultados.
2. Borrar `src/Utility/SettingKeys.php`.
3. Borrar `src/Utility/ValidationConstants.php`.
4. Borrar `src/Utility/SettingsEncryptionTrait.php`.
5. Borrar el directorio `src/Utility/`.
6. `composer dump-autoload`.
7. `composer cs-check && composer cs-fix`.
8. Smoke test final: home, login, detalle de ticket, crear comentario, `/admin/settings`.

**Punto de no-retorno:** Fases A-C son aditivas o reemplazo y reversibles con `git revert`. Fase D es la eliminación física. Tag de rollback recomendado: `git tag pre-criticos-1-3` antes de Fase A.

---

## 4. Sincronización de `CLAUDE.md`

Se ejecuta **al final** (después de Fase D) para que el documento describa el estado real del repo.

### 4.1 Bloque a reemplazar — sección "Layered structure" / `src/Controller/`

**Antes:**
> `src/Controller/` — HTTP edge. Controllers stay slim and delegate to services. `TicketsController` composes behavior from controller traits in `src/Controller/Traits/` (`TicketSystemControllerTrait`, `TicketSystemListingTrait`, `TicketSystemViewTrait`, `TicketSystemActionsTrait`, `TicketSystemBulkTrait`, `TicketSystemHistoryTrait`, `ServiceInitializerTrait`, `ViewDataNormalizerTrait`). These traits still expose an `$entityType` parameter from when a second module existed; today only `'ticket'` is supported.

**Después:**
> `src/Controller/` — HTTP edge. `TicketsController` is currently a single ~1100-line file organized internally by `// region:` markers (Listing, View, Actions, Bulk, History). The original intent was to split these into traits under `src/Controller/Trait/`, but that extraction has not been done. The methods still expose an `$entityType` parameter from a removed second module — today only `'ticket'` is supported, and the parameter is dead abstraction pending removal. Ver `docs/audits/2026-05-07-architecture-audit.md` (Crítico 3.2 god-controller, Crítico 3.3 `$entityType`).

### 4.2 Bloque a reemplazar — sección "Service Traits"

**Antes:**
> Reusable mixin logic lives in `src/Service/Traits/` (e.g. `NotificationDispatcherTrait`, `GenericAttachmentTrait`, `TicketSystemTrait`, `ConfigResolutionTrait`, `SecureHttpTrait`).

**Después:**
> Reusable mixin logic lives in `src/Service/Traits/`: `ConfigResolutionTrait`, `GenericAttachmentTrait`, `SecureHttpTrait`, `SettingsEncryptionTrait` (consumed by `AppController` and `GmailImportService` for transparent encryption of sensitive `system_settings` keys).

### 4.3 Bloque nuevo — sección "Layered structure" (agregar)

> `src/Constants/` — final classes con constantes de dominio. **Nunca hardcodear strings o IDs de dominio**; referenciar estas clases. Archivos:
> - `TicketConstants` — estados de ticket, prioridades, tipos de comentario, labels y colores de presentación.
> - `RoleConstants` — roles de usuario y atajo `STAFF_ROLES`.
> - `CacheConstants` — keys/configs de cache y `DEFAULT_SYSTEM_TITLE`.
> - `SettingKeys` — keys usadas en la tabla `system_settings`.

### 4.4 Bloque nuevo — sección "Cross-cutting conventions" (agregar)

> **Ticket status enum**: el modelo canónico de 4 estados (`nuevo`, `abierto`, `pendiente`, `resuelto`) vive en `TicketConstants::STATUSES`. Tickets en producción pueden tener valores legacy (`convertido`, `cerrado`, `en_progreso`) de módulos removidos; estos se toleran al leer (los helpers caen a un badge gris genérico) pero los validators los rechazan al escribir.

### 4.5 Eliminar referencias

- Cualquier alusión a `src/Utility/`, `App\Utility\*`, `ValidationConstants`.

---

## 5. Validación, manejo de errores y casos borde

### 5.1 Validators de CakePHP

`TicketsTable::validationDefault()` debe usar:

```php
$validator
    ->scalar('status')
    ->inList('status', TicketConstants::STATUSES, 'Estado no válido.')
    ->notEmptyString('status');

$validator
    ->scalar('priority')
    ->inList('priority', TicketConstants::PRIORITIES, 'Prioridad no válida.')
    ->notEmptyString('priority');
```

`TicketCommentsTable::validationDefault()` debe usar `TicketConstants::ALL_COMMENT_TYPES` (incluye `system` para inserts auto-generados).

### 5.2 Lectura defensiva en `StatusHelper`

```php
public function label(string $status): string
{
    return TicketConstants::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

public function color(string $status): string
{
    return TicketConstants::STATUS_COLORS[$status] ?? '#6c757d';
}
```

Tickets huérfanos en BD con `status='convertido'`/`cerrado`/`en_progreso` se renderizan con label crudo capitalizado (`"Convertido"`, `"Cerrado"`, `"En progreso"`) en badge gris. Sin warnings en logs.

### 5.3 Tickets huérfanos accedidos por URL directa

- **Detalle del ticket** (`templates/Tickets/view.php`): badge gris vía fallback. ✅
- **Sidebar** (`templates/element/tickets/left_sidebar.php`): `$isLocked = in_array($ticket->status, TicketConstants::RESOLVED_STATUSES, true)` → `false` para `convertido`. El usuario verá controles de edición habilitados. Aceptable: si edita y guarda, el validator rechaza el `status='convertido'` actual y el formulario fuerza a elegir uno de los 4 activos.
- **Cambio de estado**: dropdown solo lista los 4 activos. El estado legacy no se puede perpetuar.

### 5.4 Smoke checks pre-deploy

Antes de Fase D:

```bash
grep -rn "App\\\\Utility" src/ config/ templates/ webroot/ tests/ 2>&1
```

Debe retornar **cero** resultados.

```bash
grep -rn -E "(en_progreso|convertido|cerrado)" src/ templates/ 2>&1 \
  | grep -v 'cerrado sesión' \
  | grep -v 'src/Constants/TicketConstants.php'
```

Debe retornar **cero** resultados en `templates/` y solo retornar matches "estructurales" en `src/` que son referencias intencionales:
- `src/Constants/TicketConstants.php` — el array `LEGACY_STATUSES` (excluido del grep arriba).
- `src/Model/Table/TicketsTable.php` — uso de `LEGACY_STATUSES` vía constante (sin literales).

**Cero literales `'convertido'`/`'cerrado'`/`'en_progreso'` deben quedar en código (excepto dentro de la constante `LEGACY_STATUSES` y comentarios explicativos).**

### 5.5 Cobertura

El proyecto no tiene tests. Verificación 100% manual con los smoke tests listados en cada fase.

### 5.6 Rollback

- Fases A-C: `git revert` restaura código.
- Fase D: requiere restaurar archivos desde el tag `pre-criticos-1-3`.
- No hay migración de BD; ningún dato se pierde.

---

## 6. Out of scope

Listado explícito para evitar scope creep durante implementación.

### 6.1 Próximos ciclos (referenciados según numeración de la auditoría §3 y hoja de ruta §8)

- **Auditoría §3.3 / Hoja de ruta #4 — Eliminar `$entityType` muerto** en `TicketsController` (~−400 LOC). Próximo brainstorm.
- **Auditoría §3.5 / Hoja de ruta #5 — Enriquecer entidad `Ticket`** con predicados de dominio (`isResolved()`, `isLocked()`, etc.). Próximo brainstorm.
- **Auditoría §3.2 / Hoja de ruta #6 (Alto) — Trocear `TicketsController` en traits reales** o aceptarlo como monolítico y borrar `// region:` markers.

### 6.2 Issues altos relacionados pero no incluidos

- **Alto 4.4 — HTML inline en `StatusHelper`**: solo deduplicamos datos, el HTML inline se queda.
- **Alto 4.5 — Query inline en `TicketsSidebarCell`**: cambiamos solo el array literal por `OPEN_STATUSES`; la duplicación de capa con `SidebarCountsService` se queda.
- **Alto 4.8 — Cache `_cake_core_` para `system_settings`**: solo movemos la constante; estrategia de cache intacta.
- **Alto 4.7 — Acoplamiento OAuth en `TicketsController::beforeFilter`**: intacto.
- **Alto 4.2 — `EmailTemplateRenderer` vs. `NotificationRenderer`**: el solapamiento se queda; solo se actualiza el array de labels en NotificationRenderer.
- **Alto 4.6 — `src/Controller/Component/` vacío**: no se elimina (housekeeping para Crítico 6).

### 6.3 Issues medios

Todos fuera: `src/Event/`, `tests/`, mass-assignment de `assignee_id`, foreign keys de migraciones.

### 6.4 Decisiones de dominio diferidas

- **Reincorporar `cerrado` con `closed_at`**: no. Si el negocio lo pide, ciclo aparte.
- **Migrar BD `convertido → resuelto`**: no. Trivial cuando se requiera (`UPDATE tickets SET status='resuelto' WHERE status='convertido'`).
- **i18n**: no. Labels en español hardcoded.

### 6.5 Documentación

- No se actualiza `docs/audits/*` (queda como evidencia histórica).
- No se crea README específico de `src/Constants/` (docstring de cada clase basta).

---

## 7. Resumen ejecutivo

| Métrica | Valor |
|---|---|
| Archivos nuevos | 5 (`Constants/{Ticket,Role,Cache,Setting}*.php` + `Service/Traits/SettingsEncryptionTrait.php`) |
| Archivos modificados (estimación) | ~24 |
| Archivos eliminados | 4 (`Utility/{SettingKeys,ValidationConstants,SettingsEncryptionTrait}.php` + carpeta) |
| Migración de BD | Ninguna |
| Riesgo | Bajo-medio (refactor mecánico + eliminación de 3 valores legacy de UI) |
| Esfuerzo estimado | ~7 horas (auditoría: 1-2h Crítico 1 + 2h Crítico 2 + 4h Crítico 3) |
| Cobertura de tests | Manual (proyecto sin suite) |

**Próximo paso:** invocar `superpowers:writing-plans` para generar el plan de implementación a partir de este spec.
