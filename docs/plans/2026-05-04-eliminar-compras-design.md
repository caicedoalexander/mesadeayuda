# Eliminar módulo Compras

Fecha: 2026-05-04

## Objetivo

Eliminar completamente el módulo Compras (controlador, servicio, helper, cell, entities, tables, layout, plantillas, rutas, sidebar, columna `original_ticket_number` y conversiones bidireccionales Ticket↔Compra). Conservar los traits compartidos (`TicketSystem*`, `GenericAttachmentTrait`, `NotificationDispatcherTrait`) limpiando solo las ramas `compra`. Eliminar `EntityConversionTrait` (existe únicamente para la conversión).

## Archivos eliminados

- `src/Controller/ComprasController.php`
- `src/Service/ComprasService.php`
- `src/View/Helper/ComprasHelper.php`
- `src/View/Cell/ComprasSidebarCell.php`
- `src/Model/Entity/{Compra,ComprasComment,ComprasHistory,ComprasAttachment}.php`
- `src/Model/Table/{ComprasTable,ComprasCommentsTable,ComprasHistoryTable,ComprasAttachmentsTable}.php`
- `src/Service/Traits/EntityConversionTrait.php`
- `templates/Compras/`
- `templates/cell/ComprasSidebar/`
- `templates/element/compras/`
- `templates/layout/compras.php`

## Cambios principales

- `config/routes.php`: borradas rutas `/compras/*` y `tickets/convert-to-compra`.
- `src/Controller/AppController.php`: quitado layout compras y rama ROLE_COMPRAS.
- `TicketsController` / `TicketService`: borrados `convertToCompra`, `createFromCompra`, `copyCompraData`.
- `EntityType`, `ValidationConstants`, `SettingKeys`: quitadas todas las constantes/casos compra.
- Servicios (`EmailService`, `WhatsappService`, `GmailService`, `Renderer/NotificationRenderer`, `NumberGenerationService`, `SidebarCountsService`, `AuthorizationService`): eliminadas ramas `compra`.
- Traits compartidos: limpiadas ramas `'compra' =>` (los traits permanecen, solo soportan ticket).
- Settings admin (`index`, `users`, `add_user`, `edit_user`): sin campo whatsapp compras ni opción de rol.
- Migración Initial: tablas `compras*`, FKs, índices y drops removidos.
- Docs (`CLAUDE.md`, `README.md`): sin referencias a Compras.

## SQL manual ejecutado por el usuario

```sql
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS compras_attachments;
DROP TABLE IF EXISTS compras_comments;
DROP TABLE IF EXISTS compras_history;
DROP TABLE IF EXISTS compras;
SET FOREIGN_KEY_CHECKS=1;

DELETE FROM system_settings WHERE setting_key = 'whatsapp_compras_number';
UPDATE users SET role = 'agent' WHERE role = 'compras';
```

## Verificación manual

- `composer cs-check` pasa.
- `/` carga.
- `/compras*` y `/tickets/convert-to-compra/*` → 404.
- Ticket view sin botón "Convertir a Compra".
- `/admin/settings` sin campo `whatsapp_compras_number`.
- Crear/comentar/adjuntar ticket OK.
- Worker Gmail no falla.

## Nota futura

Los traits `TicketSystem*Trait` y `GenericAttachmentTrait` quedaron sobre-abstractos (firmas con `$entityType` que solo aceptan `'ticket'`). Candidato a aplanamiento futuro hacia `TicketsController`/`TicketService` directos.
