# Reducción de roles + organización base — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Colapsar los 4 roles actuales (`admin`, `agent`, `servicio_cliente`, `requester`) a 2 roles funcionales (`admin`, `asesor_tic`) más un marcador no-funcional (`external`), eliminar layouts de roles deprecados, y backfillear `users.organization_id` contra una organización base.

**Architecture:** Refactor de strings/constantes + 1 migración de datos. La columna `users.role` sigue siendo VARCHAR libre. Selección de layout pasa a basarse en el prefijo de ruta (`/admin` → layout `admin`, resto → `default`) en lugar del rol. Sin cambios estructurales de tablas; solo `INSERT` en `organizations`, `UPDATE` en `users`, y cambio de DEFAULT de `users.role`.

**Tech Stack:** CakePHP 5.x, PHP 8.5+, MySQL/MariaDB, PHPUnit, phinx (CakePHP Migrations).

**Spec:** `docs/superpowers/specs/2026-05-10-roles-to-organizations-design.md`.

---

## Pre-requisitos

- Repo limpio en `main`.
- `composer install` ejecutado.
- Comandos clave (CLAUDE.md):
  - `composer test` — corre suite completa
  - `composer cs-fix && composer cs-check`
  - `vendor/bin/phpstan analyse src`
  - `bin/cake bake migration <Name>` — genera archivo de migración con timestamp

---

## Task 1 — Crear migración `NormalizeRolesAndBaseOrganization`

**Files:**
- Create: `config/Migrations/<timestamp>_NormalizeRolesAndBaseOrganization.php` (timestamp lo fija `bin/cake bake`)

- [ ] **Step 1: Generar el esqueleto de migración**

```bash
bin/cake bake migration NormalizeRolesAndBaseOrganization
```

Esto crea `config/Migrations/<timestamp>_NormalizeRolesAndBaseOrganization.php` con un método `change()` vacío.

- [ ] **Step 2: Reemplazar el contenido del archivo generado**

Sustituir todo el archivo por:

```php
<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Normalize role values to {admin, asesor_tic, external} and ensure all staff
 * users belong to a base organization.
 *
 * Migration mappings:
 *   - 'agent'             -> 'asesor_tic'
 *   - 'servicio_cliente'  -> 'asesor_tic'  (collapsed; no real permission diff)
 *   - 'requester'         -> 'external'    (non-functional marker; no login)
 *
 * NOTE: This migration intentionally uses raw SQL UPDATEs which do NOT trigger
 * AuditBehavior. We do not want users_history to be flooded with N rows for a
 * structural rename. The down() method does not reconstruct 'servicio_cliente'
 * (data loss accepted and documented).
 */
final class NormalizeRolesAndBaseOrganization extends AbstractMigration
{
    public bool $autoId = false;

    public function up(): void
    {
        // 1. Ensure base organization exists. organizations.name has no unique
        //    index so we use check-then-insert. Migrations run once per env so
        //    no race condition concern.
        $existing = $this->fetchRow(
            "SELECT id FROM organizations WHERE name = 'Organización Base' LIMIT 1"
        );
        if ($existing === false || $existing === null) {
            $this->execute(
                "INSERT INTO organizations (name, domain, created, modified) "
                . "VALUES ('Organización Base', NULL, NOW(), NOW())"
            );
            $row = $this->fetchRow(
                "SELECT id FROM organizations WHERE name = 'Organización Base' LIMIT 1"
            );
            $baseId = (int)$row['id'];
        } else {
            $baseId = (int)$existing['id'];
        }

        // 2. Collapse agent + servicio_cliente -> asesor_tic.
        $this->execute(
            "UPDATE users SET role = 'asesor_tic' "
            . "WHERE role IN ('agent', 'servicio_cliente')"
        );

        // 3. Rename requester -> external (non-functional marker).
        $this->execute("UPDATE users SET role = 'external' WHERE role = 'requester'");

        // 4. Backfill organization_id for staff users only.
        $this->execute(
            "UPDATE users SET organization_id = {$baseId} "
            . "WHERE organization_id IS NULL AND role IN ('admin', 'asesor_tic')"
        );

        // 5. Change the column default so newly auto-created users (Gmail import)
        //    that don't specify a role land as 'external'.
        $this->execute(
            "ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'external'"
        );
    }

    public function down(): void
    {
        // Restore default first.
        $this->execute(
            "ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'requester'"
        );

        // Best-effort revert. 'servicio_cliente' is NOT reconstructed.
        $this->execute("UPDATE users SET role = 'agent' WHERE role = 'asesor_tic'");
        $this->execute("UPDATE users SET role = 'requester' WHERE role = 'external'");

        // organization_id is left as-is on rollback (was nullable from start;
        // unsetting it would lose information added intentionally).
    }
}
```

- [ ] **Step 3: Aplicar la migración en local**

```bash
bin/cake migrations migrate
```

Expected: muestra `NormalizeRolesAndBaseOrganization migrated`. Si falla, revisar errores y arreglar el archivo (no commitear migraciones rotas).

- [ ] **Step 4: Verificar el efecto en BD**

```bash
bin/cake migrations status
```

Expected: la nueva migración aparece como `up`.

Spot-check vía consola MySQL (opcional):
```sql
SELECT role, COUNT(*) FROM users GROUP BY role;
SELECT id, name FROM organizations WHERE name='Organización Base';
SHOW COLUMNS FROM users LIKE 'role';   -- Default: external
```

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/
git commit -m "feat(db): normalize role values and ensure base organization

- Collapse agent/servicio_cliente into asesor_tic
- Rename requester to external (non-functional marker)
- Backfill users.organization_id for staff against base organization
- Change users.role default from 'requester' to 'external'"
```

---

## Task 2 — Actualizar `RoleConstants`

**Files:**
- Modify: `src/Constants/RoleConstants.php`

- [ ] **Step 1: Reemplazar el contenido completo del archivo**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * User role constants.
 */
final class RoleConstants
{
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_ASESOR_TIC  = 'asesor_tic';
    public const ROLE_EXTERNAL    = 'external';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_ASESOR_TIC,
        self::ROLE_EXTERNAL,
    ];

    public const STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_ASESOR_TIC,
    ];
}
```

- [ ] **Step 2: Confirmar que falla la compilación estática (consumidores aún usan constantes viejas)**

```bash
vendor/bin/phpstan analyse src --no-progress 2>&1 | head -40
```

Expected: errores de tipo "Constant `App\Constants\RoleConstants::ROLE_AGENT` not found." en varios archivos. Esto es esperado y se arregla en las siguientes tasks.

- [ ] **Step 3: NO commitear todavía** — el árbol queda roto hasta completar Task 8. Continuar con Task 3.

---

## Task 3 — Eliminar layout-por-rol en `AppController`

**Files:**
- Modify: `src/Controller/AppController.php` (líneas 101–115 y 123–132)

- [ ] **Step 1: Quitar el bloque `setLayout` por rol**

Reemplazar el bloque entre las líneas 101–115:

```php
        // Set layout based on user role
        $user = $identity?->getOriginalData();
        if ($user instanceof User) {
            $role = $user->role;
            if ($role === RoleConstants::ROLE_ADMIN) {
                $this->viewBuilder()->setLayout('admin');
            } elseif ($role === RoleConstants::ROLE_AGENT) {
                $this->viewBuilder()->setLayout('agent');
            } elseif ($role === RoleConstants::ROLE_SERVICIO_CLIENTE) {
                $this->viewBuilder()->setLayout('servicio_cliente');
            } else {
                $this->viewBuilder()->setLayout('requester');
            }
        }
```

por: (eliminar completo — `default.php` se aplica automáticamente; el layout `admin` se asignará en `Admin/AppController` en Task 4).

- [ ] **Step 2: Simplificar `getDefaultRedirectForRole`**

Reemplazar el método completo (~líneas 117–132):

```php
    /**
     * Get the default redirect target for a given role.
     *
     * @param string $role User role
     * @return array CakePHP-style URL array
     */
    protected function getDefaultRedirectForRole(string $role): array
    {
        // All staff lands on the unfiltered tickets index.
        return ['controller' => 'Tickets', 'action' => 'index'];
    }
```

- [ ] **Step 3: Quitar imports no usados si quedan**

Si tras los cambios `App\Model\Entity\User` deja de usarse en este archivo, eliminar la línea `use App\Model\Entity\User;`. Verificar también que `App\Constants\RoleConstants` siga usándose en `redirectByRole()` (sí — `$role = $user->role`, no se compara con constante específica). Si no se usa, quitarlo.

```bash
grep -n "RoleConstants\|User" src/Controller/AppController.php
```

Expected: solo deben quedar referencias necesarias (`$user instanceof User` se eliminó; `RoleConstants` ya no se referencia en este archivo). Eliminar ambos `use` si ninguno se referencia.

- [ ] **Step 4: NO commitear todavía.** Continuar con Task 4.

---

## Task 4 — Crear `Admin/AppController` con layout admin

**Files:**
- Create: `src/Controller/Admin/AppController.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController as BaseAppController;
use Cake\Event\EventInterface;

/**
 * Base controller for the /admin prefix.
 *
 * Centralizes the admin layout assignment that was previously branched by
 * role in the global AppController.
 */
class AppController extends BaseAppController
{
    /**
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event.
     * @return void
     */
    public function beforeRender(EventInterface $event): void
    {
        parent::beforeRender($event);
        $this->viewBuilder()->setLayout('admin');
    }
}
```

- [ ] **Step 2: Cambiar la herencia de los controllers admin existentes**

En cada uno de:
- `src/Controller/Admin/SettingsController.php`
- `src/Controller/Admin/EmailTemplatesController.php`
- `src/Controller/Admin/TagsController.php`

**Eliminar** la línea:
```php
use App\Controller\AppController;
```

No hace falta añadir un `use` nuevo: como estos archivos viven en `namespace App\Controller\Admin;`, PHP resuelve automáticamente `extends AppController` a `App\Controller\Admin\AppController` (mismo namespace). La línea `class XController extends AppController` no se modifica.

- [ ] **Step 3: Verificar**

```bash
grep -n "extends AppController" src/Controller/Admin/*.php
grep -n "use App.*AppController" src/Controller/Admin/*.php
```

Expected: cada controller admin sigue diciendo `extends AppController`. El segundo grep no debería devolver nada (excepto en `Admin/AppController.php` mismo, que sí importa el base como `BaseAppController`).

- [ ] **Step 4: Caso especial — `EmailTemplatesController.php` línea 96**

Esa línea hace `$this->viewBuilder()->setLayout(null);` en alguna acción. Verificar que esa acción siga sirviendo respuestas sin layout (probablemente JSON o descarga). Como `Admin/AppController::beforeRender` aplica `'admin'`, el `null` posterior dentro del action ejecuta DESPUÉS y gana. No hay conflicto.

```bash
sed -n '90,100p' src/Controller/Admin/EmailTemplatesController.php
```

Expected: confirma que el `setLayout(null)` está dentro de un método de acción (no en `beforeRender`). Si está en `beforeRender`, mover a un `setLayout` específico de la acción.

- [ ] **Step 5: NO commitear todavía.** Continuar con Task 5.

---

## Task 5 — Ajustar `TicketsController::beforeFilter`

**Files:**
- Modify: `src/Controller/TicketsController.php:55`

- [ ] **Step 1: Reemplazar la línea de roles permitidos**

Cambiar:
```php
        return $this->redirectByRole([RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT, RoleConstants::ROLE_REQUESTER], 'tickets');
```

por:
```php
        return $this->redirectByRole(RoleConstants::STAFF_ROLES, 'tickets');
```

- [ ] **Step 2: NO commitear todavía.** Continuar con Task 6.

---

## Task 6 — Actualizar traits del módulo de tickets

**Files:**
- Modify: `src/Controller/Trait/TicketViewTrait.php`
- Modify: `src/Controller/Trait/TicketHistoryTrait.php`
- Modify: `src/Controller/Trait/TicketListingTrait.php`

### 6a. `TicketViewTrait.php`

- [ ] **Step 1: Eliminar la rama `ROLE_REQUESTER` en `_checkTicketViewPermission`**

Reemplazar el método (líneas 41–62):

```php
    /**
     * @param \App\Model\Entity\Ticket $ticket Ticket entity
     * @return \Cake\Http\Response|null Reservado para ramas de permisos futuras.
     */
    private function _checkTicketViewPermission(Ticket $ticket)
    {
        // Antes filtrábamos requester. Como 'external' nunca inicia sesión,
        // la rama es código muerto. Cualquier staff autenticado ve todo.
        return null;
    }
```

- [ ] **Step 2: Renombrar `ROLE_AGENT` en `getDefaultAgentsRoleFilter`**

Reemplazar (líneas 134–137):

```php
    /**
     * @return array
     */
    private function getDefaultAgentsRoleFilter(): array
    {
        return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_ASESOR_TIC];
    }
```

- [ ] **Step 3: Verificar que `_checkTicketViewPermission` aún sea referenciado**

```bash
grep -n "_checkTicketViewPermission" src/Controller/Trait/TicketViewTrait.php
```

Expected: 2 hits (definición + uso en `view()`). El método queda como punto de extensión futuro.

### 6b. `TicketHistoryTrait.php`

- [ ] **Step 4: Eliminar la rama `ROLE_REQUESTER` en `historyTicket`**

Quitar las líneas 48–56 (el bloque que chequea `$userRole === ROLE_REQUESTER`):

```php
            $userRole = $user->get('role');
            $userId = $user->get('id');
            if ($userRole === RoleConstants::ROLE_REQUESTER && $entity->requester_id !== $userId) {
                $this->set('error', 'No tienes permiso para ver este historial');
                $this->viewBuilder()->setOption('serialize', ['error']);
                $this->response = $this->response->withStatus(403);

                return;
            }
```

Quedan las líneas anteriores (`$entity = $this->fetchTable($tableName)->get($id);`) y siguientes (`$historyTable = ...`). Después del cambio, `$userRole` y `$userId` ya no se usan — quitar también esas dos asignaciones.

- [ ] **Step 5: Quitar el import si ya no se usa**

```bash
grep -n "RoleConstants" src/Controller/Trait/TicketHistoryTrait.php
```

Si solo queda en el `use` del header, eliminar `use App\Constants\RoleConstants;`.

### 6c. `TicketListingTrait.php`

- [ ] **Step 6: Eliminar la rama requester en `applyRoleBasedFilters`**

Reemplazar el método (líneas 121–129):

```php
    private function applyRoleBasedFilters($query, $user, ?string $userRole, string $tableAlias): void
    {
        // Antes filtrábamos por requester_id cuando el rol era 'requester'.
        // 'external' no inicia sesión, así que esa rama es código muerto.
        // El método se conserva como punto de extensión para filtros por rol
        // futuros (p.ej. organización).
    }
```

- [ ] **Step 7: Renombrar en `getDefaultUsersRoleFilter`**

```php
    private function getDefaultUsersRoleFilter(): array
    {
        return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_ASESOR_TIC];
    }
```

- [ ] **Step 8: NO commitear todavía.** Continuar con Task 7.

---

## Task 7 — Renombrar `ROLE_AGENT` en servicios y vistas

**Files:**
- Modify: `src/Service/SidebarCountsService.php:43`
- Modify: `src/Model/Table/TicketsTable.php:218`
- Modify: `src/View/Cell/TicketsSidebarCell.php:35`

- [ ] **Step 1: `SidebarCountsService.php` línea 43**

Cambiar `RoleConstants::ROLE_AGENT` → `RoleConstants::ROLE_ASESOR_TIC`.

- [ ] **Step 2: `TicketsTable.php` línea 218**

Cambiar `$isAgent = $userRole === RoleConstants::ROLE_AGENT;` →
`$isAgent = $userRole === RoleConstants::ROLE_ASESOR_TIC;`

(Mantenemos el nombre de variable `$isAgent` por ahora — semánticamente es "staff que ve solo lo suyo, no admin". Renombrarlo es un refactor opcional fuera de alcance.)

- [ ] **Step 3: `TicketsSidebarCell.php` línea 35**

Cambiar `RoleConstants::ROLE_AGENT` → `RoleConstants::ROLE_ASESOR_TIC`.

- [ ] **Step 4: Verificar que ya no quedan referencias a constantes eliminadas**

```bash
grep -rn "ROLE_AGENT\|ROLE_SERVICIO_CLIENTE\|ROLE_REQUESTER" src/ tests/
```

Expected: sin resultados. Si aparece algo, arreglar antes de seguir.

- [ ] **Step 5: NO commitear todavía.** Continuar con Task 8.

---

## Task 8 — Actualizar `AuthorizationService`

**Files:**
- Modify: `src/Service/AuthorizationService.php:35`

- [ ] **Step 1: Reemplazar la lista de roles permitidos**

Cambiar línea 35:
```php
        return !in_array($userRole, [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT], true);
```

por:
```php
        return !in_array($userRole, RoleConstants::STAFF_ROLES, true);
```

- [ ] **Step 2: Validación estática**

```bash
vendor/bin/phpstan analyse src --no-progress 2>&1 | tail -20
```

Expected: en este punto los errores derivados de constantes eliminadas deberían haber desaparecido. Si quedan errores nuevos, arreglar.

- [ ] **Step 3: Commit del bloque de constantes + consumidores**

```bash
git add src/Constants/RoleConstants.php src/Controller/AppController.php \
        src/Controller/Admin/AppController.php src/Controller/Admin/*.php \
        src/Controller/TicketsController.php \
        src/Controller/Trait/TicketViewTrait.php \
        src/Controller/Trait/TicketHistoryTrait.php \
        src/Controller/Trait/TicketListingTrait.php \
        src/Service/AuthorizationService.php \
        src/Service/SidebarCountsService.php \
        src/Model/Table/TicketsTable.php \
        src/View/Cell/TicketsSidebarCell.php
git commit -m "refactor(roles): collapse to admin/asesor_tic, route layout by URL prefix

- Replace ROLE_AGENT/ROLE_SERVICIO_CLIENTE/ROLE_REQUESTER with
  ROLE_ASESOR_TIC and ROLE_EXTERNAL
- Move admin layout assignment to a new Admin/AppController; the global
  AppController stops branching layout by role
- Drop dead requester-only filtering branches in ticket traits"
```

---

## Task 9 — Ajustar `TicketIngestionService`

**Files:**
- Modify: `src/Service/TicketIngestionService.php:289-297`

- [ ] **Step 1: Importar la constante si falta**

Verificar que el archivo tenga `use App\Constants\RoleConstants;`. Si no, añadirlo en el bloque de imports.

```bash
grep -n "use App\\\\Constants\\\\RoleConstants" src/Service/TicketIngestionService.php
```

- [ ] **Step 2: Reemplazar el literal `'requester'`**

Cambiar el bloque (líneas 289–297):

```php
        // Create new user with role 'requester' and null password
        $user = $usersTable->newEntity([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => 'requester',
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
```

por:

```php
        // Auto-create as 'external': non-functional marker, never logs in.
        // Exists so tickets.requester_id can FK to a real users row.
        $user = $usersTable->newEntity([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => RoleConstants::ROLE_EXTERNAL,
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/TicketIngestionService.php
git commit -m "refactor(ingestion): auto-create Gmail senders as 'external' role"
```

---

## Task 10 — Reducir el select de rol en formularios admin

**Files:**
- Modify: `templates/Admin/Settings/add_user.php:80-87`
- Modify: `templates/Admin/Settings/edit_user.php:115-122`
- Modify: `templates/Admin/Settings/users.php:60-67`

- [ ] **Step 1: `add_user.php`**

Reemplazar el bloque del select de rol (líneas ~80–87):

```php
                        <?= $this->Form->select('role', [
                            'admin' => 'Administrador',
                            'agent' => 'Agente',
                            'servicio_cliente' => 'Servicio al Cliente',
                            'requester' => 'Solicitante'
                        ], [
                            'required' => true
                        ]) ?>
```

por:

```php
                        <?= $this->Form->select('role', [
                            'admin' => 'Administrador',
                            'asesor_tic' => 'Asesor TIC',
                        ], [
                            'required' => true
                        ]) ?>
```

- [ ] **Step 2: `edit_user.php`**

Reemplazar análogamente el select de rol (líneas ~115–122):

```php
                        <?= $this->Form->select('role', [
                            'admin' => 'Administrador',
                            'asesor_tic' => 'Asesor TIC',
                        ], [
                            'required' => true,
                        ]) ?>
```

(Conservar cualquier opción extra del bloque original que no sea el array de roles — p.ej. `'value' => $user->role` si existe.)

- [ ] **Step 3: `users.php`**

Reemplazar el array de roles en la columna que muestra el rol (líneas ~60–67):

```php
                                'admin' => 'Administrador',
                                'agent' => 'Agente',
                                'servicio_cliente' => 'Servicio al Cliente',
                                'requester' => 'Solicitante',
```

por:

```php
                                'admin' => 'Administrador',
                                'asesor_tic' => 'Asesor TIC',
                                'external' => 'Externo',
```

(En el listado sí incluimos `external` para que las filas históricas se vean con etiqueta legible. En los formularios add/edit NO, porque no se debe asignar manualmente.)

- [ ] **Step 4: Commit**

```bash
git add templates/Admin/Settings/
git commit -m "refactor(admin): reduce user role select to admin + asesor_tic"
```

---

## Task 11 — Borrar layouts y elemento huérfano

**Files:**
- Delete: `templates/layout/agent.php`
- Delete: `templates/layout/servicio_cliente.php`
- Delete: `templates/layout/requester.php`
- Delete: `templates/element/tickets/requester_stats.php`

- [ ] **Step 1: Confirmar que `requester_stats.php` no se incluye en ningún template**

```bash
grep -rln "tickets/requester_stats\|element('tickets/requester" templates/ src/
```

Expected: sin resultados (ya verificado en la fase de spec). Si aparece algún include, arreglarlo primero antes de borrar.

- [ ] **Step 2: Borrar archivos**

```bash
rm templates/layout/agent.php
rm templates/layout/servicio_cliente.php
rm templates/layout/requester.php
rm templates/element/tickets/requester_stats.php
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore(layout): remove deprecated agent/servicio_cliente/requester layouts

The role-based layout selection in AppController has been replaced by URL
prefix routing (/admin -> admin layout, otherwise default). The element
templates/element/tickets/requester_stats.php was orphaned and is removed."
```

---

## Task 12 — Actualizar tests con valores de rol viejos

**Files:**
- Modify: `tests/TestCase/Model/Entity/TicketTest.php` (líneas 47, 147, 154, 161, 168)

- [ ] **Step 1: Reemplazar `'agent'` → `'asesor_tic'` y `'requester'` → `'external'`**

Hacer 5 ediciones específicas:

- Línea 47: `'role' => 'agent',` → `'role' => 'asesor_tic',`
- Línea 147: `['role' => 'agent', 'is_active' => true]` → `['role' => 'asesor_tic', 'is_active' => true]`
- Línea 154: `['role' => 'agent', 'is_active' => false]` → `['role' => 'asesor_tic', 'is_active' => false]`
- Línea 161: `['role' => 'requester', 'is_active' => true]` → `['role' => 'external', 'is_active' => true]`
- Línea 168: `['role' => 'agent', 'is_active' => true]` → `['role' => 'asesor_tic', 'is_active' => true]`

(Los números de línea pueden haberse desplazado por commits previos en este plan — buscar por contenido si no calzan.)

- [ ] **Step 2: Verificar que no queden literales de rol viejos en tests**

```bash
grep -rn "'agent'\|'servicio_cliente'\|'requester'" tests/ \
    | grep -v "'requester_id'\|'requesters'"
```

Expected: sin resultados. (Las referencias a `requester_id` y `requesters` son nombres de columnas/asociaciones — no se tocan.)

- [ ] **Step 3: Correr la suite completa**

```bash
composer test
```

Expected: todos los tests pasan.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test: align ticket entity tests with new role values"
```

---

## Task 13 — Validación final + invalidación de sesiones

**Files:** ninguno (gates de calidad).

- [ ] **Step 1: Style fix + check**

```bash
composer cs-fix
composer cs-check
```

Expected: el segundo comando termina con `0 errors`.

- [ ] **Step 2: Static analysis**

```bash
vendor/bin/phpstan analyse src
```

Expected: sin errores nuevos respecto al baseline. Si aparecen, arreglarlos en commits enfocados.

- [ ] **Step 3: Tests**

```bash
composer test
```

Expected: green.

- [ ] **Step 4: Smoke test manual del servidor**

```bash
bin/cake server
```

En otro terminal o navegador:
- `GET /` con sesión admin → debería redirigir a tickets index sin error 500.
- `GET /admin` con sesión admin → debería usar el layout `admin`.
- Login con un usuario `asesor_tic` (modificar uno existente en BD si hace falta) → cae en `default.php`, ve tickets index.
- Login con un usuario `external` (no debería poder entrar — `STAFF_ROLES` lo bloquea en `redirectByRole`).

Si alguno falla, abrir el log de la app (`logs/error.log`) y arreglar antes de cerrar el plan.

- [ ] **Step 5: Documentar invalidación de sesiones para el deploy**

Este plan no toca el storage de sesiones. Tras desplegar a producción, **el operador debe invalidar las sesiones activas** para que los usuarios autenticados no queden con un rol viejo cacheado en su identidad. Añadir una nota al ticket/PR de despliegue:

> **Post-deploy step:** truncar la tabla de sesiones (o el handler equivalente — revisar `config/app_local.php` → `Session.handler`) inmediatamente después de aplicar la migración. Sin esto, usuarios con sesión abierta entran al sistema con `role` viejo (`agent` / `requester`) que ya no existe en el código y reciben redirects rotos hasta su siguiente login.

- [ ] **Step 6: Crear PR**

Usar el flujo estándar (`gh pr create`) apuntando a `main`. Cuerpo del PR:
- Resumen de cambios.
- Link al spec: `docs/superpowers/specs/2026-05-10-roles-to-organizations-design.md`.
- **Riesgo n8n:** revisar workflows de n8n que consuman el rol del usuario en payloads salientes (`N8nService::buildPayload` incluye datos de usuario). Si algún consumer hace `if role == 'agent'`, romperá. Coordinar con el operador de n8n antes del merge.
- **Post-deploy:** invalidar sesiones (paso 5).

---

## Resumen de archivos tocados

**Created:**
- `config/Migrations/<timestamp>_NormalizeRolesAndBaseOrganization.php`
- `src/Controller/Admin/AppController.php`

**Modified:**
- `src/Constants/RoleConstants.php`
- `src/Controller/AppController.php`
- `src/Controller/Admin/SettingsController.php`
- `src/Controller/Admin/EmailTemplatesController.php`
- `src/Controller/Admin/TagsController.php`
- `src/Controller/TicketsController.php`
- `src/Controller/Trait/TicketViewTrait.php`
- `src/Controller/Trait/TicketHistoryTrait.php`
- `src/Controller/Trait/TicketListingTrait.php`
- `src/Service/AuthorizationService.php`
- `src/Service/SidebarCountsService.php`
- `src/Service/TicketIngestionService.php`
- `src/Model/Table/TicketsTable.php`
- `src/View/Cell/TicketsSidebarCell.php`
- `templates/Admin/Settings/add_user.php`
- `templates/Admin/Settings/edit_user.php`
- `templates/Admin/Settings/users.php`
- `tests/TestCase/Model/Entity/TicketTest.php`

**Deleted:**
- `templates/layout/agent.php`
- `templates/layout/servicio_cliente.php`
- `templates/layout/requester.php`
- `templates/element/tickets/requester_stats.php`
