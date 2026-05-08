# Audit Fase 2 — Altos Pendientes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los 7 hallazgos altos restantes (4.1, 4.2, 4.3, 4.4, 4.5, 4.7, 4.8) del audit del 2026-05-07 y reconciliar el documento con los críticos ya cerrados de facto (3.1, 3.4, 3.6).

**Architecture:** Refactor incremental sin cambios funcionales. Una sola fase, 8 commits ordenados de menor a mayor riesgo: primero los puntuales aislados (OAuth, cache, sidebar cell, helpers), luego decisión de renderers, finalmente troceo del god-service `TicketService` y mejora de DI.

**Tech Stack:** PHP 8.4, CakePHP 5.x, Bootstrap 5, Composer scripts (`cs-check`, `cs-fix`), MySQL/MariaDB. Sin tests automatizados — verificación por smoke manual + `composer cs-check` por commit.

**Spec base:** `docs/superpowers/specs/2026-05-08-audit-fase2-altos-design.md` (commit `83cfdc5`).

---

## File Structure

### Archivos a crear

| Path | Responsabilidad |
|---|---|
| `src/Service/TicketIngestionService.php` | Entrada de tickets desde email/WhatsApp. Lógica de `createFromEmail`, `createCommentFromEmail`, parseo de remitente, autorización por recipients, attachments-from-email |
| `src/Service/TicketNotificationService.php` | Despacho de notificaciones email + WhatsApp para creación, status change, comment, response. Wraps `EmailService` + `WhatsappService` |
| `templates/element/tickets/status_badge.php` | Element reutilizable para badge de estado con clases CSS (sin estilos inline) |
| `templates/element/tickets/priority_badge.php` | Element reutilizable para badge de prioridad |
| `webroot/css/badges.css` | Clases CSS para badges (sustituye estilos inline) |

### Archivos a modificar

| Path | Cambio |
|---|---|
| `docs/audits/2026-05-07-architecture-audit.md` | Anexar cierre de 3.1, 3.4, 3.6 |
| `src/Controller/Trait/TicketListingTrait.php` | Eliminar closure `specialRedirects` y opción de configuración asociada |
| `config/routes.php` | Añadir ruta `/oauth/gmail/callback` (defensa por compatibilidad con configs OAuth legacy) |
| `src/View/Helper/StatusHelper.php` | Reescribir `statusBadge` y `priorityBadge` para usar elements + clases CSS |
| `src/View/Cell/TicketsSidebarCell.php` | Reemplazar query inline por llamada a `SidebarCountsService::getAgentStatusCounts()` |
| `src/Service/SidebarCountsService.php` | Añadir método `getAgentStatusCounts(int $userId): array` |
| `templates/Tickets/index.php` | Reemplazar `$this->Ticket->getViewUrl($ticket)` por `['action' => 'view', $ticket->id]` directo |
| `src/Service/TicketService.php` | Mover métodos a nuevos servicios. Aceptar EmailService/WhatsappService inyectables en constructor |
| `src/Service/EmailTemplateRenderer.php` | (Posible) docblock que clarifique rol como "template loader" |
| `src/Service/Renderer/NotificationRenderer.php` | (Posible) renombrar/clarificar como formatter, o consolidar |
| `CLAUDE.md` | Actualizar lista de servicios (añadir `TicketIngestionService`, `TicketNotificationService`); eliminar mención a `TicketHelper` |

### Archivos a eliminar

| Path | Razón |
|---|---|
| `src/View/Helper/TicketHelper.php` | Wrapper trivial; su único método (`getViewUrl`) reemplazado por array literal |

---

## Task 0: Reconciliar audit doc

**Files:**
- Modify: `docs/audits/2026-05-07-architecture-audit.md`

- [ ] **Step 0.1: Verificar estado en disco antes de marcar cerrado**

Ejecutar:
```bash
ls src/Utility 2>&1; ls src/Constants
ls src/Service/Traits/SettingsEncryptionTrait.php
grep -l "PRIORITY_LABELS\|TICKET_STATUS_LABELS" src/View/Helper/StatusHelper.php
```
Esperado: `src/Utility` no existe; `src/Constants/` contiene `TicketConstants.php`, `RoleConstants.php`, `CacheConstants.php`, `SettingKeys.php`; `SettingsEncryptionTrait.php` existe en `src/Service/Traits/`; `StatusHelper.php` NO contiene `PRIORITY_LABELS` ni `TICKET_STATUS_LABELS` locales (solo lee de `TicketConstants`).

- [ ] **Step 0.2: Editar el anexo del audit**

Abrir `docs/audits/2026-05-07-architecture-audit.md` y al final del **Anexo — Cierre de críticos pendientes (2026-05-08)** añadir nueva subsección:

```markdown
### Anexo 2 — Cierre adicional verificado (2026-05-08)

Tras inspección directa del código, los siguientes críticos también están cerrados de facto:

- **3.1 ✅** `src/Utility/` eliminado. Constantes movidas a `src/Constants/` (`TicketConstants`, `RoleConstants`, `CacheConstants`, `SettingKeys`). `SettingsEncryptionTrait` reubicado a `src/Service/Traits/`.
- **3.4 ✅** Migration `ConsolidateLegacyTicketStatuses` consolidó estados a 4 (`nuevo`, `abierto`, `pendiente`, `resuelto`). `TicketConstants` es la única fuente de verdad para estados, prioridades, labels y colores. `StatusHelper` ya lee de `TicketConstants` (sin duplicación local). Pendiente residual: HTML inline en `StatusHelper::statusBadge`/`priorityBadge` — se cierra como parte del alto 4.4.
- **3.6 ✅** `CLAUDE.md` sincronizado con la realidad: describe los 6 traits reales en `src/Controller/Trait/`, los 4 traits en `src/Service/Traits/`, las 4 clases de `src/Constants/` y la entidad `Ticket` enriquecida.

**Pendientes ahora reales:** altos 4.1, 4.2, 4.3, 4.4, 4.5, 4.7, 4.8 + medios 5.1–5.7. Plan de fase 2: `docs/superpowers/plans/2026-05-08-audit-fase2-altos.md`.
```

- [ ] **Step 0.3: Verificar y commitear**

Ejecutar:
```bash
git diff docs/audits/2026-05-07-architecture-audit.md
git add docs/audits/2026-05-07-architecture-audit.md
git commit -m "docs(audit): close 3.1 / 3.4 / 3.6 (verified in code)

Anexo 2 añadido al audit del 2026-05-07 documentando que los criticos
3.1 (src/Utility), 3.4 (multiples fuentes de verdad estados/prioridades)
y 3.6 (CLAUDE.md desincronizado) ya estan cerrados de facto en el
codigo. Se mantiene 3.4 con un residual menor (HTML inline en
StatusHelper) que cierra el alto 4.4.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 1: 4.7 — Eliminar redirect OAuth de Tickets + ruta de compatibilidad

**Contexto:** El closure `specialRedirects` en `src/Controller/Trait/TicketListingTrait.php:24-39` redirige `?code=...` recibido en `/` hacia `Admin/Settings::gmailAuth`. Es un guardián legacy: el flujo OAuth actual de `SettingsController::gmailAuth` (`src/Controller/Admin/SettingsController.php:136-140`) construye `redirect_uri` apuntando a sí mismo (`/admin/settings/gmail-auth`), por lo que Google nunca debería volver a `/`. El closure es la única invocación del sistema `specialRedirects` en `indexTicketList`.

**Decisión:** eliminar el closure y la opción `specialRedirects` del trait (quedará más simple). Para defensa contra credenciales OAuth legacy en Google Cloud Console que aún apunten a `/`, añadir una ruta dedicada `/oauth/gmail/callback` que mapee a `Admin/Settings::gmailAuth`.

**Files:**
- Modify: `src/Controller/Trait/TicketListingTrait.php`
- Modify: `config/routes.php`

- [ ] **Step 1.1: Añadir ruta de callback OAuth**

En `config/routes.php`, dentro del scope `/`, antes de `$builder->prefix('Admin', ...)` (línea ~67), añadir:

```php
// Gmail OAuth callback (compat con configs legacy en Google Cloud Console)
$builder->connect(
    '/oauth/gmail/callback',
    ['controller' => 'Settings', 'action' => 'gmailAuth', 'prefix' => 'Admin']
);
```

- [ ] **Step 1.2: Eliminar closure y opción `specialRedirects` en TicketListingTrait**

Editar `src/Controller/Trait/TicketListingTrait.php`:

Reemplazar líneas 20-41 (el método `index`) por:

```php
    /**
     * Index method - List tickets with filters
     */
    public function index()
    {
        $this->indexTicketList(['filterParams' => []]);
    }
```

Reemplazar líneas 46-69 (la inicialización de `$config` y bloque `if (is_callable($config['specialRedirects']))`) por:

```php
    protected function indexTicketList(array $config = []): void
    {
        $defaults = [
            'defaultView' => 'todos_sin_resolver',
            'defaultSort' => 'created',
            'defaultDirection' => 'desc',
            'paginationLimit' => 10,
            'contain' => null,
            'validSortFields' => null,
            'filterParams' => [],
            'usersRoleFilter' => null,
            'additionalViewVars' => [],
            'beforeQuery' => null,
        ];
        $config = array_merge($defaults, $config);
        $user = $this->Authentication->getIdentity();
        $userRole = $user ? $user->get('role') : null;
```

(elimina las líneas `'specialRedirects' => null,` y el bloque `if (is_callable($config['specialRedirects']))`).

- [ ] **Step 1.3: Verificar cs-check**

```bash
composer cs-check
```
Esperado: PASS sin errores en los archivos modificados (puede haber warnings preexistentes en otros archivos — solo importan los de los modificados).

Si falla en los archivos modificados:
```bash
composer cs-fix
composer cs-check
```

- [ ] **Step 1.4: Smoke manual**

1. `bin/cake server` (o `docker compose up -d`) y abrir http://localhost:8765
2. Verificar que `/` carga el listado de tickets sin errores.
3. Login como admin, ir a `/admin/settings`, click en "Conectar Gmail" (o equivalente).
4. Verificar que el flujo OAuth completa correctamente (debe redirigir a Google y volver a `/admin/settings/gmail-auth?code=...` con `Flash::success`).
5. **Nota para el operador:** si la configuración de Google Cloud Console aún apunta el redirect URI a `http://localhost/?code=...` (legacy), debe actualizarse a `http://<dominio>/admin/settings/gmail-auth` o `http://<dominio>/oauth/gmail/callback`. Documentar este punto en el commit message.

- [ ] **Step 1.5: Commit**

```bash
git add src/Controller/Trait/TicketListingTrait.php config/routes.php
git commit -m "refactor(oauth): drop specialRedirects closure, add dedicated callback route — close audit 4.7

Elimina el closure 'specialRedirects' inyectado en TicketListingTrait
que redirigia ?code= recibido en / hacia Admin/Settings::gmailAuth.
El flujo OAuth actual ya configura redirect_uri en SettingsController
apuntando a /admin/settings/gmail-auth, por lo que el closure era
legacy fallback nunca ejecutado en condiciones normales.

Para defensa contra configs OAuth legacy en Google Cloud Console que
aun apunten a /, se anade ruta dedicada /oauth/gmail/callback que
mapea a Admin/Settings::gmailAuth. Operador debe verificar la URL
de redirect autorizada en Google Cloud Console.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: 4.8 — Verificar invalidación de cache de settings

**Contexto:** `SettingsService::saveSetting()` (`src/Service/SettingsService.php:44-72`) ya invalida 4 keys en `clearAllCaches()`. `SettingsController::index` (`src/Controller/Admin/SettingsController.php:92-96`) siempre va por `settingsService->saveSetting()`. Verificar que NO haya otros lugares que persistan en `system_settings` saltando el servicio.

**Files:**
- Read-only audit + posibles fixes en archivos detectados

- [ ] **Step 2.1: Auditar saves directos a SystemSettings**

```bash
grep -rn "fetchTable('SystemSettings')" src/ --include='*.php'
grep -rn "SystemSettingsTable" src/ --include='*.php'
```

Para cada resultado, verificar si hay una llamada `->save(` cercana (próximas 30 líneas):

```bash
grep -rn -A 30 "fetchTable('SystemSettings')" src/ --include='*.php' | grep -B 1 "->save("
```

Sitios conocidos al iniciar (esperados):
- `src/Service/SettingsService.php:46` → save vía `saveSetting`. ✓ invalida cache.
- `src/Controller/AppController.php:76` → solo find, sin save. ✓
- `src/Controller/HealthController.php:60` → solo find. ✓
- `src/Service/GmailService.php:467` → solo find. ✓
- `src/Service/Traits/ConfigResolutionTrait.php:51,87` → solo find. ✓
- `src/Service/GmailImportService.php:48,57` → solo lee. ✓

Si hay sitios adicionales con `->save(`, listarlos y aplicar Step 2.2 a cada uno.

- [ ] **Step 2.2: (Solo si Step 2.1 encontró saves directos) Refactorizar para usar SettingsService**

Para cada save directo encontrado:
1. Inyectar/instanciar `SettingsService` en la clase.
2. Reemplazar el `$settingsTable->save($entity)` directo por `$this->settingsService->saveSetting($key, $value)`.
3. Si el sitio modifica múltiples keys, hacer múltiples llamadas o invocar `clearAllCaches()` manualmente al terminar.

- [ ] **Step 2.3: Auditar el cache config**

`SettingsService` y `TicketServiceInitializerTrait` usan `CacheConstants::CACHE_CONFIG = '_cake_core_'` (`src/Constants/CacheConstants.php:12`).

`_cake_core_` es el cache de bootstrap de CakePHP — pensado para metadatos compilados, no para runtime data como settings. Esto es una deuda menor pero NO se cambia en este commit (scope creep). Documentar en el commit message para que se aborde en fase 3.

- [ ] **Step 2.4: cs-check**

```bash
composer cs-check
```
Esperado: PASS.

- [ ] **Step 2.5: Smoke manual**

1. Levantar la app.
2. Login como admin → `/admin/settings`.
3. Cambiar el valor de "Título del sistema" (`system_title`) a algo nuevo (ej: "Mesa Test").
4. Guardar.
5. Recargar cualquier página (ej: `/`) y verificar que el header/title refleja el nuevo valor SIN reiniciar el contenedor.
6. Restaurar el valor original.

- [ ] **Step 2.6: Commit**

```bash
git add -A
git commit -m "fix(settings): verify cache invalidation on persist — close audit 4.8

Auditoria de todos los call-sites que persisten en system_settings:
todos pasan por SettingsService::saveSetting(), que invoca
clearAllCaches() invalidando las 4 keys (system_settings,
whatsapp_settings, n8n_settings, gmail_settings).

Deuda residual documentada (no abordada aqui para evitar scope creep):
CacheConstants::CACHE_CONFIG apunta a '_cake_core_', el cache de
bootstrap de CakePHP. Idealmente runtime data como settings deberia
vivir en un cache config separado (ej: 'system_settings_cache') con
TTL explicito. Queda para fase 3.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

(Si Step 2.1/2.2 no encontró nada que cambiar, este commit puede ser solo documentación. Si no hay cambios de archivos, omitir el commit y notar en el commit del Task 3 con un trailer `Audit-4.8: verified, no code changes needed`.)

---

## Task 3: 4.5 — Mover query inline de TicketsSidebarCell a SidebarCountsService

**Files:**
- Modify: `src/Service/SidebarCountsService.php`
- Modify: `src/View/Cell/TicketsSidebarCell.php`

- [ ] **Step 3.1: Añadir método `getAgentStatusCounts` al servicio**

Editar `src/Service/SidebarCountsService.php`. Después del método `getSidebarCounts` (línea 54, antes del `}` final de la clase), añadir:

```php

    /**
     * Get per-status ticket counts for tickets assigned to a specific agent.
     *
     * Returns an associative array of status => count for OPEN_STATUSES only
     * (nuevo, abierto, pendiente). Statuses with no tickets are absent from
     * the result.
     *
     * @param int $userId Agent user ID
     * @return array<string, int>
     */
    public function getAgentStatusCounts(int $userId): array
    {
        $table = $this->fetchTable('Tickets');

        return $table->find()
            ->select(['status', 'count' => $table->find()->func()->count('*')])
            ->where([
                'assignee_id' => $userId,
                'status IN' => TicketConstants::OPEN_STATUSES,
            ])
            ->groupBy(['status'])
            ->all()
            ->combine('status', 'count')
            ->toArray();
    }
```

- [ ] **Step 3.2: Reemplazar query inline en el Cell**

Editar `src/View/Cell/TicketsSidebarCell.php`. Reemplazar líneas 38-48:

```php
        // For agents: count status-specific tickets assigned to them
        $agentStatusCounts = [];
        if ($isAgent && $userId) {
            $ticketsTable = $this->fetchTable('Tickets');
            $agentStatusCounts = $ticketsTable->find()
                ->select(['status', 'count' => $ticketsTable->find()->func()->count('*')])
                ->where(['assignee_id' => $userId, 'status IN' => TicketConstants::OPEN_STATUSES])
                ->groupBy(['status'])
                ->all()
                ->combine('status', 'count')
                ->toArray();
        }
```

por:

```php
        // For agents: count status-specific tickets assigned to them
        $agentStatusCounts = [];
        if ($isAgent && $userId) {
            $agentStatusCounts = $service->getAgentStatusCounts($userId);
        }
```

- [ ] **Step 3.3: Limpiar imports no usados**

En `src/View/Cell/TicketsSidebarCell.php`:
- Si `TicketConstants` ya no se usa en ningún otro lugar del archivo, eliminar la línea `use App\Constants\TicketConstants;` (línea 7).
- Verificar buscando `TicketConstants` en el archivo después del cambio:

```bash
grep -n "TicketConstants" src/View/Cell/TicketsSidebarCell.php
```

Si no aparece (excepto la línea `use`), eliminar el `use`.

- [ ] **Step 3.4: cs-check**

```bash
composer cs-check
```

- [ ] **Step 3.5: Smoke manual**

1. Levantar app, login como admin (debe ver counts globales).
2. Verificar sidebar izquierdo: contadores `Sin asignar`, `Todos sin resolver`, `Pendientes`, `Nuevos`, `Abiertos`, `Resueltos` muestran números coherentes.
3. Logout, login como agent (usuario con role `agent`).
4. Verificar que aparece `Mis tickets` y que `Nuevos`, `Abiertos`, `Pendientes` muestran SOLO los asignados al agente.
5. Comparar contra estado pre-cambio (los números deben ser idénticos).

- [ ] **Step 3.6: Commit**

```bash
git add src/Service/SidebarCountsService.php src/View/Cell/TicketsSidebarCell.php
git commit -m "refactor(sidebar): move agent counts query into SidebarCountsService — close audit 4.5

TicketsSidebarCell ejecutaba un find() directo sobre la tabla Tickets
para calcular los counts por estado del agente actual, bypassando la
capa de servicio que ya consume para los counts globales.

Se anade SidebarCountsService::getAgentStatusCounts(int userId): array
y el Cell pasa a delegar el calculo. El Cell queda como wrapper de
presentacion sin queries.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: 4.4 — Limpieza de Helpers (StatusHelper + eliminar TicketHelper)

**Contexto:**
- `StatusHelper` ya lee constantes de `TicketConstants` (parte de 3.4 está cerrado). Lo pendiente es el HTML inline con `style="background-color: ...; color: white; ..."` en `statusBadge` y `priorityBadge` (`src/View/Helper/StatusHelper.php:59-108`).
- `TicketHelper::getViewUrl` (`src/View/Helper/TicketHelper.php`) es un wrapper trivial. Único call-site: `templates/Tickets/index.php:107` y declaración en `templates/Tickets/index.php:6`.

**Files:**
- Create: `templates/element/tickets/status_badge.php`
- Create: `templates/element/tickets/priority_badge.php`
- Create: `webroot/css/badges.css`
- Modify: `src/View/Helper/StatusHelper.php`
- Modify: `templates/Tickets/index.php`
- Modify: `templates/layout/default.php` (cargar badges.css)
- Modify: `CLAUDE.md` (remover referencias a TicketHelper)
- Delete: `src/View/Helper/TicketHelper.php`

- [ ] **Step 4.1: Crear elements de badges**

Crear `templates/element/tickets/status_badge.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $status Status key (e.g. 'nuevo', 'abierto')
 * @var string $label Human-readable label
 * @var string|null $url Optional URL — wraps badge in <a>
 */
declare(strict_types=1);

$badge = sprintf(
    '<span class="badge badge-status badge-status-%s">%s</span>',
    h($status),
    h($label),
);

if (!empty($url)) {
    echo $this->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
} else {
    echo $badge;
}
```

Crear `templates/element/tickets/priority_badge.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $priority Priority key (e.g. 'baja', 'urgente')
 * @var string $label Human-readable label
 * @var string|null $url Optional URL — wraps badge in <a>
 */
declare(strict_types=1);

$badge = sprintf(
    '<span class="badge badge-priority badge-priority-%s">%s</span>',
    h($priority),
    h($label),
);

if (!empty($url)) {
    echo $this->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
} else {
    echo $badge;
}
```

- [ ] **Step 4.2: Crear hoja de estilos para los badges**

Crear `webroot/css/badges.css`:

```css
/* Badges para tickets — extraido de StatusHelper inline (audit 4.4) */
/* Colores derivados de TicketConstants::STATUS_COLORS y PRIORITY_COLORS */

.badge-status,
.badge-priority {
    color: white;
    border-radius: 8px;
    padding: 0.35rem 0.65rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-status-nuevo     { background-color: #ffc107; }
.badge-status-abierto   { background-color: #dc3545; }
.badge-status-pendiente { background-color: #0d6efd; }
.badge-status-resuelto  { background-color: #198754; }

.badge-priority-baja    { background-color: #6c757d; }
.badge-priority-media   { background-color: #0dcaf0; }
.badge-priority-alta    { background-color: #ffc107; }
.badge-priority-urgente { background-color: #dc3545; }
```

- [ ] **Step 4.3: Cargar badges.css en el layout**

Verificar primero el path del layout:
```bash
ls templates/layout/
```

Editar el layout principal (probablemente `templates/layout/default.php`). Buscar la sección donde se cargan otros CSS (`<?= $this->Html->css(...) ?>`). Añadir:

```php
<?= $this->Html->css('badges') ?>
```

Si hay un bloque tipo `$this->Html->css(['bootstrap', 'main'])`, añadir 'badges' al array.

- [ ] **Step 4.4: Reescribir StatusHelper sin HTML inline**

Reemplazar el contenido completo de `src/View/Helper/StatusHelper.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Constants\TicketConstants;
use Cake\View\Helper;

/**
 * Status Helper
 *
 * Thin layer over TicketConstants. Defers HTML rendering to
 * templates/element/tickets/{status,priority}_badge.php with CSS classes
 * defined in webroot/css/badges.css.
 */
class StatusHelper extends Helper
{
    /**
     * @param string $priority Priority key
     * @return string Hex color (kept for non-badge consumers)
     */
    public function priorityColor(string $priority): string
    {
        return TicketConstants::PRIORITY_COLORS[strtolower($priority)] ?? '#6c757d';
    }

    /**
     * @param string $priority Priority key
     * @return string Human-readable label
     */
    public function priorityLabel(string $priority): string
    {
        return TicketConstants::PRIORITY_LABELS[strtolower($priority)] ?? ucfirst($priority);
    }

    /**
     * @param string $status Status key
     * @return string Hex color (kept for non-badge consumers)
     */
    public function statusColor(string $status): string
    {
        return TicketConstants::STATUS_COLORS[strtolower($status)] ?? '#6c757d';
    }

    /**
     * @param string $status Status key
     * @return string Human-readable label
     */
    public function statusLabel(string $status): string
    {
        return TicketConstants::STATUS_LABELS[strtolower($status)]
            ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * @param string $status Status key
     * @param array{url?: mixed} $options Optional ['url' => mixed]
     * @return string HTML badge
     */
    public function statusBadge(string $status, array $options = []): string
    {
        $key = strtolower($status);

        return $this->getView()->element('tickets/status_badge', [
            'status' => $key,
            'label' => $this->statusLabel($key),
            'url' => $options['url'] ?? null,
        ]);
    }

    /**
     * @param string $priority Priority key
     * @param array{url?: mixed} $options Optional ['url' => mixed]
     * @return string HTML badge
     */
    public function priorityBadge(string $priority, array $options = []): string
    {
        $key = strtolower($priority);

        return $this->getView()->element('tickets/priority_badge', [
            'priority' => $key,
            'label' => $this->priorityLabel($key),
            'url' => $options['url'] ?? null,
        ]);
    }
}
```

- [ ] **Step 4.5: Eliminar TicketHelper**

```bash
rm src/View/Helper/TicketHelper.php
```

- [ ] **Step 4.6: Reemplazar referencias a TicketHelper en templates**

Editar `templates/Tickets/index.php`:

1. Eliminar la línea 6 (`@var \App\View\Helper\TicketHelper $Ticket`).
2. Línea 107: reemplazar `$this->Ticket->getViewUrl($ticket)` por `['action' => 'view', $ticket->id]`.

Verificar que no quedan otras referencias:
```bash
grep -rn "TicketHelper\|\$this->Ticket->" templates/ src/
```
Esperado: cero resultados.

- [ ] **Step 4.7: Actualizar CLAUDE.md**

Editar `CLAUDE.md`. Buscar la mención a Helpers. La sección actual no lista TicketHelper explícitamente, pero verificar:

```bash
grep -n "TicketHelper\|StatusHelper" CLAUDE.md
```

Si aparece `TicketHelper`, eliminar la mención. Si la sección de Helpers describe a StatusHelper como generador de HTML inline, actualizar a "delega rendering a templates/element/tickets/{status,priority}_badge.php".

- [ ] **Step 4.8: cs-check**

```bash
composer cs-check
```

- [ ] **Step 4.9: Smoke manual**

1. Levantar app.
2. Abrir `/` (listado de tickets).
3. Verificar que badges de estado y prioridad se ven con los mismos colores que antes (amarillo, rojo, azul, verde).
4. Abrir un ticket — verificar badges en sidebar izquierdo (`templates/element/tickets/left_sidebar.php`).
5. Inspeccionar el HTML de un badge: debe ser `<span class="badge badge-status badge-status-X">…</span>` SIN atributo `style=`.
6. Verificar en DevTools que `badges.css` se carga (Network tab).

- [ ] **Step 4.10: Commit**

```bash
git add src/View/Helper/StatusHelper.php templates/element/tickets/ webroot/css/badges.css templates/Tickets/index.php templates/layout/default.php CLAUDE.md
git rm src/View/Helper/TicketHelper.php
git commit -m "refactor(view): consolidate StatusHelper, drop trivial TicketHelper — close audit 4.4

StatusHelper:
- statusBadge() y priorityBadge() ya no concatenan HTML con
  estilos inline. Delegan a templates/element/tickets/{status,priority}_badge.php
  con clases CSS definidas en webroot/css/badges.css.
- Mantenidos statusColor/priorityColor/statusLabel/priorityLabel
  por si algun consumidor los usa fuera del badge.

TicketHelper eliminado:
- Su unico metodo getViewUrl(\$ticket) era un wrapper de
  ['action' => 'view', \$ticket->id]. Templates actualizados a usar
  el array literal directamente.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: 4.2 — Decidir entre EmailTemplateRenderer y NotificationRenderer

**Contexto:** Lectura de ambos archivos confirma que NO se solapan tanto como sugería el audit:
- `EmailTemplateRenderer` (153 LOC): carga templates de la tabla `email_templates`, los cachea, sustituye `{{var}}`. Es un **template loader + renderer de strings**.
- `NotificationRenderer` (136 LOC): formatters utilitarios (`formatDate`, `getTicketUrl`, `getStatusLabel`, `renderAttachmentsHtml`, `renderStatusChangeHtml`, `renderWhatsappNewTicket`). Es un **kit de formatters/composers**.

Las responsabilidades son diferentes. La confusión viene del nombre similar.

**Decisión recomendada (capas):** mantener ambos pero clarificar roles vía docblocks y, opcionalmente, renombrar `NotificationRenderer` a `NotificationFormatter` para que no compita semánticamente con `EmailTemplateRenderer`.

**Files:**
- Modify: `src/Service/EmailTemplateRenderer.php`
- Modify: `src/Service/Renderer/NotificationRenderer.php`

- [ ] **Step 5.1: Time-box de inspección (max 1h)**

Antes de aplicar la decisión recomendada, verificar consumidores de cada clase:

```bash
grep -rn "EmailTemplateRenderer\|new EmailTemplateRenderer" src/ --include='*.php' | grep -v "src/Service/EmailTemplateRenderer.php"
grep -rn "NotificationRenderer\|new NotificationRenderer" src/ --include='*.php' | grep -v "src/Service/Renderer/NotificationRenderer.php"
```

Si los consumidores son distintos (sin solapamiento real), aplicar Step 5.2 (capas).
Si los consumidores se mezclan o llaman a métodos similares, considerar consolidación (Step 5.3 alternativo).

- [ ] **Step 5.2: Aplicar decisión "capas" — clarificar roles vía docblocks**

Editar `src/Service/EmailTemplateRenderer.php`. Reemplazar el docblock de la clase (líneas 14-19) por:

```php
/**
 * EmailTemplateRenderer
 *
 * **Layer:** template loader + string renderer.
 *
 * Loads email templates from the `email_templates` table, caches them
 * in-memory and renders them by replacing {{variable}} placeholders.
 *
 * For domain-specific formatting (dates, status labels, attachments
 * HTML, WhatsApp message text), use {@see \App\Service\Renderer\NotificationRenderer}
 * instead.
 */
```

Editar `src/Service/Renderer/NotificationRenderer.php`. Reemplazar el docblock de la clase (líneas 12-17) por:

```php
/**
 * NotificationRenderer
 *
 * **Layer:** domain formatter for notifications.
 *
 * Formats values (dates, URLs, status labels) and renders HTML/text
 * fragments (attachment lists, status-change blocks, WhatsApp messages)
 * used to fill template variables.
 *
 * Does NOT load or render full email templates — for that, use
 * {@see \App\Service\EmailTemplateRenderer}.
 */
```

- [ ] **Step 5.3: (Solo si Step 5.1 reveló consolidación) Consolidar**

Si los consumidores se solapan significativamente, en vez de Step 5.2:
1. Mover métodos de `NotificationRenderer` a `EmailTemplateRenderer` (o viceversa, dependiendo de cuál tiene más consumidores).
2. Actualizar todos los call-sites del archivo eliminado.
3. Eliminar el archivo absorbido y su `use` statements.
4. Renombrar la clase superviviente si tiene más sentido.

(Solo aplicar Step 5.3 si Step 5.1 lo justifica. La decisión recomendada por defecto es Step 5.2.)

- [ ] **Step 5.4: cs-check**

```bash
composer cs-check
```

- [ ] **Step 5.5: Smoke manual**

1. Crear un ticket por UI → verificar email saliente (revisar logs o inbox de prueba).
2. Cambiar el estado del ticket → verificar email de cambio de estado.
3. Si está configurado WhatsApp: verificar mensaje WhatsApp de creación.
4. `bin/cake import_gmail --max 5` → si hay correos pendientes, verificar que se crean tickets correctamente con templates renderizados.

- [ ] **Step 5.6: Commit**

```bash
git add src/Service/EmailTemplateRenderer.php src/Service/Renderer/NotificationRenderer.php
git commit -m "refactor(notifications): clarify renderer layers — close audit 4.2

Auditoria revelo que las dos clases no se solapaban tanto como
sugeria el audit:

- EmailTemplateRenderer: template loader + string renderer
  (carga templates de DB, sustituye {{vars}}).
- NotificationRenderer: domain formatter (formatDate, getTicketUrl,
  getStatusLabel, renderAttachmentsHtml, renderStatusChangeHtml,
  renderWhatsappNewTicket).

Decision: mantener ambas, clarificar el rol de cada una via docblocks
con cross-references. La confusion venia del nombre similar.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

(Si se ejecutó Step 5.3 en vez de 5.2, ajustar el commit message para describir la consolidación.)

---

## Task 6: 4.1 — Trocear TicketService en 3 servicios

**Contexto:** `TicketService` tiene 1046 LOC. Se extraen dos servicios:
- `TicketIngestionService`: entrada desde email/WhatsApp (`createFromEmail`, `createCommentFromEmail`, helpers).
- `TicketNotificationService`: despacho de notificaciones (`dispatchCreationNotifications`, `dispatchUpdateNotifications`, `sendResponseNotifications`).

`TicketService` retiene: `addComment`, `addTag`, `removeTag`, `addFollower`, `changeStatus`, `assign`, `changePriority`, `handleResponse`, `saveUploadedFile`, `logHistory`, `buildResponseResult`, `decodeEmailRecipients`, `sanitizeHtml`.

**Métodos a mover y dependencias:**

| Método actual en TicketService | Va a | Dependencias |
|---|---|---|
| `createFromEmail` (líneas 73-176) | `TicketIngestionService` | `findOrCreateUser`, `processEmailAttachments`, `dispatchCreationNotifications`, `getN8nService`, `sanitizeHtml` |
| `createCommentFromEmail` (líneas 185-270) | `TicketIngestionService` | `findOrCreateUser`, `isEmailInTicketRecipients`, `processEmailAttachments`, `sanitizeHtml` |
| `findOrCreateUser` (líneas 279-320) | `TicketIngestionService` | — |
| `isEmailInTicketRecipients` (líneas 329-368) | `TicketIngestionService` | — |
| `processEmailAttachments` (líneas 379-414) | `TicketIngestionService` | `GenericAttachmentTrait::saveAttachmentFromBinary` |
| `dispatchCreationNotifications` (líneas 965-991) | `TicketNotificationService` | `EmailService`, `WhatsappService` |
| `dispatchUpdateNotifications` (líneas 999-1043) | `TicketNotificationService` | `EmailService` |
| `sendResponseNotifications` (líneas 875-908) | `TicketNotificationService` | `dispatchUpdateNotifications` |

`sanitizeHtml` se duplica/extrae a un trait compartido (decisión más limpia que duplicar).

**Files:**
- Create: `src/Service/TicketIngestionService.php`
- Create: `src/Service/TicketNotificationService.php`
- Create: `src/Service/Traits/HtmlSanitizerTrait.php`
- Modify: `src/Service/TicketService.php`
- Modify: `src/Service/GmailImportService.php` (consume `TicketIngestionService` en vez de `TicketService::createFromEmail`)
- Modify: `src/Controller/Trait/TicketServiceInitializerTrait.php` (instanciar también el `TicketNotificationService` si necesario)
- Modify: cualquier consumer afectado (Tickets traits que llamaban a `dispatchCreationNotifications` o similares)

- [ ] **Step 6.1: Crear `HtmlSanitizerTrait`**

Crear `src/Service/Traits/HtmlSanitizerTrait.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Traits;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HtmlSanitizerTrait
 *
 * Standard HTML sanitization for ticket bodies and comments.
 * Allows only a safe whitelist of tags and attributes; targets _blank
 * for links to prevent reverse-tabnabbing.
 */
trait HtmlSanitizerTrait
{
    /**
     * Sanitize HTML content to prevent stored XSS
     *
     * @param string $html Raw HTML content
     * @return string Sanitized HTML
     */
    protected function sanitizeHtml(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }
}
```

- [ ] **Step 6.2: Crear `TicketNotificationService`**

Crear `src/Service/TicketNotificationService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Exception;

/**
 * Ticket Notification Service
 *
 * Despachador de notificaciones para tickets:
 * - Notificaciones de creacion (Email + WhatsApp).
 * - Notificaciones de cambio de estado, comentario y respuesta (Email).
 *
 * Las plantillas y formateo viven en EmailTemplateRenderer y
 * Renderer\NotificationRenderer (consumidos indirectamente via
 * EmailService).
 */
class TicketNotificationService
{
    private EmailService $emailService;
    private WhatsappService $whatsappService;

    /**
     * @param array<string, mixed>|null $systemConfig Optional system configuration
     * @param \App\Service\EmailService|null $emailService Optional injected email service
     * @param \App\Service\WhatsappService|null $whatsappService Optional injected whatsapp service
     */
    public function __construct(
        ?array $systemConfig = null,
        ?EmailService $emailService = null,
        ?WhatsappService $whatsappService = null,
    ) {
        $this->emailService = $emailService ?? new EmailService($systemConfig);
        $this->whatsappService = $whatsappService ?? new WhatsappService($systemConfig);
    }

    /**
     * Dispatch creation notifications (Email + WhatsApp).
     */
    public function dispatchCreationNotifications(
        EntityInterface $entity,
        bool $sendEmail = true,
        bool $sendWhatsapp = true,
    ): void {
        if ($sendEmail) {
            try {
                $this->emailService->sendNewEntityNotification($entity);
            } catch (Exception $e) {
                Log::error('Failed to send ticket creation email', [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }

        if ($sendWhatsapp) {
            try {
                $this->whatsappService->sendNewEntityNotification($entity);
            } catch (Exception $e) {
                Log::error('Failed to send ticket creation WhatsApp', [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }
    }

    /**
     * Dispatch update notifications (Email only).
     *
     * @param string $notificationType 'status_change', 'comment', 'response'
     * @param array<string, mixed> $context Additional context (old_status, new_status, comment, etc.)
     */
    public function dispatchUpdateNotifications(
        EntityInterface $entity,
        string $notificationType,
        array $context = [],
    ): void {
        try {
            switch ($notificationType) {
                case 'status_change':
                    $this->emailService->sendEntityStatusChangeNotification(
                        $entity,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                    );
                    break;

                case 'comment':
                    $this->emailService->sendEntityCommentNotification(
                        $entity,
                        $context['comment'] ?? null,
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? [],
                    );
                    break;

                case 'response':
                    $this->emailService->sendEntityResponseNotification(
                        $entity,
                        $context['comment'] ?? null,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? [],
                    );
                    break;

                default:
                    Log::warning("Unknown notification type: {$notificationType}");
            }
        } catch (Exception $e) {
            Log::error("Failed to send ticket {$notificationType} email", [
                'error' => $e->getMessage(),
                'entity_id' => $entity->id,
            ]);
        }
    }

    /**
     * Send notifications based on response changes (comment + status + files).
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket
     * @param mixed $comment Comment entity or null
     * @param string $oldStatus Status before change
     * @param string|null $newStatus Status after change
     * @param bool $hasComment Whether a comment was added
     * @param string $commentType 'public' or 'internal'
     * @param bool $hasStatusChange Whether status changed
     * @param array $emailTo Additional To recipients
     * @param array $emailCc Additional Cc recipients
     */
    public function sendResponseNotifications(
        EntityInterface $entity,
        $comment,
        string $oldStatus,
        ?string $newStatus,
        bool $hasComment,
        string $commentType,
        bool $hasStatusChange,
        array $emailTo = [],
        array $emailCc = [],
    ): void {
        $hasPublicComment = $hasComment && $commentType === 'public';

        if ($hasPublicComment && $hasStatusChange && $comment) {
            $this->dispatchUpdateNotifications($entity, 'response', [
                'comment' => $comment,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasPublicComment && $comment) {
            $this->dispatchUpdateNotifications($entity, 'comment', [
                'comment' => $comment,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasStatusChange) {
            $this->dispatchUpdateNotifications($entity, 'status_change', [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }
    }
}
```

- [ ] **Step 6.3: Crear `TicketIngestionService`**

Crear `src/Service/TicketIngestionService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Service\Traits\GenericAttachmentTrait;
use App\Service\Traits\HtmlSanitizerTrait;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Ticket Ingestion Service
 *
 * Manejo de ingreso de tickets y respuestas desde canales externos:
 * - Creacion de tickets desde email (Gmail import).
 * - Creacion de comentarios desde respuestas en thread de email.
 * - Auto-creacion de usuarios desde el remitente.
 * - Procesamiento de adjuntos descargados desde Gmail.
 *
 * Notificaciones de creacion delegadas a TicketNotificationService.
 * Webhook a n8n para auto-tagging delegado a N8nService.
 */
class TicketIngestionService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;
    use HtmlSanitizerTrait;

    private TicketNotificationService $notificationService;
    private ?N8nService $n8nService = null;
    private ?array $systemConfig = null;

    /**
     * @param array<string, mixed>|null $systemConfig Optional system configuration
     * @param \App\Service\TicketNotificationService|null $notificationService Optional injected notification service
     */
    public function __construct(
        ?array $systemConfig = null,
        ?TicketNotificationService $notificationService = null,
    ) {
        $this->systemConfig = $systemConfig;
        $this->notificationService = $notificationService ?? new TicketNotificationService($systemConfig);
    }

    /**
     * Get N8nService instance (lazy loading)
     */
    private function getN8nService(): N8nService
    {
        if ($this->n8nService === null) {
            $this->n8nService = new N8nService($this->systemConfig);
        }

        return $this->n8nService;
    }

    /**
     * Create ticket from email data
     *
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\Ticket|null Created ticket or null on failure
     */
    public function createFromEmail(array $emailData): ?Ticket
    {
        $ticketsTable = $this->fetchTable('Tickets');

        if (!empty($emailData['gmail_message_id'])) {
            $existing = $ticketsTable->find()
                ->where(['gmail_message_id' => $emailData['gmail_message_id']])
                ->first();

            if ($existing) {
                Log::info('Ticket already exists for Gmail message: ' . $emailData['gmail_message_id']);

                return $existing;
            }
        }

        $parser = new GmailService();
        $fromEmail = $parser->extractEmailAddress($emailData['from']);
        $fromName = $parser->extractName($emailData['from']);

        $user = $this->findOrCreateUser($fromEmail, $fromName);
        if (!$user) {
            Log::error('Failed to create user for email: ' . $fromEmail);

            return null;
        }

        $rawBody = $emailData['body_html'] ?: $emailData['body_text'];
        $description = $this->sanitizeHtml($rawBody);

        $ticketNumber = $ticketsTable->generateTicketNumber();

        $subject = trim($emailData['subject'] ?? '');
        if (empty($subject)) {
            $subject = '(Sin asunto)';
        }

        $channel = 'email';
        $whatsappBotEmail = 'mesadeayuda.whatsapp@gmail.com';
        if (strtolower($fromEmail) === strtolower($whatsappBotEmail)) {
            $channel = 'whatsapp';
        }

        $ticket = $ticketsTable->newEntity([
            'ticket_number' => $ticketNumber,
            'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
            'gmail_thread_id' => $emailData['gmail_thread_id'] ?? null,
            'subject' => $subject,
            'description' => $description,
            'status' => 'nuevo',
            'priority' => 'media',
            'requester_id' => $user->id,
            'channel' => $channel,
            'source_email' => $fromEmail,
        ], ['accessibleFields' => [
            'ticket_number' => true, 'gmail_message_id' => true, 'gmail_thread_id' => true,
            'status' => true, 'requester_id' => true, 'channel' => true, 'source_email' => true,
        ]]);
        assert($ticket instanceof Ticket);

        $ticket->email_to = !empty($emailData['email_to']) ? $emailData['email_to'] : null;
        $ticket->email_cc = !empty($emailData['email_cc']) ? $emailData['email_cc'] : null;

        if (!$ticketsTable->save($ticket)) {
            Log::error('Failed to save ticket', ['errors' => $ticket->getErrors()]);

            return null;
        }

        if (!empty($emailData['attachments'])) {
            $this->processEmailAttachments($ticket, $emailData['attachments'], $user->id);
        }

        $this->notificationService->dispatchCreationNotifications($ticket);

        try {
            $this->getN8nService()->sendTicketCreatedWebhook($ticket);
        } catch (Exception $e) {
            Log::warning('n8n webhook failed (non-blocking): ' . $e->getMessage());
        }

        Log::info('Created ticket from email', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'from' => $fromEmail,
        ]);

        return $ticket;
    }

    /**
     * Create comment from email response in existing thread
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket to add comment to
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\TicketComment|null Created comment or null
     */
    public function createCommentFromEmail(Ticket $ticket, array $emailData): ?TicketComment
    {
        $ticketCommentsTable = $this->fetchTable('TicketComments');

        $parser = new GmailService();
        $fromEmail = $parser->extractEmailAddress($emailData['from']);
        $fromName = $parser->extractName($emailData['from']);

        if (!$this->isEmailInTicketRecipients($ticket, $fromEmail)) {
            Log::warning('Unauthorized email sender attempted to reply to ticket', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'from_email' => $fromEmail,
            ]);

            return null;
        }

        $user = $this->findOrCreateUser($fromEmail, $fromName);
        if (!$user) {
            Log::error('Failed to create user for email comment', ['email' => $fromEmail]);

            return null;
        }

        $rawBody = $emailData['body_html'] ?: $emailData['body_text'];
        $body = $this->sanitizeHtml($rawBody);

        $maxLength = 65000;
        if (strlen($body) > $maxLength) {
            Log::warning('Email body truncated to prevent DB overflow', [
                'ticket_id' => $ticket->id,
                'original_length' => strlen($body),
                'truncated_length' => $maxLength,
            ]);
            $body = substr($body, 0, $maxLength);
        }

        $comment = $ticketCommentsTable->newEntity([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $body,
            'comment_type' => 'public',
            'is_system_comment' => false,
            'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
            'sent_as_email' => false,
            'email_to' => !empty($emailData['email_to']) ? json_encode($emailData['email_to']) : null,
            'email_cc' => !empty($emailData['email_cc']) ? json_encode($emailData['email_cc']) : null,
        ], ['accessibleFields' => [
            'user_id' => true, 'is_system_comment' => true, 'gmail_message_id' => true, 'sent_as_email' => true,
        ]]);
        assert($comment instanceof TicketComment);

        if (!$ticketCommentsTable->save($comment)) {
            Log::error('Failed to save ticket comment from email', [
                'ticket_id' => $ticket->id,
                'errors' => $comment->getErrors(),
            ]);

            return null;
        }

        if (!empty($emailData['attachments'])) {
            $this->processEmailAttachments($ticket, $emailData['attachments'], $user->id, $comment->id);
        }

        Log::info('Created ticket comment from email', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'comment_id' => $comment->id,
            'from_email' => $fromEmail,
        ]);

        return $comment;
    }

    /**
     * Find existing user or create new one
     */
    private function findOrCreateUser(string $email, string $name): ?User
    {
        $usersTable = $this->fetchTable('Users');

        $user = $usersTable->find()
            ->where(['email' => $email])
            ->first();

        if ($user) {
            return $user;
        }

        $nameParts = explode(' ', $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(' ', $nameParts);

        if (empty($lastName)) {
            $lastName = $firstName;
        }

        $user = $usersTable->newEntity([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => 'requester',
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
        assert($user instanceof User);

        if ($usersTable->save($user)) {
            Log::info('Auto-created user from email', ['email' => $email, 'name' => $name]);

            return $user;
        }

        Log::error('Failed to create user', ['email' => $email, 'errors' => $user->getErrors()]);

        return null;
    }

    /**
     * Check if email address is in ticket's original To/CC recipients
     */
    private function isEmailInTicketRecipients(Ticket $ticket, string $email): bool
    {
        $normalizedEmail = strtolower(trim($email));

        $emailTo = $ticket->email_to_array;
        if (!empty($emailTo)) {
            foreach ($emailTo as $recipient) {
                if (isset($recipient['email']) && strtolower(trim($recipient['email'])) === $normalizedEmail) {
                    return true;
                }
            }
        }

        $emailCc = $ticket->email_cc_array;
        if (!empty($emailCc)) {
            foreach ($emailCc as $recipient) {
                if (isset($recipient['email']) && strtolower(trim($recipient['email'])) === $normalizedEmail) {
                    return true;
                }
            }
        }

        if (!isset($ticket->requester)) {
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket->id, [
                'contain' => ['Requesters'],
            ]);
        }

        if (isset($ticket->requester->email) && strtolower(trim($ticket->requester->email)) === $normalizedEmail) {
            return true;
        }

        return false;
    }

    /**
     * Process email attachments (using GenericAttachmentTrait)
     */
    private function processEmailAttachments(EntityInterface $ticket, array $attachments, int $userId, ?int $commentId = null): void
    {
        assert($ticket instanceof Ticket);
        $gmailService = new GmailService(GmailService::loadConfigFromDatabase());

        foreach ($attachments as $attachmentData) {
            try {
                usleep(200000);

                $content = $gmailService->downloadAttachment(
                    $ticket->gmail_message_id,
                    $attachmentData['attachment_id'],
                );

                $this->saveAttachmentFromBinary(
                    $ticket,
                    $attachmentData['filename'],
                    $content,
                    $attachmentData['mime_type'],
                    $commentId,
                    $userId,
                );
            } catch (Exception $e) {
                Log::error('Failed to process attachment', [
                    'ticket_id' => $ticket->id,
                    'filename' => $attachmentData['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
```

- [ ] **Step 6.4: Adelgazar `TicketService`**

Reemplazar el contenido completo de `src/Service/TicketService.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Attachment;
use App\Model\Entity\Ticket;
use App\Service\Exception\InvalidStatusTransitionException;
use App\Service\Traits\GenericAttachmentTrait;
use App\Service\Traits\HtmlSanitizerTrait;
use Cake\Datasource\EntityInterface;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Ticket Service
 *
 * Pipeline operations for tickets owned by the application:
 * - Status changes (with state machine validation).
 * - Assignment changes.
 * - Priority changes.
 * - Comments (UI-driven; comments-from-email live in TicketIngestionService).
 * - Tag/follower management.
 * - File uploads from UI.
 * - Coordinated response handling (comment + status + files + notifications).
 *
 * Email/WhatsApp ingestion lives in TicketIngestionService.
 * Notification dispatch lives in TicketNotificationService.
 */
class TicketService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;
    use HtmlSanitizerTrait;

    private TicketNotificationService $notificationService;

    /**
     * @param array<string, mixed>|null $systemConfig Optional system configuration
     * @param \App\Service\TicketNotificationService|null $notificationService Optional injected notification service
     */
    public function __construct(
        ?array $systemConfig = null,
        ?TicketNotificationService $notificationService = null,
    ) {
        $this->notificationService = $notificationService ?? new TicketNotificationService($systemConfig);
    }

    /**
     * Save uploaded file (using GenericAttachmentTrait for form uploads)
     */
    public function saveUploadedFile(
        Ticket|int $ticket,
        UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null,
    ): ?Attachment {
        if (is_int($ticket)) {
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket);
        }
        assert($ticket instanceof Ticket);

        $result = $this->saveGenericUploadedFile($ticket, $file, $commentId, $userId);
        assert($result instanceof Attachment || $result === null);

        return $result;
    }

    /**
     * Handle a complete response (comment + status change + files + notifications)
     */
    public function handleResponse(int $entityId, int $userId, array $data, array $files): array
    {
        $commentBody = $data['comment_body'] ?? $data['body'] ?? '';
        $commentType = $data['comment_type'] ?? 'public';
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

        $comment = null;
        $uploadedCount = 0;

        if ($hasComment) {
            $comment = $this->addComment($entityId, $userId, $commentBody, $commentType, false, $emailTo, $emailCc);

            if (!$comment) {
                return [
                    'success' => false,
                    'message' => 'Error al agregar el comentario.',
                    'entity' => $entity,
                ];
            }

            if (!empty($files['attachments'])) {
                foreach ($files['attachments'] as $file) {
                    if ($file->getError() === UPLOAD_ERR_OK) {
                        $result = $this->saveUploadedFile($entity, $file, $comment->id, $userId);
                        if ($result) {
                            $uploadedCount++;
                        }
                    }
                }
            }
        }

        if ($hasStatusChange) {
            $this->changeStatus($entity, $newStatus, $userId, null, false);
            $entity->status = $newStatus;
        }

        $this->notificationService->sendResponseNotifications(
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

    /**
     * Add tag to ticket
     *
     * @return array{success: bool, message: string}
     */
    public function addTag(int $ticketId, int $tagId): array
    {
        $ticketsTable = $this->fetchTable('Tickets');
        $ticketsTable->get($ticketId);

        $ticketTagsTable = $this->fetchTable('TicketTags');

        $exists = $ticketTagsTable->find()
            ->where(['ticket_id' => $ticketId, 'tag_id' => $tagId])
            ->count();

        if ($exists) {
            return ['success' => false, 'message' => 'Esta etiqueta ya está agregada.'];
        }

        $ticketTag = $ticketTagsTable->newEntity([
            'ticket_id' => $ticketId,
            'tag_id' => $tagId,
        ]);

        if ($ticketTagsTable->save($ticketTag)) {
            return ['success' => true, 'message' => 'Etiqueta agregada.'];
        }

        return ['success' => false, 'message' => 'Error al agregar la etiqueta.'];
    }

    /**
     * Remove tag from ticket
     *
     * @return array{success: bool, message: string}
     */
    public function removeTag(int $ticketId, int $tagId): array
    {
        $ticketTagsTable = $this->fetchTable('TicketTags');

        $ticketTag = $ticketTagsTable->find()
            ->where(['ticket_id' => $ticketId, 'tag_id' => $tagId])
            ->first();

        if ($ticketTag && $ticketTagsTable->delete($ticketTag)) {
            return ['success' => true, 'message' => 'Etiqueta eliminada.'];
        }

        return ['success' => false, 'message' => 'Error al eliminar la etiqueta.'];
    }

    /**
     * Add follower to ticket
     *
     * @return array{success: bool, message: string}
     */
    public function addFollower(int $ticketId, int $userId): array
    {
        $followersTable = $this->fetchTable('TicketFollowers');

        $exists = $followersTable->find()
            ->where(['ticket_id' => $ticketId, 'user_id' => $userId])
            ->count();

        if ($exists) {
            return ['success' => false, 'message' => 'Este usuario ya está siguiendo el ticket.'];
        }

        $follower = $followersTable->newEntity([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
        ]);

        if ($followersTable->save($follower)) {
            return ['success' => true, 'message' => 'Seguidor agregado.'];
        }

        return ['success' => false, 'message' => 'Error al agregar seguidor.'];
    }

    /**
     * Change ticket status (with state machine validation).
     */
    public function changeStatus(
        EntityInterface $entity,
        string $newStatus,
        ?int $userId = null,
        ?string $comment = null,
        bool $sendNotifications = true,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $oldStatus = $entity->status;

        if ($oldStatus === $newStatus) {
            return true;
        }

        if ($entity instanceof Ticket && !$entity->canTransitionTo($newStatus)) {
            throw InvalidStatusTransitionException::for($oldStatus, $newStatus);
        }

        $entity->status = $newStatus;

        $now = FrozenTime::now();
        if ($newStatus === 'resuelto' && !$entity->resolved_at) {
            $entity->resolved_at = $now;
        }

        if (!$table->save($entity)) {
            Log::error('Failed to change status', ['errors' => $entity->getErrors()]);

            return false;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'status',
            $oldStatus,
            $newStatus,
            $userId,
            "Estado cambiado de '{$oldStatus}' a '{$newStatus}'",
        );

        $systemComment = $comment ?? "El estado cambió de '{$oldStatus}' a '{$newStatus}'";
        $this->addComment($entity->id, $userId, $systemComment, 'internal', true);

        if ($sendNotifications) {
            $this->notificationService->dispatchUpdateNotifications($entity, 'status_change', [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }

        return true;
    }

    /**
     * Add comment to a ticket.
     *
     * NOTE: This method does NOT send notifications. Notifications are handled
     * by handleResponse() via TicketNotificationService::sendResponseNotifications()
     * for proper coordination of comment + status change + file uploads.
     */
    public function addComment(
        int $entityId,
        ?int $userId,
        string $body,
        string $type = 'public',
        bool $isSystem = false,
        ?array $emailTo = null,
        ?array $emailCc = null,
    ): ?EntityInterface {
        $commentsTable = $this->fetchTable('TicketComments');

        $sanitizedBody = $this->sanitizeHtml($body);

        $data = [
            'ticket_id' => $entityId,
            'user_id' => $userId,
            'comment_type' => $type,
            'body' => $sanitizedBody,
            'is_system_comment' => $isSystem,
        ];

        if ($type === 'public' && !$isSystem) {
            if (is_array($emailTo) && count($emailTo) > 0) {
                $data['email_to'] = json_encode($emailTo);
            }
            if (is_array($emailCc) && count($emailCc) > 0) {
                $data['email_cc'] = json_encode($emailCc);
            }
        }

        $comment = $commentsTable->newEntity($data, ['accessibleFields' => [
            'user_id' => true, 'is_system_comment' => true, 'sent_as_email' => true,
        ]]);

        if (!$commentsTable->save($comment)) {
            Log::error('Failed to add comment', ['errors' => $comment->getErrors()]);

            return null;
        }

        return $comment;
    }

    /**
     * Assign ticket to a user.
     */
    public function assign(
        EntityInterface $entity,
        ?int $assigneeId,
        ?int $userId = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $usersTable = $this->fetchTable('Users');

        $oldAssigneeId = $entity->assignee_id;
        $entity->assignee_id = $assigneeId === 0 || $assigneeId === '0' ? null : $assigneeId;

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
        if ($assigneeId) {
            $newUser = $usersTable->get($assigneeId);
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

        $this->addComment($entity->id, $userId, "Asignado a {$newAssigneeName}", 'internal', true);

        return true;
    }

    /**
     * Change ticket priority.
     */
    public function changePriority(
        EntityInterface $entity,
        string $newPriority,
        ?int $userId = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $oldPriority = $entity->priority;

        if ($oldPriority === $newPriority) {
            return true;
        }

        $entity->priority = $newPriority;

        if (!$table->save($entity)) {
            Log::error('Failed to change priority', ['errors' => $entity->getErrors()]);

            return false;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'priority',
            $oldPriority,
            $newPriority,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
        );

        $this->addComment(
            $entity->id,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
            'internal',
            true,
        );

        return true;
    }

    /**
     * Log change to ticket history.
     */
    private function logHistory(
        string $tableName,
        string $foreignKey,
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?int $userId = null,
        ?string $description = null,
    ): void {
        $historyTable = $this->fetchTable($tableName);

        if (method_exists($historyTable, 'logChange')) {
            $historyTable->logChange($entityId, $fieldName, $oldValue, $newValue, $userId, $description);
        } else {
            $history = $historyTable->newEntity([
                $foreignKey => $entityId,
                'changed_by' => $userId,
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'description' => $description,
            ], ['accessibleFields' => ['changed_by' => true]]);
            $historyTable->save($history);
        }
    }

    /**
     * Build success message for response operations.
     */
    private function buildResponseResult(bool $hasComment, bool $hasStatusChange, int $uploadedCount, $entity): array
    {
        $successMessage = '';
        if ($hasComment && $hasStatusChange) {
            $successMessage = 'Comentario agregado y estado actualizado exitosamente.';
        } elseif ($hasComment) {
            $successMessage = 'Comentario agregado exitosamente.';
        } elseif ($hasStatusChange) {
            $successMessage = 'Estado actualizado exitosamente.';
        }

        if ($uploadedCount > 0) {
            $successMessage .= " ({$uploadedCount} archivo(s) adjunto(s))";
        }

        return [
            'success' => true,
            'message' => $successMessage,
            'entity' => $entity,
        ];
    }

    /**
     * Decode email recipients from JSON string or array.
     */
    private function decodeEmailRecipients($data): array
    {
        if (empty($data)) {
            return [];
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($data)) {
            return $data;
        }

        return [];
    }
}
```

- [ ] **Step 6.5: Migrar consumidores de `createFromEmail` y `createCommentFromEmail`**

Estos métodos ya NO existen en `TicketService`. Buscar y migrar consumidores:

```bash
grep -rn "createFromEmail\|createCommentFromEmail\|->dispatchCreationNotifications\|->dispatchUpdateNotifications" src/ --include='*.php'
```

Sitios esperados (verificar y editar):

**`src/Service/GmailImportService.php`** — busca el `new TicketService(...)` y los call-sites:

Si hay código como:
```php
$ticketService = new TicketService(self::loadSystemSettings());
$ticket = $ticketService->createFromEmail($emailData);
```

Reemplazar por:
```php
$ingestionService = new TicketIngestionService(self::loadSystemSettings());
$ticket = $ingestionService->createFromEmail($emailData);
```

Mismo tratamiento para `createCommentFromEmail`.

**Otros call-sites de `dispatchCreationNotifications` o `dispatchUpdateNotifications` desde controllers/traits:**
- Si llaman `$this->ticketService->dispatchCreationNotifications(...)`, reemplazar por `$this->ticketNotificationService->dispatchCreationNotifications(...)`. Esto requiere inicializar el servicio en `TicketServiceInitializerTrait` (ver Step 6.6).

- [ ] **Step 6.6: Inicializar TicketNotificationService en el trait del controller (si necesario)**

Si Step 6.5 detectó call-sites que necesiten `TicketNotificationService` desde el controller, editar `src/Controller/Trait/TicketServiceInitializerTrait.php`:

Añadir al método `initializeTicketSystemServices()` (línea 36):

```php
    protected function initializeTicketSystemServices(): void
    {
        $this->initializeServices([
            'ticketService' => TicketService::class,
            'ticketNotificationService' => TicketNotificationService::class,
        ]);
    }
```

Y añadir el `use` de `App\Service\TicketNotificationService` arriba.

Si Step 6.5 NO detectó call-sites desde controllers, omitir este step.

- [ ] **Step 6.7: Verificar tamaño objetivo**

```bash
wc -l src/Service/TicketService.php
```
Esperado: ≤ 600 LOC.

Si excede, considerar dividir el commit en 6a (extraer Notification) y 6b (extraer Ingestion). Hacer git reset suave y rehacer en dos commits.

- [ ] **Step 6.8: cs-check**

```bash
composer cs-check
```

- [ ] **Step 6.9: Smoke manual extensivo**

1. **Ingestion path:**
   ```bash
   bin/cake import_gmail --max 5
   ```
   - Si hay correos pendientes, verificar que se crean tickets correctamente.
   - Verificar logs: `Created ticket from email`.
   - Verificar que se enviaron notificaciones de creación.

2. **Comment from email path:**
   - Si hay un thread con respuesta pendiente, verificar que se crea TicketComment.
   - Verificar logs: `Created ticket comment from email`.

3. **Pipeline path (UI):**
   - Login como agent.
   - Cambiar estado de un ticket existente: verificar que cambia, que se loggea en historia, y que llega email de cambio de estado al requester.
   - Reasignar ticket: verificar entrada en historia.
   - Cambiar prioridad: verificar entrada en historia.
   - Agregar comentario público desde la UI: verificar que llega email al requester.
   - Agregar tag y follower.

4. **Response coordinado:**
   - Desde la vista de ticket, escribir un comentario público + cambiar estado + adjuntar archivo.
   - Verificar que se envía un único email de "response" combinado.

- [ ] **Step 6.10: Commit**

```bash
git add src/Service/TicketService.php src/Service/TicketIngestionService.php src/Service/TicketNotificationService.php src/Service/Traits/HtmlSanitizerTrait.php
git add src/Service/GmailImportService.php
# Añadir TicketServiceInitializerTrait solo si se modificó en Step 6.6:
# git add src/Controller/Trait/TicketServiceInitializerTrait.php
git commit -m "refactor(ticket-service): extract Ingestion and Notification services — close audit 4.1

TicketService de 1046 LOC se trocea en 3 clases:

- TicketIngestionService (nueva): entrada desde email/WhatsApp.
  Mueve createFromEmail, createCommentFromEmail, findOrCreateUser,
  isEmailInTicketRecipients, processEmailAttachments. Consume
  TicketNotificationService para creation notifications.

- TicketNotificationService (nueva): despacho de notificaciones.
  Mueve dispatchCreationNotifications, dispatchUpdateNotifications,
  sendResponseNotifications. Wraps EmailService + WhatsappService.

- TicketService (residual, ~600 LOC): pipeline operations.
  Conserva changeStatus, assign, changePriority, addComment, addTag,
  removeTag, addFollower, handleResponse, saveUploadedFile, helpers
  privados (logHistory, buildResponseResult, decodeEmailRecipients).

HtmlSanitizerTrait extraido a src/Service/Traits/ para evitar
duplicacion entre TicketService y TicketIngestionService.

GmailImportService actualizado para consumir TicketIngestionService.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: 4.3 — Inyección de dependencias en servicios refactorizados

**Contexto:** Tras Task 6, los 3 servicios (`TicketService`, `TicketIngestionService`, `TicketNotificationService`) ya aceptan dependencias inyectables como parámetros opcionales del constructor. Step revisión: confirmar que todos los servicios consumidores de `EmailService`/`WhatsappService` permiten inyección, y que ningún consumidor del flujo principal pierda funcionalidad.

**Files:**
- Verificación final + posibles ajustes en `TicketIngestionService` y `TicketNotificationService`

- [ ] **Step 7.1: Verificar firmas de constructor**

Confirmar que los tres servicios tienen firmas con parámetros inyectables:

```bash
grep -n "public function __construct" src/Service/TicketService.php src/Service/TicketIngestionService.php src/Service/TicketNotificationService.php
```

Esperado:
- `TicketService::__construct(?array $systemConfig = null, ?TicketNotificationService $notificationService = null)`
- `TicketIngestionService::__construct(?array $systemConfig = null, ?TicketNotificationService $notificationService = null)`
- `TicketNotificationService::__construct(?array $systemConfig = null, ?EmailService $emailService = null, ?WhatsappService $whatsappService = null)`

- [ ] **Step 7.2: Permitir inyección de EmailService en TicketIngestionService (vía notificationService)**

`TicketIngestionService` consume `EmailService` indirectamente vía `TicketNotificationService`. Como `TicketNotificationService` ya acepta `EmailService` inyectado, no se necesita cambio adicional. Verificar que `TicketIngestionService` NO instancia `EmailService` ni `WhatsappService` directamente:

```bash
grep -n "new EmailService\|new WhatsappService" src/Service/TicketIngestionService.php
```
Esperado: cero resultados.

- [ ] **Step 7.3: Documentar patrón en CLAUDE.md**

Editar `CLAUDE.md`. En la sección "Cross-cutting conventions", añadir un nuevo bullet (al lado del bullet de "Notifications"):

```markdown
- **Service composition (DI):** los servicios `TicketService`, `TicketIngestionService` y `TicketNotificationService` aceptan sus colaboradores (`EmailService`, `WhatsappService`, `TicketNotificationService`) como parámetros opcionales del constructor. El default es instanciar internamente con `new` para preservar el estilo "fat-service simple". Sustituye el default solo cuando necesites swap (futuros tests, scripts CLI con configs específicas).
```

- [ ] **Step 7.4: cs-check**

```bash
composer cs-check
```

- [ ] **Step 7.5: Smoke manual final**

Repetir el smoke manual de Task 6 (Step 6.9) para validar que la refactorización completa de los 8 commits no introdujo regresiones:

1. `bin/cake import_gmail --max 5` (ingestion + notificación de creación).
2. Cambiar estado, asignar, cambiar prioridad, comentar desde UI.
3. Response coordinado (comentario + estado + archivo).
4. Verificar sidebar counts (Task 3).
5. Verificar badges (Task 4).
6. Verificar OAuth callback (Task 1).
7. Verificar que cambiar un setting se refleja sin reinicio (Task 2).

- [ ] **Step 7.6: Commit**

```bash
git add CLAUDE.md
# Si Step 7.2 requirió ajustes:
# git add src/Service/TicketIngestionService.php
git commit -m "refactor(ticket-services): document optional DI pattern — close audit 4.3

Los servicios refactorizados en Task 6 (TicketService,
TicketIngestionService, TicketNotificationService) ya aceptan sus
colaboradores como parametros opcionales del constructor (estilo
'?Service \$svc = null'). Esto permite swap sin imponer un container
ni interfaces innecesarias.

CLAUDE.md actualizado documentando el patron en la seccion de
cross-cutting conventions, junto al bullet de Notifications.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Cierre del audit y actualización del CLAUDE.md de fase 2

**Files:**
- Modify: `docs/audits/2026-05-07-architecture-audit.md` (anexar cierre de altos)
- Modify: `CLAUDE.md` (asegurar que refleja el estado final post-fase-2)

- [ ] **Step 8.1: Anexar al audit el cierre de los altos**

Editar `docs/audits/2026-05-07-architecture-audit.md`. Al final del documento añadir:

```markdown
### Anexo 3 — Cierre de altos (fase 2, 2026-05-08)

Cerrados en plan `docs/superpowers/plans/2026-05-08-audit-fase2-altos.md`:

- **4.1 ✅** `TicketService` (1046 LOC) troceado en `TicketIngestionService`, `TicketNotificationService` y `TicketService` residual (≤600 LOC). `HtmlSanitizerTrait` extraído.
- **4.2 ✅** `EmailTemplateRenderer` y `Renderer/NotificationRenderer` clarificados como capas separadas (template loader + domain formatter) vía docblocks con cross-references. No se solapaban en realidad.
- **4.3 ✅** Los tres servicios pipelining/ingestion/notification aceptan colaboradores inyectables como parámetros opcionales del constructor. Patrón documentado en `CLAUDE.md`.
- **4.4 ✅** `StatusHelper::statusBadge`/`priorityBadge` ya no concatenan HTML inline; delegan a `templates/element/tickets/{status,priority}_badge.php` con clases CSS en `webroot/css/badges.css`. `TicketHelper` eliminado (era wrapper trivial).
- **4.5 ✅** Query inline de `TicketsSidebarCell` movida a `SidebarCountsService::getAgentStatusCounts()`.
- **4.7 ✅** Closure `specialRedirects` en `TicketListingTrait` eliminado. Ruta dedicada `/oauth/gmail/callback` añadida como fallback para configs OAuth legacy.
- **4.8 ✅** Auditado: todos los call-sites que persisten en `system_settings` pasan por `SettingsService::saveSetting()` que invalida 4 keys. Deuda residual documentada (cache config en `_cake_core_` en vez de un cache dedicado).

**Pendiente próxima fase:** medios 5.1–5.7.
```

- [ ] **Step 8.2: Verificar CLAUDE.md refleja estado final**

```bash
grep -n "TicketIngestionService\|TicketNotificationService\|HtmlSanitizerTrait\|TicketHelper" CLAUDE.md
```

Esperado:
- `TicketIngestionService` y `TicketNotificationService` mencionados en la sección de Service.
- `HtmlSanitizerTrait` mencionado entre los traits de `src/Service/Traits/`.
- `TicketHelper` SIN aparecer (eliminado).

Si falta alguno, editar `CLAUDE.md` en la sección `### Layered structure` → bullet de `src/Service/`:

```markdown
- **`src/Service/`** — Business logic. Domain services: `TicketService` (pipeline: status/assign/priority/comment/tag/follower/handleResponse), `TicketIngestionService` (entrada desde Gmail/WhatsApp), `TicketNotificationService` (dispatch de notificaciones email + WhatsApp). Integraciones: `GmailService`, `EmailService`, `WhatsappService`, `N8nService`. Cross-cutting helpers: `SidebarCountsService`, `NumberGenerationService`, `EmailTemplateRenderer` (template loader), `Renderer/NotificationRenderer` (domain formatter), `SettingsService`, `AuthorizationService`, `ProfileImageService`. Reusable mixin logic en `src/Service/Traits/`: `ConfigResolutionTrait`, `GenericAttachmentTrait`, `SecureHttpTrait`, `SettingsEncryptionTrait`, `HtmlSanitizerTrait`. Attachments en `webroot/uploads/`.
```

- [ ] **Step 8.3: Verificar Definition of Done**

Ejecutar checklist final:

```bash
# 1. Ningun TicketHelper en disco
test ! -f src/View/Helper/TicketHelper.php && echo "OK: TicketHelper deleted"

# 2. Cero referencias a TicketHelper
grep -rn "TicketHelper\|->Ticket->getViewUrl" src/ templates/ && echo "FAIL" || echo "OK: no TicketHelper refs"

# 3. TicketService ≤ 600 LOC
echo "TicketService LOC: $(wc -l < src/Service/TicketService.php)"

# 4. Servicios nuevos existen
test -f src/Service/TicketIngestionService.php && echo "OK: TicketIngestionService exists"
test -f src/Service/TicketNotificationService.php && echo "OK: TicketNotificationService exists"

# 5. Cero queries inline en Cells
grep -rn "fetchTable.*Tickets\|TableLocator" src/View/Cell/ && echo "FAIL" || echo "OK: no inline queries"

# 6. cs-check pasa
composer cs-check
```

- [ ] **Step 8.4: Commit final**

```bash
git add docs/audits/2026-05-07-architecture-audit.md CLAUDE.md
git commit -m "docs(audit): close fase 2 — altos 4.1-4.8 (excl 4.6)

Anexo 3 al audit del 2026-05-07 con evidencia de cierre de los 7
altos restantes (4.1, 4.2, 4.3, 4.4, 4.5, 4.7, 4.8). 4.6 ya estaba
cerrado en fase previa.

CLAUDE.md actualizado para reflejar nuevos servicios
(TicketIngestionService, TicketNotificationService), nuevo trait
(HtmlSanitizerTrait), eliminacion de TicketHelper, y patron de DI
en servicios.

Pendiente proxima fase: medios 5.1-5.7.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- 0: Reconciliar audit doc → Task 0 ✓
- 1: 4.7 OAuth → Task 1 ✓
- 2: 4.8 cache → Task 2 ✓
- 3: 4.5 sidebar cell → Task 3 ✓
- 4: 4.4 helpers → Task 4 ✓
- 5: 4.2 renderers → Task 5 ✓
- 6: 4.1 trocear TicketService → Task 6 ✓
- 7: 4.3 DI → Task 7 ✓
- Cierre final del audit + CLAUDE.md → Task 8 ✓ (añadido al plan, no estaba en el spec original — necesario para closure efectivo)

**Placeholder scan:** sin TBDs, sin "implement later", todo el código mostrado.

**Type consistency:**
- `TicketNotificationService` definido en Step 6.2 con métodos `dispatchCreationNotifications`, `dispatchUpdateNotifications`, `sendResponseNotifications`. Usado en Step 6.3 (`TicketIngestionService`) y Step 6.4 (`TicketService`) con esas mismas firmas.
- `TicketIngestionService` definido en Step 6.3 con `createFromEmail`, `createCommentFromEmail`. Consumido en Step 6.5 (`GmailImportService`) con esas firmas.
- `HtmlSanitizerTrait::sanitizeHtml` definido en Step 6.1, consumido en Step 6.3 y 6.4.
- `SidebarCountsService::getAgentStatusCounts(int $userId): array` definido en Step 3.1, consumido en Step 3.2.
- `TicketConstants::OPEN_STATUSES` referenciado en Step 3.1 (existe en `src/Constants/TicketConstants.php:28-32`).

**Riesgos remanentes:**
- Step 6.5 dice "buscar consumidores" pero no enumera todos. Justificado: no podemos conocerlos todos antes de ejecutar; el `grep` los descubre. El operador debe migrar cada uno siguiendo el patrón mostrado.
- Step 4.3 asume que el layout es `templates/layout/default.php`. Si el proyecto usa otro path, el operador debe ajustarlo (paso `ls templates/layout/` está incluido).
- Step 5.3 es opcional y solo aplica si Step 5.1 lo justifica — explícito en el plan.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-08-audit-fase2-altos.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - Dispatch un subagent fresco por task, review entre tasks, iteración rápida. Ideal para Task 6 que es el commit más arriesgado: el subagent puede dividir en 6a/6b si crece.

**2. Inline Execution** - Ejecutar tasks en esta sesión usando `executing-plans`, batch con checkpoints de revisión.

¿Cuál enfoque?
