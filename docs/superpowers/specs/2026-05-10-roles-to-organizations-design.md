# Reducción de roles y preparación para modelo por organizaciones

**Fecha:** 2026-05-10
**Estado:** Diseño aprobado, pendiente plan de implementación

## Contexto

El sistema actual tiene cuatro roles (`admin`, `agent`, `servicio_cliente`, `requester`) con cuatro layouts dedicados (`admin.php`, `agent.php`, `servicio_cliente.php`, `requester.php`) y lógica de selección de layout en `AppController::beforeRender()` en función del rol.

`servicio_cliente` y `agent` no tienen distinción real de permisos en el código. `requester` solo aplica a usuarios autocreados por `TicketIngestionService` cuando llega un correo de Gmail; nunca son una cuenta humana que inicie sesión.

Existe ya la tabla `organizations` (id, name, domain) y la columna `users.organization_id` (nullable) sin uso funcional.

El objetivo es:
1. Reducir la matriz de roles funcionales a dos: **Administrador** y **Asesor TIC**.
2. Eliminar los layouts de los roles deprecados.
3. Asegurar que todos los usuarios staff queden vinculados a una **organización base**, dejando preparada la columna `organization_id` para cuando aparezca el modelo multi-tenant real (cada organización con su propia tabla de tickets).

Este diseño **no** implementa filtrado por organización ni separación de datos por tenant: eso es trabajo futuro.

## Decisiones de diseño

### Modelo de roles final

La columna `users.role` (VARCHAR libre) pasa a admitir tres valores:

| Valor | Uso | Visible en UI |
|---|---|---|
| `admin` | Administrador, acceso a `/admin` y `/` | Sí |
| `asesor_tic` | Staff del helpdesk, acceso a `/` (renombre de `agent`) | Sí |
| `external` | Marca no funcional para usuarios autocreados por Gmail import. No inicia sesión. Existe para que `tickets.requester_id` siga apuntando a una fila válida. | No (no aparece en formularios admin) |

`STAFF_ROLES = [admin, asesor_tic]`. Cualquier ramificación que antes distinguía `agent` vs `servicio_cliente` se colapsa en `asesor_tic` (no había diferencia real de comportamiento).

No se introduce tabla `roles` ni FK: sigue siendo un string controlado por `RoleConstants`.

### Layouts

Se conservan: `admin.php`, `default.php`, `ajax.php`, `error.php`, `email/`.
Se eliminan: `agent.php`, `servicio_cliente.php`, `requester.php`.

Selección de layout:
- Prefijo `/admin` → layout `admin` (asignado en `Admin/AppController::beforeRender`).
- Resto → layout `default` (heredado por defecto, sin asignación explícita).
- Se elimina por completo el bloque de selección de layout por rol en `src/Controller/AppController.php` (líneas 101–115).

### Organización base

Se garantiza la existencia de una organización base ("Organización Base") y se backfillea `users.organization_id` para todos los staff (`admin`, `asesor_tic`). Los usuarios `external` quedan con `organization_id = NULL` (no son miembros de ninguna org; son contactos externos).

El código **no consume** `organization_id` para filtrar todavía. Queda como atributo persistido y listo.

## Cambios de código

### `src/Constants/RoleConstants.php`
Reescribir constantes:
```php
public const ROLE_ADMIN       = 'admin';
public const ROLE_ASESOR_TIC  = 'asesor_tic';
public const ROLE_EXTERNAL    = 'external';

public const ROLES = [self::ROLE_ADMIN, self::ROLE_ASESOR_TIC, self::ROLE_EXTERNAL];
public const STAFF_ROLES = [self::ROLE_ADMIN, self::ROLE_ASESOR_TIC];
```
Eliminar: `ROLE_AGENT`, `ROLE_SERVICIO_CLIENTE`, `ROLE_REQUESTER`.

### `src/Controller/AppController.php`
- Borrar el bloque `setLayout` por rol (~líneas 101–115).
- `getDefaultRedirectForRole()` / `redirectByRole()`: simplificar — admin y asesor_tic redirigen a `Tickets::index` (sin `?view=mis_tickets`); external no debe llegar a este flujo.

### `src/Controller/Admin/AppController.php`
Asegurar `setLayout('admin')` en `beforeRender` para todo el prefijo `/admin`.

### `src/Controller/TicketsController.php` y traits
- `TicketsController::beforeFilter`: `redirectByRole([ROLE_ADMIN, ROLE_ASESOR_TIC], 'tickets')`.
- `TicketViewTrait::view`, `TicketHistoryTrait`, `TicketListingTrait`: eliminar las ramas `if ($userRole === ROLE_REQUESTER) { ... }`. Dado que `external` ya no inicia sesión, esa rama es código muerto.

### `src/Service/AuthorizationService.php`
`isAssignmentDisabled` queda equivalente: devolver `true` si user es null o role no está en `STAFF_ROLES`.

### `src/Service/TicketIngestionService.php`
Línea 294: cambiar `'role' => 'requester'` → `'role' => RoleConstants::ROLE_EXTERNAL`. No asignar `organization_id` (queda NULL para externos).

### Otros consumidores de constantes
Auditar y actualizar referencias en:
- `src/Service/N8nService.php`
- `src/Service/SidebarCountsService.php`
- `src/Model/Table/TicketsTable.php`
- `src/Model/Entity/Ticket.php` (default array `'requester' => false` puede no tener relación con el rol — verificar)
- `src/Model/Entity/User.php`
- `src/View/Cell/TicketsSidebarCell.php`

Cada cambio se hace caso a caso; el comportamiento que distinguía `agent` vs `servicio_cliente` se colapsa en `asesor_tic`.

### Templates admin
`templates/Admin/Settings/{add_user,edit_user,users}.php`: el select de rol pasa a 2 opciones:
```php
'admin' => 'Administrador',
'asesor_tic' => 'Asesor TIC',
```

### Vistas y elementos asociados a layouts borrados
`templates/element/tickets/requester_stats.php` y similares: revisar usos antes de borrar. Si solo eran consumidos por el layout `requester` o por una rama de UI exclusiva del solicitante, eliminar.

### Borrado de archivos
- `templates/layout/agent.php`
- `templates/layout/servicio_cliente.php`
- `templates/layout/requester.php`
- Elementos huérfanos (verificar antes).

### Tests
`tests/TestCase/Model/Entity/TicketTest.php:161` y cualquier otro test con `'role' => 'agent' | 'servicio_cliente' | 'requester'`: reemplazar por `'asesor_tic'` o `'external'` según lo que el test verifique.

## Migración de BD

**Archivo:** `config/Migrations/<timestamp>_NormalizeRolesAndBaseOrganization.php`

```text
up():
  1. SELECT id FROM organizations WHERE name='Organización Base' LIMIT 1.
     Si no existe, INSERT INTO organizations (name, domain, created, modified)
     VALUES ('Organización Base', NULL, NOW(), NOW()) y capturar lastInsertId.
     (Patrón check-then-insert porque organizations.name NO tiene índice único;
      la migración corre una sola vez por entorno, así que no hay carrera real.)
  2. UPDATE users SET role = 'asesor_tic'
     WHERE role IN ('agent', 'servicio_cliente');
  3. UPDATE users SET role = 'external' WHERE role = 'requester';
  4. UPDATE users SET organization_id = <base_id>
     WHERE organization_id IS NULL AND role IN ('admin','asesor_tic');
  5. ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'external';

down():
  - Restaura default a 'requester'.
  - UPDATE users SET role='agent' WHERE role='asesor_tic'.
  - UPDATE users SET role='requester' WHERE role='external'.
  - NO reconstruye 'servicio_cliente' (pérdida asumida y documentada en el
    comentario de la migración).
```

**Notas:**
- La columna sigue siendo VARCHAR libre; no se introduce ENUM ni FK.
- La migración usa SQL crudo (`UPDATE`), por lo que **no dispara `AuditBehavior`** — intencional, para no inflar `users_history` con N filas por una operación estructural. Se documenta en el comentario de la migración.

## Orden de despliegue

Todo va en una sola PR:

1. Migración aplicada vía `bin/cake migrations migrate`.
2. Cambios de código + borrado de layouts + ajustes de tests en el mismo commit que la migración.
3. Antes del commit: `composer cs-fix && composer cs-check && composer test && vendor/bin/phpstan analyse src`.
4. **Invalidar sesiones activas** como paso de despliegue (truncar tabla `sessions` o equivalente). Sin esto, usuarios con sesión activa quedan con role viejo en su identidad y caen en redirects raros hasta su siguiente login.

## Riesgos

- **Sesiones activas**: ver paso 4 arriba.
- **N8n payloads salientes**: `N8nService` puede incluir el rol en webhooks. Si algún workflow consumidor hace `if role == 'agent'`, romperá. **Acción previa al merge:** revisar workflows n8n relevantes y comunicarlo al usuario.
- **Tests con datos hardcodeados**: el `grep` ya identificó los puntos. Riesgo bajo.
- **Elementos de vista huérfanos**: si se borra `requester_stats.php` y aún lo incluye otro template, error 500. Mitigar con `grep` antes de borrar.

## Fuera de alcance

- Filtrado de queries por `organization_id`.
- Tabla de tickets separada por organización.
- Refactor de `tickets.requester_id` para guardar email/nombre en lugar de FK.
- Tabla `roles` o sistema de permisos granulares.
- UI para crear/gestionar organizaciones desde `/admin`.
