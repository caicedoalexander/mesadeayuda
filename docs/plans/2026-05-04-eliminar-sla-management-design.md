# Eliminar funcionalidad de SLA Management

Fecha: 2026-05-04

## Objetivo

Eliminar completamente la funcionalidad de SLA (Service Level Agreement) del sistema: archivos, columnas de BD, settings, referencias en código y plantillas. No se conserva nada para reactivación posterior.

Incluye también eliminar `first_response_at` en `tickets` y `compras` (su único uso era alimentar métricas SLA).

## Archivos a eliminar

- `src/Controller/Admin/SlaManagementController.php`
- `src/Service/SlaManagementService.php`
- `src/Service/Traits/SlaAwareTrait.php`
- `src/View/Helper/SlaHelper.php`
- `templates/Admin/SlaManagement/` (carpeta completa)

## Cambios por archivo

### View / templates
- `src/View/AppView.php`: quitar `addHelper('Sla')`.
- `templates/Admin/Settings/index.php`: quitar tarjeta "Gestión SLA".
- `templates/Compras/index.php`: quitar filtro `vencidos_sla`, columna `<th>SLA</th>`, bloque `getSlaDisplayStatus`, `slaIcon`, `$rowClass` derivado de SLA.
- `templates/cell/ComprasSidebar/display.php`: quitar `<li>` SLA Vencidos.
- `templates/element/compras/left_sidebar.php`: quitar bloque `dualSlaIndicator`.
- `src/View/Cell/ComprasSidebarCell.php`: quitar conteo `vencidos_sla`.
- `src/View/Helper/ComprasHelper.php`: quitar comentario obsoleto.

### Compras
- `src/Service/ComprasService.php`: quitar `SlaAwareTrait`, `$slaService`, cálculo de deadlines en creación, override `getResolutionSlaDue()`, finder de SLA vencidos.
- `src/Controller/ComprasController.php`: quitar `$isSLABreached` y comentarios.
- `src/Model/Table/ComprasTable.php`: quitar validaciones `sla_due_date` y `first_response_at`; quitar `case 'vencidos_sla'`.
- `src/Model/Entity/Compra.php`: quitar properties phpdoc y entradas `$_accessible` para `sla_due_date`, `first_response_at`, `first_response_sla_due`, `resolution_sla_due`.

### Tickets
- `src/Model/Entity/Ticket.php`: quitar phpdoc + accessible para `first_response_at`.
- `src/Model/Table/TicketsTable.php`: quitar validación `first_response_at`.
- `src/Service/Traits/TicketSystemTrait.php`: quitar lógica que escribe `first_response_at` al crear primer comentario no-sistema.

### Integraciones
- `src/Service/EmailService.php`: quitar `$slaDue` y entrada `sla_due_date` del payload.
- `src/Service/Renderer/NotificationRenderer.php`: quitar referencias a `resolution_sla_due` / `sla_due_date`.
- `src/Service/SettingsService.php`: quitar `'sla_settings'` del array de cache keys.

### Migración consolidada
`config/Migrations/20260430213127_Initial.php`: quitar columnas `sla_due_date`, `first_response_sla_due`, `resolution_sla_due`, `first_response_at` (en `compras` y `tickets`); quitar índices `idx_sla_due_date`, `idx_compras_first_response_sla`, `idx_compras_resolution_sla`; actualizar comentario de ejemplo en `system_settings`.

### Docs
- `CLAUDE.md`, `README.md`: quitar menciones SLA / SlaManagement.

## SQL manual (ejecuta el usuario)

```sql
ALTER TABLE compras
    DROP INDEX idx_sla_due_date,
    DROP INDEX idx_compras_first_response_sla,
    DROP INDEX idx_compras_resolution_sla,
    DROP COLUMN sla_due_date,
    DROP COLUMN first_response_sla_due,
    DROP COLUMN resolution_sla_due,
    DROP COLUMN first_response_at;

ALTER TABLE tickets
    DROP COLUMN first_response_at;

DELETE FROM system_settings WHERE setting_key LIKE 'sla_%';
```

## Verificación manual

- `/` carga sin error de helper.
- `/compras` listado sin columna SLA ni filtro `vencidos_sla`.
- Vista de un compra/ticket: sidebar sin bloque SLA.
- Crear nueva compra: persiste sin errores.
- Crear ticket + comentar: sin error por `first_response_at`.
- `/admin/settings`: sin tarjeta "Gestión SLA".
- `/admin/sla-management`: 404 (esperado).
- Notificaciones email de compra: sin errores; revisar manualmente plantillas que usen `{sla_due_date}` y limpiar.

## Riesgo

Plantillas en `email_templates` con placeholder `{sla_due_date}` aparecerán vacías — limpieza manual post-deploy vía `/admin/email-templates`.
