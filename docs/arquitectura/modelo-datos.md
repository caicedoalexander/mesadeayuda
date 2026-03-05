# Modelo de Datos

Base de datos MySQL 8.0+ con codificacion utf8mb4 y zona horaria America/Bogota (UTC-5).

## Resumen de Tablas

El sistema cuenta con 23 tablas organizadas en 4 grupos:

| Grupo | Tablas | Descripcion |
|---|---|---|
| Core | `users`, `organizations` | Usuarios y organizaciones |
| Tickets | `tickets`, `ticket_comments`, `ticket_history`, `ticket_followers`, `tickets_tags`, `attachments`, `tags` | Modulo de mesa de ayuda |
| Compras | `compras`, `compras_comments`, `compras_history`, `compras_attachments` | Modulo de compras |
| PQRS | `pqrs`, `pqrs_comments`, `pqrs_history`, `pqrs_attachments` | Modulo de PQRS |
| Sistema | `system_settings`, `email_templates` | Configuracion global |

---

## Tablas Core

### organizations

Soporte multi-tenant. Permite aislar datos entre organizaciones.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `name` | varchar(255) | Nombre de la organizacion |
| `domain` | varchar(255) | Dominio de email para auto-asignacion |
| `created` | datetime | Fecha de creacion |
| `modified` | datetime | Ultima modificacion |

**Relaciones**: hasMany Tickets, hasMany Users

### users

Usuarios del sistema con roles diferenciados.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `email` | varchar(255), unique | Email (login) |
| `password` | varchar(255), nullable | Hash de contrasena (nullable para usuarios auto-creados desde Gmail) |
| `first_name` | varchar(100) | Nombre |
| `last_name` | varchar(100) | Apellido |
| `role` | enum | `admin`, `agent`, `compras`, `servicio_cliente`, `requester` |
| `organization_id` | unsigned int, nullable | FK a organizations |
| `profile_image` | varchar(255), nullable | Ruta de imagen de perfil (S3 o local) |
| `is_active` | boolean | Estado activo/inactivo |
| `created` | datetime | Fecha de creacion |
| `modified` | datetime | Ultima modificacion |

**Relaciones**: belongsTo Organizations. hasMany TicketComments, TicketFollowers.
**Indices**: unique(email), composite(role, is_active)

---

## Tablas de Tickets

### tickets

Tabla principal del modulo de mesa de ayuda. Formato de numeracion: `TKT-YYYY-NNNNN`.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_number` | varchar(20), unique | Numero auto-generado (TKT-2026-00001) |
| `gmail_message_id` | varchar(255), unique | ID del mensaje Gmail para threading |
| `gmail_thread_id` | varchar(255) | ID del hilo Gmail |
| `email_to` | text (JSON) | Destinatarios del email |
| `email_cc` | text (JSON) | Copia del email |
| `subject` | varchar(255) | Asunto |
| `description` | text | Descripcion (soporta HTML sanitizado) |
| `status` | enum | Ver estados abajo |
| `priority` | enum | `baja`, `media`, `alta`, `urgente` |
| `channel` | varchar(20) | `email`, `web`, `api` |
| `requester_id` | unsigned int (FK) | Usuario solicitante (requerido) |
| `assignee_id` | unsigned int, nullable (FK) | Agente asignado |
| `resolved_at` | datetime, nullable | Fecha de resolucion |
| `first_response_at` | datetime, nullable | Fecha de primera respuesta |
| `created` | datetime | Fecha de creacion |
| `modified` | datetime | Ultima modificacion |

**Estados**: `nuevo` → `abierto` → `pendiente` → `resuelto` | `convertido`

**Relaciones**:
- belongsTo Requesters (Users), Assignees (Users)
- hasMany TicketComments, TicketHistory, Attachments, TicketFollowers, TicketTags
- belongsToMany Tags (via tickets_tags)

**Indices**: unique(ticket_number), unique(gmail_message_id), composite(status, priority), composite(assignee_id, status)

### ticket_comments

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_id` | unsigned int (FK) | Ticket asociado |
| `user_id` | unsigned int (FK) | Autor del comentario |
| `body` | text | Contenido (HTML) |
| `comment_type` | enum | `public`, `internal`, `system` |
| `is_system_comment` | boolean | Comentario generado por el sistema |
| `sent_as_email` | boolean | Enviado como email |
| `gmail_message_id` | varchar(255) | ID Gmail para threading |
| `created` | datetime | Fecha de creacion |
| `modified` | datetime | Ultima modificacion |

### ticket_history

Registro de auditoria para cada cambio en un ticket.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_id` | unsigned int (FK) | Ticket |
| `changed_by` | unsigned int, nullable (FK) | Usuario que realizo el cambio |
| `field_name` | varchar(50) | Campo modificado |
| `old_value` | text | Valor anterior |
| `new_value` | text | Valor nuevo |
| `description` | varchar(500) | Descripcion legible del cambio |
| `created` | datetime | Fecha del cambio |

**Metodo auxiliar**: `logChange($ticketId, $fieldName, $oldValue, $newValue, $userId, $description)`

### ticket_followers

Tabla de union para usuarios que observan un ticket.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_id` | unsigned int (FK) | Ticket |
| `user_id` | unsigned int (FK) | Usuario seguidor |
| `created` | datetime | Fecha |

**Restriccion**: unique(ticket_id, user_id)

### attachments

Adjuntos de tickets y comentarios. Soporta almacenamiento en S3 y local.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_id` | unsigned int, nullable (FK) | Ticket |
| `comment_id` | unsigned int, nullable (FK) | Comentario |
| `filename` | varchar(255) | Nombre de archivo en disco |
| `original_filename` | varchar(255) | Nombre original del archivo |
| `file_path` | varchar(500) | Ruta completa (S3 o local) |
| `mime_type` | varchar(100) | Tipo MIME |
| `file_size` | int | Tamano en bytes |
| `is_inline` | boolean | Imagen inline (CID) |
| `content_id` | varchar(255) | Content-ID para imagenes inline |
| `uploaded_by` | unsigned int (FK) | Usuario que subio el archivo |
| `created` | datetime | Fecha |

### tags

Etiquetas de categorizacion para tickets.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `name` | varchar(100), unique | Nombre de la etiqueta |
| `color` | varchar(7) | Color hexadecimal (#FF5733) |
| `is_active` | boolean | Estado activo/inactivo (inactivos ocultos de seleccion) |
| `created` | datetime | Fecha |
| `modified` | datetime | Ultima modificacion |

### tickets_tags

Tabla de union tickets-tags (many-to-many).

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_id` | unsigned int (FK) | Ticket |
| `tag_id` | unsigned int (FK) | Tag |
| `created` | datetime | Fecha |

**Restriccion**: unique(ticket_id, tag_id)

---

## Tablas de Compras

### compras

Tabla principal del modulo de compras. Formato de numeracion: `CPR-YYYY-NNNNN`.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `compra_number` | varchar(20), unique | Numero auto-generado (CPR-2026-00001) |
| `original_ticket_number` | varchar(20), nullable | Ticket de origen (si fue convertido) |
| `subject` | varchar(255) | Asunto |
| `description` | text | Descripcion (HTML) |
| `status` | enum | Ver estados abajo |
| `priority` | enum | `baja`, `media`, `alta`, `urgente` |
| `channel` | varchar(20) | `email`, `whatsapp` |
| `email_to` | json | Destinatarios |
| `email_cc` | json | Copia |
| `requester_id` | unsigned int (FK) | Solicitante |
| `assignee_id` | unsigned int, nullable (FK) | Responsable asignado |
| `sla_due_date` | datetime, nullable | Fecha limite SLA (legacy) |
| `first_response_at` | datetime, nullable | Primera respuesta |
| `resolved_at` | datetime, nullable | Fecha de resolucion |
| `first_response_sla_due` | datetime, nullable | Limite SLA primera respuesta |
| `resolution_sla_due` | datetime, nullable | Limite SLA resolucion |
| `created` | datetime | Fecha de creacion |
| `modified` | datetime | Ultima modificacion |

**Estados**: `nuevo` → `en_revision` → `aprobado` → `en_proceso` → `completado` | `rechazado`

**Relaciones**: belongsTo Requesters (Users), Assignees (Users). hasMany ComprasComments, ComprasAttachments, ComprasHistory.

### compras_comments

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `compra_id` | unsigned int (FK) | Compra |
| `user_id` | unsigned int, nullable (FK) | Autor |
| `body` | text | Contenido |
| `comment_type` | enum | `public`, `internal` |
| `is_system_comment` | boolean | Comentario del sistema |
| `sent_as_email` | boolean | Enviado como email |
| `created` | datetime | Fecha |

**Relaciones**: hasMany ComprasAttachments

### compras_attachments

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `compra_id` | unsigned int (FK) | Compra |
| `compras_comment_id` | unsigned int, nullable (FK) | Comentario asociado |
| `uploaded_by_user_id` | unsigned int, nullable (FK) | Usuario |
| `filename` | varchar(255) | Nombre en disco |
| `original_filename` | varchar(255) | Nombre original |
| `file_path` | varchar(500) | Ruta |
| `file_size` | int | Tamano en bytes |
| `mime_type` | varchar(100) | Tipo MIME |
| `is_inline` | boolean | Imagen inline |
| `content_id` | varchar(255) | Content-ID |
| `created` | datetime | Fecha |

### compras_history

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `compra_id` | unsigned int (FK) | Compra |
| `changed_by` | unsigned int, nullable (FK) | Usuario |
| `field_name` | varchar(100) | Campo modificado |
| `old_value` | varchar(255) | Valor anterior |
| `new_value` | varchar(255) | Valor nuevo |
| `description` | text | Descripcion del cambio |
| `created` | datetime | Fecha |

---

## Tablas de PQRS

### pqrs

Tabla principal del modulo PQRS. Formato de numeracion: `PQRS-YYYY-NNNNN`. A diferencia de Tickets y Compras, los datos del solicitante se almacenan directamente en la tabla (no requiere usuario autenticado).

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `pqrs_number` | varchar(20), unique | Numero auto-generado (PQRS-2026-00001) |
| `type` | enum | `peticion`, `queja`, `reclamo`, `sugerencia` |
| `subject` | varchar(255) | Asunto |
| `description` | text | Descripcion (HTML) |
| `status` | enum | Ver estados abajo |
| `priority` | enum | `baja`, `media`, `alta`, `urgente` |
| `channel` | varchar(20) | `web`, `whatsapp` |
| `requester_name` | varchar(255) | Nombre del solicitante |
| `requester_email` | varchar(255) | Email del solicitante |
| `requester_phone` | varchar(20), nullable | Telefono |
| `assignee_id` | unsigned int, nullable (FK) | Agente asignado |
| `ip_address` | varchar(45) | IP del solicitante (oculta en JSON) |
| `user_agent` | text | User-Agent del navegador (oculto en JSON) |
| `source_url` | varchar(500) | URL de origen |
| `resolved_at` | datetime, nullable | Fecha de resolucion |
| `first_response_at` | datetime, nullable | Primera respuesta |
| `closed_at` | datetime, nullable | Fecha de cierre |
| `first_response_sla_due` | datetime, nullable | Limite SLA primera respuesta |
| `resolution_sla_due` | datetime, nullable | Limite SLA resolucion |
| `created` | datetime | Fecha de creacion |
| `modified` | datetime | Ultima modificacion |

**Estados**: `nuevo` → `en_revision` → `en_proceso` → `resuelto` → `cerrado`

**Relaciones**: belongsTo Assignees (Users). hasMany PqrsComments, PqrsAttachments, PqrsHistory.

**Indices**: unique(pqrs_number), composite(type, status), composite(status, created)

### pqrs_comments

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `pqrs_id` | unsigned int (FK) | PQRS |
| `user_id` | unsigned int, nullable (FK) | Autor |
| `body` | text | Contenido |
| `comment_type` | enum | `public`, `internal` |
| `is_system_comment` | boolean | Comentario del sistema |
| `sent_as_email` | boolean | Enviado como email |
| `created` | datetime | Fecha |

**Relaciones**: hasMany PqrsAttachments

### pqrs_attachments

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `pqrs_id` | unsigned int (FK) | PQRS |
| `pqrs_comment_id` | unsigned int, nullable (FK) | Comentario |
| `uploaded_by_user_id` | unsigned int, nullable (FK) | Usuario |
| `filename` | varchar(255) | Nombre en disco |
| `original_filename` | varchar(255) | Nombre original |
| `file_path` | varchar(500) | Ruta |
| `file_size` | int | Tamano |
| `mime_type` | varchar(100) | Tipo MIME |
| `is_inline` | boolean | Inline |
| `content_id` | varchar(255) | Content-ID |
| `created` | datetime | Fecha |

### pqrs_history

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `pqrs_id` | unsigned int (FK) | PQRS |
| `changed_by` | unsigned int (FK) | Usuario (requerido, a diferencia de tickets/compras) |
| `field_name` | varchar(50) | Campo |
| `old_value` | varchar(255) | Valor anterior |
| `new_value` | varchar(255) | Valor nuevo |
| `description` | varchar(500) | Descripcion |
| `created` | datetime | Fecha |

---

## Tablas de Sistema

### system_settings

Almacena toda la configuracion del sistema como pares clave-valor. Valores sensibles se almacenan cifrados.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `setting_key` | varchar(100), unique | Clave de configuracion |
| `setting_value` | text | Valor (posiblemente cifrado) |
| `setting_type` | varchar(50) | Tipo de dato |
| `created` | datetime | Fecha |
| `modified` | datetime | Ultima modificacion |

**Claves cifradas**: `gmail_refresh_token`, `whatsapp_api_key`, `n8n_api_key`

### email_templates

Plantillas de notificacion por email con variables sustituibles.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `template_key` | varchar(100), unique | Identificador de plantilla |
| `subject` | varchar(255) | Asunto del email |
| `body_html` | text | Cuerpo HTML con variables {{variable}} |
| `available_variables` | text | Lista de variables disponibles |
| `is_active` | boolean | Plantilla activa |
| `created` | datetime | Fecha |
| `modified` | datetime | Ultima modificacion |

**Plantillas por modulo**:
- Tickets: `nuevo_ticket`, `ticket_estado`, `nuevo_comentario`, `ticket_respuesta`
- Compras: `nueva_compra`, `compra_estado`, `compra_comentario`, `compra_respuesta`
- PQRS: `nuevo_pqrs`, `pqrs_estado`, `pqrs_comentario`, `pqrs_respuesta`

---

## Diagrama de Relaciones

```
organizations ── users (1:N)

users ──┬── tickets (como requester, 1:N, CASCADE)
        ├── tickets (como assignee, 1:N, SET_NULL)
        ├── ticket_comments (1:N, CASCADE)
        ├── ticket_followers (1:N, CASCADE)
        ├── compras (como requester, 1:N, CASCADE)
        ├── compras (como assignee, 1:N, SET_NULL)
        ├── compras_comments (1:N, CASCADE)
        ├── pqrs (como assignee, 1:N, SET_NULL)
        └── pqrs_comments (1:N, CASCADE)

tickets ──┬── ticket_comments (1:N, CASCADE)
          ├── ticket_followers (1:N, CASCADE)
          ├── attachments (1:N, CASCADE)
          ├── tickets_tags (1:N, CASCADE)
          ├── ticket_history (1:N, CASCADE)
          └── tags (N:M via tickets_tags)

compras ──┬── compras_comments (1:N, CASCADE)
          ├── compras_attachments (1:N, CASCADE)
          └── compras_history (1:N)

compras_comments ── compras_attachments (1:N)

pqrs ──┬── pqrs_comments (1:N, CASCADE)
       ├── pqrs_attachments (1:N, CASCADE)
       └── pqrs_history (1:N)

pqrs_comments ── pqrs_attachments (1:N)
```

## Estrategia de Indices

- **Indices unicos**: ticket_number, gmail_message_id, compra_number, pqrs_number, email (users), template_key, setting_key
- **Indices simples**: priority, assignee_id, requester_id, created, channel, status, type, is_active
- **Indices compuestos**: (status, priority), (assignee_id, status), (status, created), (type, status), (role, is_active)
- **Foreign keys**: Todas las columnas FK tienen indice automatico
