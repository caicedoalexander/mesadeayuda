# Diseño: Eliminar Organizaciones del código

**Fecha:** 2026-04-30
**Autor:** Alexander + Claude
**Estado:** Aprobado, pendiente de implementación

## Contexto

El módulo de Organizaciones existe en código y BD pero no tiene funcionalidad real: no se usa para reglas de negocio, autorización, segmentación de datos ni reportes. Solo aparece como CRUD admin, campo opcional en usuarios, columna informativa en sidebar de tickets y un campo en el payload de n8n. Se decide eliminarlo del código pero conservar la estructura en BD por si se reactiva en el futuro.

## Alcance

**Eliminar del código (alcance opción A):** todo controlador, template, asociación de modelo, validador, regla, fixture y referencia funcional a organizaciones.

**Conservar en BD (alcance opción B):**
- Tabla `organizations` (con sus filas)
- Columnas `users.organization_id` y `tickets.organization_id` (nullable)
- Eliminar FKs e índices asociados a `organization_id` (manual)

## Cambios en BD

Verificación previa devolvió vacío en este entorno: **no hay FKs ni índices que eliminar**. Cero acción manual necesaria.

Para entornos donde sí existan, ejecutar:

```sql
-- Identificar nombres reales
SELECT CONSTRAINT_NAME, TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND COLUMN_NAME = 'organization_id'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

SELECT INDEX_NAME, TABLE_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLUMN_NAME = 'organization_id';

-- Luego DROP en orden: FK → INDEX
```

## Archivos a eliminar (11)

**Controlador y templates admin:**
- `src/Controller/Admin/OrganizationsController.php`
- `templates/Admin/Organizations/index.php`
- `templates/Admin/Organizations/add.php`
- `templates/Admin/Organizations/edit.php`

**Templates duplicados en Settings:**
- `templates/Admin/Settings/organizations.php`
- `templates/Admin/Settings/add_organization.php`
- `templates/Admin/Settings/edit_organization.php`

**Modelo:**
- `src/Model/Table/OrganizationsTable.php`
- `src/Model/Entity/Organization.php`

**Tests:**
- `tests/TestCase/Model/Table/OrganizationsTableTest.php`
- `tests/Fixture/OrganizationsFixture.php`

## Archivos a modificar (~20)

**Modelo:**
- `src/Model/Entity/User.php` — quitar `organization_id` y `organization` de `$_accessible` y docblocks
- `src/Model/Entity/Ticket.php` — mismo patrón
- `src/Model/Table/UsersTable.php` — quitar `belongsTo('Organizations')`, validador `organization_id`, regla `existsIn`, docblock

**Controladores:**
- `src/Controller/Admin/SettingsController.php` — quitar métodos `organizations()`, `addOrganization()`, `editOrganization()`, `deleteOrganization()`; quitar `contain(['Organizations'])` y carga de `$organizations` en CRUD de users
- `src/Controller/TicketsController.php` — quitar mapeo `'organization_id' => 'filter_organization'`
- `src/Controller/Traits/TicketSystemListingTrait.php` — quitar `Organizations` de contain de Requesters y carga de `$data['organizations']`
- `src/Controller/Traits/TicketSystemViewTrait.php` — `'Requesters' => ['Organizations']` → `'Requesters'`

**Servicios:**
- `src/Service/N8nService.php` — eliminar bloque que añade `organization` al payload (líneas 127-129)

**Templates:**
- `templates/layout/admin.php` — quitar link "Organizaciones" del menú admin
- `templates/Admin/Settings/users.php` — quitar columna organización
- `templates/Admin/Settings/add_user.php` — quitar label/select de organización
- `templates/Admin/Settings/edit_user.php` — mismo
- `templates/Admin/Settings/index.php` — revisar y limpiar tarjetas/links
- `templates/element/tickets/right_sidebar.php` — quitar bloque "Organización" del requester
- `templates/element/pqrs/right_sidebar.php` — mismo
- `templates/element/compras/right_sidebar.php` — mismo

**Tests:**
- `tests/Fixture/UsersFixture.php` — quitar `'organization_id' => 1`
- `tests/Fixture/TicketsFixture.php` — quitar `'organization_id' => 1`
- `tests/TestCase/Service/TicketServiceTest.php` — quitar `'app.Organizations'` de `$fixtures`
- `tests/TestCase/Model/Table/TicketsTableTest.php` — mismo
- `tests/TestCase/Model/Table/UsersTableTest.php` — mismo

## Lo que NO se toca

- **Migraciones existentes** (`20260105000001_CreateOrganizations.php`, columna `organization_id` en `CreateUsers`, seed con `organization_id => null`) — historia inmutable. Un fresh install seguirá creando la tabla, queda huérfana sin código que la use.
- **`config/schema/mesadeayuda.sql`** — snapshot de migraciones, no fuente de verdad.
- **Documentación en `docs/`** (integraciones, modelo-datos, README, webhooks, PROYECTO-COMPLETO) — se actualiza aparte si es necesario.
- **Tabla `organizations` en BD** — persiste con datos.
- **Columnas `organization_id`** — persisten nullable, sin FK.

## Verificación

1. `composer stan` (PHPStan nivel 5) sin errores
2. `composer cs-check` estilo OK
3. `composer test` todos los tests pasan
4. Grep: `grep -ri "organiz" src/ templates/ tests/ config/routes.php` — sin referencias funcionales
5. Smoke test manual:
   - Menú admin sin "Organizaciones"
   - Form usuario sin campo organización
   - Sidebar ticket sin organización
   - Payload n8n sin clave `organization`

## Resumen

- 11 archivos eliminados
- ~20 archivos modificados
- 0 cambios en BD (en este entorno)
- 0 migraciones nuevas
