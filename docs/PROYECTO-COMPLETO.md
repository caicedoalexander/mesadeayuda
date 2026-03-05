# Mesa de Ayuda - Documentacion Completa del Proyecto

---

## 1. Que es Mesa de Ayuda

Sistema corporativo construido con **CakePHP 5.x** que unifica tres necesidades operativas en una sola plataforma:

| Modulo | Proposito | Entrada principal |
|---|---|---|
| **Tickets** (Helpdesk) | Soporte interno: problemas de TI, solicitudes, incidencias | Emails (Gmail) y formulario web |
| **Compras** | Gestion de adquisiciones con aprobacion y seguimiento | Conversion desde tickets o creacion directa |
| **PQRS** | Canal publico para clientes: peticiones, quejas, reclamos, sugerencias | Formulario publico (sin login) |
| **Admin** | Configuracion global: integraciones, SLA, usuarios, plantillas | Panel administrativo |

### Stack tecnologico

| Componente | Tecnologia |
|---|---|
| Backend | CakePHP 5.x (PHP 8.1+) |
| Base de datos | MySQL 8.0+ (utf8mb4, timezone America/Bogota) |
| Frontend | Bootstrap 5, templates server-rendered |
| Email | Google Gmail API (OAuth2) |
| Automatizacion | n8n (webhooks para clasificacion AI) |
| Mensajeria | WhatsApp Business via Evolution API |
| Almacenamiento | AWS S3 (opcional) o local |
| Despliegue | Docker (PHP-FPM + Nginx + Worker) |

---

## 2. Roles y Permisos

| Rol | Descripcion | Tickets | Compras | PQRS | Admin |
|---|---|---|---|---|---|
| `admin` | Administrador | Completo | Completo | Completo | Completo |
| `agent` | Agente de soporte | Completo | Lectura | Lectura | No |
| `compras` | Personal de compras | Lectura | Completo | Lectura | No |
| `servicio_cliente` | Servicio al cliente | Lectura | Lectura | Completo | No |
| `requester` | Solicitante | Solo propios | No | No | No |

---

## 3. Arquitectura General

```
┌──────────────────────────────────────────────────────────┐
│                    CLIENTES                               │
│  Navegador Web  │  Email (Gmail)  │  Formulario Publico  │
└────────┬────────────────┬──────────────────┬─────────────┘
         │                │                  │
         ▼                ▼                  ▼
┌──────────────────────────────────────────────────────────┐
│               CONTROLADORES                               │
│  TicketsController │ ComprasController │ PqrsController   │
│  Admin\SettingsController │ Admin\SlaManagementController │
└────────┬─────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────┐
│               CAPA DE SERVICIOS (logica de negocio)       │
│  TicketService    │ ComprasService   │ PqrsService        │
│  GmailService     │ EmailService     │ WhatsappService    │
│  N8nService       │ S3Service        │ SlaManagementSvc   │
│  StatisticsService│ SettingsService  │ AuthorizationSvc   │
└────────┬─────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────┐
│           INTEGRACIONES EXTERNAS                          │
│  Gmail API  │  Evolution API  │  n8n Webhooks  │  AWS S3 │
└──────────────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────┐
│               BASE DE DATOS (MySQL 8.0+)                  │
│  23 tablas organizadas en 4 grupos                        │
└──────────────────────────────────────────────────────────┘
```

### Patrones clave

- **Service Layer**: Controladores delegan toda la logica a servicios. Un controlador nunca accede a BD directamente.
- **Traits reutilizables**: 7 traits de servicio (notificaciones, adjuntos, SLA, conversion) + 5 traits de controlador (acciones, listado, vista, historial, bulk).
- **Auditoria completa**: Cada cambio en tickets, compras y PQRS se registra en tablas de historial (quien, que, cuando, valor anterior/nuevo).

---

## 4. Modelo de Datos (23 tablas)

### Tablas por grupo

| Grupo | Tablas |
|---|---|
| **Core** | `users`, `organizations` |
| **Tickets** | `tickets`, `ticket_comments`, `ticket_history`, `ticket_followers`, `tickets_tags`, `attachments`, `tags` |
| **Compras** | `compras`, `compras_comments`, `compras_history`, `compras_attachments` |
| **PQRS** | `pqrs`, `pqrs_comments`, `pqrs_history`, `pqrs_attachments` |
| **Sistema** | `system_settings`, `email_templates` |

### Tabla: users

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `email` | varchar(255), unique | Email (usado como login) |
| `password` | varchar(255), nullable | Hash de contrasena (nullable para usuarios auto-creados por Gmail) |
| `first_name`, `last_name` | varchar(255) | Nombre y apellido |
| `phone` | varchar(50) | Telefono |
| `role` | enum | `admin`, `agent`, `compras`, `servicio_cliente`, `requester` |
| `organization_id` | FK nullable | Organizacion del usuario |
| `profile_image` | varchar(500) | Ruta de imagen (S3 o local) |
| `is_active` | boolean | Activo/inactivo |

### Tabla: tickets

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `ticket_number` | varchar(20), unique | `TKT-YYYY-NNNNN` auto-generado |
| `gmail_message_id` | varchar(255), unique | ID Gmail para threading de emails |
| `gmail_thread_id` | varchar(255) | Hilo de Gmail |
| `email_to`, `email_cc` | text (JSON) | Destinatarios del email original |
| `subject` | varchar(255) | Asunto |
| `description` | text | Descripcion (HTML sanitizado) |
| `status` | enum | `nuevo`, `abierto`, `pendiente`, `resuelto`, `cerrado`, `convertido` |
| `priority` | enum | `baja`, `media`, `alta`, `urgente` |
| `channel` | varchar(20) | `email`, `whatsapp` |
| `requester_id` | FK (requerido) | Solicitante |
| `assignee_id` | FK (nullable) | Agente asignado |
| `resolved_at`, `first_response_at` | datetime | Tracking SLA |

**Relaciones**: belongsTo Users (requester + assignee), hasMany Comments/History/Attachments/Followers, belongsToMany Tags.

### Tabla: compras

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `compra_number` | varchar(20), unique | `CPR-YYYY-NNNNN` auto-generado |
| `original_ticket_number` | varchar(20), nullable | Ticket de origen (si fue convertido) |
| `subject`, `description` | texto | Datos de la solicitud |
| `status` | enum | `nuevo`, `en_revision`, `aprobado`, `en_proceso`, `completado`, `rechazado`, `convertido` |
| `priority` | enum | `baja`, `media`, `alta`, `urgente` |
| `requester_id`, `assignee_id` | FK | Solicitante y responsable |
| `first_response_sla_due`, `resolution_sla_due` | datetime | Plazos SLA calculados |

### Tabla: pqrs

Diferencia clave: **no requiere usuario autenticado**. Los datos del solicitante se almacenan directamente.

| Columna | Tipo | Descripcion |
|---|---|---|
| `id` | unsigned int (PK) | Identificador |
| `pqrs_number` | varchar(20), unique | `PQRS-YYYY-NNNNN` auto-generado |
| `type` | enum | `peticion`, `queja`, `reclamo`, `sugerencia` |
| `subject`, `description` | texto | Datos de la solicitud |
| `status` | enum | `nuevo`, `en_revision`, `en_proceso`, `resuelto`, `cerrado` |
| `requester_name`, `requester_email`, `requester_phone` | varchar | Datos del ciudadano |
| `requester_id_number`, `requester_address`, `requester_city` | varchar/text | Identificacion y ubicacion |
| `assignee_id` | FK (nullable) | Agente asignado |
| `ip_address`, `user_agent` | varchar/text | Metadata (ocultos en JSON) |
| `first_response_sla_due`, `resolution_sla_due` | datetime | Plazos SLA |

### Tablas de soporte (patron repetido en los 3 modulos)

Cada modulo tiene sus propias tablas de comentarios, adjuntos e historial:

| Tipo | Tickets | Compras | PQRS |
|---|---|---|---|
| Comentarios | `ticket_comments` | `compras_comments` | `pqrs_comments` |
| Adjuntos | `attachments` | `compras_attachments` | `pqrs_attachments` |
| Historial | `ticket_history` | `compras_history` | `pqrs_history` |

**Comentarios** tienen tipo `public`, `internal` (o `system` en tickets). Se marca si fue enviado como email (`sent_as_email`).

**Adjuntos** soportan archivos normales e imagenes inline (CID para emails). Almacenamiento dual S3/local.

**Historial** registra: campo modificado, valor anterior, valor nuevo, usuario responsable, descripcion.

### Tablas adicionales de Tickets

- `tags`: Etiquetas (nombre + color hex) para categorizar tickets
- `tickets_tags`: Tabla pivote tickets-tags (many-to-many)
- `ticket_followers`: Usuarios que "siguen" un ticket para recibir notificaciones

### Tablas de sistema

- `system_settings`: Configuracion clave-valor (credenciales cifradas, intervalos, URLs)
- `email_templates`: Plantillas HTML de notificacion con variables `{{sustituibles}}`

### Diagrama de relaciones

```
organizations ──── users (1:N)

users ──┬── tickets (requester 1:N / assignee 1:N)
        ├── compras (requester 1:N / assignee 1:N)
        └── pqrs (assignee 1:N)

tickets ──┬── ticket_comments (1:N)
          ├── attachments (1:N)
          ├── ticket_history (1:N)
          ├── ticket_followers (1:N)
          └── tags (N:M via tickets_tags)

compras ──┬── compras_comments ── compras_attachments
          ├── compras_attachments (1:N)
          └── compras_history (1:N)

pqrs ──┬── pqrs_comments ── pqrs_attachments
       ├── pqrs_attachments (1:N)
       └── pqrs_history (1:N)
```

---

## 5. Flujos de Negocio

### 5.1 Ciclo de vida del Ticket

```
nuevo → abierto → pendiente → resuelto → cerrado
                                 │
                                 └──→ convertido
                                  (conversion a compra)
```

| Estado | Significado |
|---|---|
| `nuevo` | Recien creado (via email, web o API). Sin asignar. |
| `abierto` | Asignado a un agente. Esperando accion. |
| `pendiente` | Esperando respuesta del solicitante o tercero. |
| `resuelto` | Solucion aplicada. Esperando confirmacion. |
| `cerrado` | Cerrado definitivamente. |
| `convertido` | Convertido a compra. Se crea una compra vinculada. |

**Numeracion**: `TKT-YYYY-NNNNN`
**Prioridades**: `baja`, `media`, `alta`, `urgente`
**Canales**: `email` (Gmail), `whatsapp` (n8n)

### 5.2 Ciclo de vida de Compra

```
nuevo → en_revision → aprobado → en_proceso → completado
                  │                               │
                  └──→ rechazado                   └──→ convertido
```

| Estado | Significado |
|---|---|
| `nuevo` | Solicitud recien creada. |
| `en_revision` | En revision por compras. |
| `aprobado` | Aprobada, pendiente de ejecucion. |
| `en_proceso` | En proceso de adquisicion. |
| `completado` | Finalizada exitosamente. |
| `rechazado` | Rechazada. |
| `convertido` | Convertido a ticket (flujo inverso). |

**Numeracion**: `CPR-YYYY-NNNNN`

### 5.3 Ciclo de vida de PQRS

```
nuevo → en_revision → en_proceso → resuelto → cerrado
```

| Estado | Significado |
|---|---|
| `nuevo` | PQRS recien creado desde formulario publico. |
| `en_revision` | En revision por servicio al cliente. |
| `en_proceso` | En proceso de atencion. |
| `resuelto` | Resuelto. |
| `cerrado` | Cerrado definitivamente. |

**Numeracion**: `PQRS-YYYY-NNNNN`
**Tipos**: `peticion`, `queja`, `reclamo`, `sugerencia`
**Sin autenticacion**: datos del solicitante se guardan directamente en la tabla.

### 5.4 Conversion bidireccional Ticket <-> Compra

**Ticket a Compra:**
1. Agente/admin hace clic en "Convertir a Compra"
2. El sistema crea una compra con los datos del ticket
3. Copia todos los comentarios y adjuntos
4. Calcula SLA para la nueva compra
5. Marca el ticket como `convertido` y registra en historial
6. Guarda referencia al ticket original (`original_ticket_number`)
7. Envia notificaciones de nueva compra (email + WhatsApp)

**Compra a Ticket:** Mismo proceso en sentido inverso.

### 5.5 Importacion automatica de Gmail

```
GmailWorkerCommand (loop infinito, cada 5 min por defecto)
  │
  ├── Obtiene emails no leidos: GmailService::getMessages('is:unread')
  │
  └── Por cada email:
      ├── Parsea mensaje (headers, cuerpo HTML/texto, adjuntos)
      ├── Detecta auto-replies y notificaciones del sistema → los ignora
      ├── Busca hilo existente por gmail_thread_id
      │   ├── Si existe ticket → agrega como comentario
      │   └── Si no existe → crea nuevo ticket
      │       └── Busca o crea usuario por email del remitente
      ├── Descarga adjuntos → S3 o local
      ├── Marca como leido
      └── Notifica: email al equipo + WhatsApp + webhook a n8n
```

**Resiliencia del worker**: backoff exponencial en errores (60s → 600s max), shutdown graceful con SIGTERM/SIGINT, espera a BD en startup.

### 5.6 Formulario publico PQRS

```
Ciudadano accede a /pqrs/formulario (sin login)
  ↓
Completa: tipo, datos personales, asunto, descripcion, adjuntos
  ↓
PqrsService::createFromForm()
  ├── Genera PQRS-YYYY-NNNNN
  ├── Registra IP + User-Agent
  ├── Calcula SLA segun tipo
  ├── Guarda adjuntos
  └── Notifica equipo (email + WhatsApp) + confirmacion al solicitante
  ↓
Redirige a /pqrs/success/{numero} → muestra radicado
```

### 5.7 Flujo de respuesta de un agente

Cuando un agente responde a cualquier entidad (ticket/compra/PQRS):

```
Controller → TicketSystemActionsTrait::addEntityComment()
  └── $service->handleResponse($entityId, $userId, $data, $files)
      ├── 1. Crea comentario (via TicketSystemTrait::addComment)
      ├── 2. Guarda adjuntos (via saveUploadedFile)
      ├── 3. Cambia estado (opcional, via changeStatus)
      └── 4. Notifica por email (via sendResponseNotifications):
          ├── Comentario + estado → email "respuesta" unificado
          ├── Solo comentario → email "comentario"
          └── Solo estado → email "cambio de estado"
          (WhatsApp solo en creacion de entidad)
```

Cada servicio (TicketService, ComprasService, PqrsService) implementa `handleResponse()` usando los metodos compartidos de `TicketSystemTrait`.

---

## 6. Gestion de SLA

### Plazos por defecto

**PQRS** (segun tipo):

| Tipo | Primera respuesta | Resolucion |
|---|---|---|
| Peticion | 2 dias | 5 dias |
| Queja | 1 dia | 3 dias |
| Reclamo | 1 dia | 3 dias |
| Sugerencia | 3 dias | 7 dias |

**Compras**: 1 dia primera respuesta, 3 dias resolucion.

### Como funciona

1. Al crear la entidad, `SlaManagementService` calcula las fechas limite
2. Se almacenan en `first_response_sla_due` y `resolution_sla_due`
3. El sistema evalua continuamente el estado:
   - **met**: cumplido dentro del plazo
   - **breached**: plazo vencido
   - **approaching**: menos de 24 horas para vencer
   - **on_track**: dentro del plazo con margen

Los plazos se configuran desde `/admin/sla-management`.

---

## 7. Integraciones Externas

### 7.1 Gmail API

**Proposito**: Importar emails como tickets y enviar notificaciones.

**Setup**:
1. Admin sube `client_secret.json` desde Google Cloud Console
2. Autoriza acceso via OAuth2 (flujo web)
3. El refresh token se guarda cifrado en BD
4. El worker empieza a importar emails automaticamente

**Funcionalidades**:
- Importacion automatica de emails no leidos como tickets
- Threading: respuestas a un ticket se agregan como comentarios
- Adjuntos: descarga automatica de archivos e imagenes inline
- Deteccion de bucles: ignora auto-replies y respuestas a notificaciones del sistema
- Envio de notificaciones por email via Gmail API

### 7.2 n8n (Clasificacion AI)

**Proposito**: Clasificar tickets automaticamente usando IA.

**Flujo**:
1. Se crea un ticket
2. El sistema envia un webhook POST a n8n con datos del ticket
3. n8n procesa con IA (clasifica el tipo de solicitud)
4. n8n responde al callback URL con los tags sugeridos
5. Los tags se asignan automaticamente al ticket

**Payload del webhook**: Incluye ticket completo, datos del solicitante, adjuntos, lista de tags disponibles y callback URL.

### 7.3 WhatsApp (Evolution API)

**Proposito**: Notificar a equipos internos cuando se crean nuevas entidades.

**Funcionamiento**:
- Cada modulo tiene un numero/grupo de WhatsApp configurable
- Solo se envia al **crear** (no en actualizaciones)
- Tickets → grupo de soporte
- Compras → grupo de compras
- PQRS → grupo de servicio al cliente

**API**: POST a `{api_url}/message/sendText/{instance_name}` con apikey en header.

### 7.4 AWS S3

**Proposito**: Almacenamiento de archivos (opcional).

- Si S3 esta habilitado → archivos van a S3 con URLs presignadas
- Si no → archivos van a `webroot/uploads/` (local)
- Funciona para adjuntos de todos los modulos e imagenes de perfil

### Cifrado de credenciales

Todas las credenciales (`gmail_refresh_token`, `whatsapp_api_key`, `n8n_api_key`) se cifran con `Security::encrypt()` usando el `SECURITY_SALT` de la app. Se almacenan con prefijo `{encrypted}` + base64 en `system_settings`.

---

## 8. Capa de Servicios

### Servicios principales

| Servicio | Responsabilidad |
|---|---|
| `TicketService` | Crear tickets (email/web), convertir a compra, gestionar tags y followers |
| `ComprasService` | Crear compras (desde ticket o directas), convertir a ticket, SLA |
| `PqrsService` | Crear PQRS desde formulario publico, SLA |
| `GmailService` | OAuth2, leer/parsear/enviar emails, descargar adjuntos |
| `EmailService` | Notificaciones con plantillas HTML (nuevo, estado, comentario, respuesta) |
| `WhatsappService` | Enviar mensajes WhatsApp via Evolution API |
| `N8nService` | Webhooks a n8n para clasificacion AI |
| `S3Service` | Upload/download/delete/presigned URLs en AWS S3 |
| `SlaManagementService` | Calcular deadlines, verificar breaches, configurar plazos |
| `StatisticsService` | Queries para dashboards (por estado, prioridad, SLA, agente, tendencias) |
| `SettingsService` | CRUD de configuracion del sistema con cifrado automatico |
| `AuthorizationService` | Autorizacion centralizada basada en roles por tipo de entidad |

### Traits reutilizables

| Trait | Que hace | Usado por |
|---|---|---|
| `TicketSystemTrait` | addComment, changeStatus, logHistory | Ticket, Compras, Pqrs Services |
| `NotificationDispatcherTrait` | Despachar email + WhatsApp | Ticket, Compras, Pqrs Services |
| `GenericAttachmentTrait` | Guardar archivos (upload, binary, rutas) | Ticket, Compras, Pqrs Services |
| `EntityConversionTrait` | Copiar comentarios/adjuntos entre entidades | Ticket, Compras Services |
| `SlaAwareTrait` | Verificar SLA breaches | Compras, Pqrs Services |
| `ConfigResolutionTrait` | Resolver config en 3 niveles (constructor → cache → BD) | Email, WhatsApp Services |
| `SecureHttpTrait` | POST HTTP seguro para webhooks | N8n Service |

---

## 9. API - Endpoints

### Convenciones generales

- Agregar `.json` a la URL para respuesta JSON: `GET /tickets.json`
- Autenticacion por sesion (cookie CakePHP)
- Formato de respuesta: `{ "success": true/false, "data": {...}, "message": "..." }`
- Paginacion: parametros `page`, `limit`, `sort`, `direction`

### 9.1 Tickets

| Metodo | Ruta | Descripcion |
|---|---|---|
| GET | `/tickets` | Listar (filtros: status, priority, assignee_id, search, view) |
| GET | `/tickets/view/{id}` | Ver detalle con comentarios, adjuntos, historial, tags |
| POST | `/tickets/add-comment/{id}` | Agregar comentario (body, comment_type, attachments[], status) |
| POST | `/tickets/assign/{id}` | Asignar agente (assignee_id) |
| POST | `/tickets/change-status/{id}` | Cambiar estado |
| POST | `/tickets/change-priority/{id}` | Cambiar prioridad |
| POST | `/tickets/add-tag/{id}` | Agregar etiqueta (tag_id) |
| POST | `/tickets/remove-tag/{id}/{tagId}` | Eliminar etiqueta |
| POST | `/tickets/add-follower/{id}` | Agregar seguidor (user_id) |
| POST | `/tickets/convert-to-compra/{id}` | Convertir a compra |
| GET | `/tickets/download-attachment/{id}` | Descargar adjunto |
| GET | `/tickets/history/{id}` | Ver historial de cambios |
| GET | `/tickets/statistics` | Dashboard (filtros: date_range, start_date, end_date) |

**Acciones masivas**:

| Metodo | Ruta | Body |
|---|---|---|
| POST | `/tickets/bulk-assign` | ticket_ids[], assignee_id |
| POST | `/tickets/bulk-change-priority` | ticket_ids[], priority |
| POST | `/tickets/bulk-add-tag` | ticket_ids[], tag_id |
| POST | `/tickets/bulk-delete` | ticket_ids[] |

### 9.2 Compras

| Metodo | Ruta | Descripcion |
|---|---|---|
| GET | `/compras` | Listar (filtros: status, priority, assignee_id, search) |
| GET | `/compras/view/{id}` | Ver detalle con SLA |
| POST | `/compras/add-comment/{id}` | Agregar comentario |
| POST | `/compras/assign/{id}` | Asignar responsable |
| POST | `/compras/change-status/{id}` | Cambiar estado |
| POST | `/compras/change-priority/{id}` | Cambiar prioridad |
| GET | `/compras/download/{id}` | Descargar adjunto |
| GET | `/compras/history/{id}` | Ver historial |
| POST | `/compras/convert-to-ticket/{id}` | Convertir a ticket |
| GET | `/compras/statistics` | Dashboard |

**Bulk**: bulk-assign, bulk-change-priority, bulk-delete (misma estructura que tickets).

### 9.3 PQRS

**Rutas publicas (sin autenticacion)**:

| Metodo | Ruta | Descripcion |
|---|---|---|
| GET | `/pqrs/formulario` | Formulario publico |
| POST | `/pqrs/formulario` | Crear PQRS (type, subject, description, datos personales, adjuntos) |
| GET | `/pqrs/success/{pqrsNumber}` | Pagina de confirmacion con radicado |

**Rutas internas (con autenticacion)**:

| Metodo | Ruta | Descripcion |
|---|---|---|
| GET | `/pqrs` | Listar (filtros: status, type, priority, assignee_id, search) |
| GET | `/pqrs/view/{id}` | Ver detalle con SLA |
| POST | `/pqrs/add-comment/{id}` | Agregar comentario |
| POST | `/pqrs/assign/{id}` | Asignar agente |
| POST | `/pqrs/change-status/{id}` | Cambiar estado |
| POST | `/pqrs/change-priority/{id}` | Cambiar prioridad |
| GET | `/pqrs/download/{id}` | Descargar adjunto |
| GET | `/pqrs/history/{id}` | Ver historial |
| GET | `/pqrs/statistics` | Dashboard con metricas SLA |

**Bulk**: bulk-assign, bulk-change-priority, bulk-delete.

### 9.4 Webhooks y Admin

**n8n webhook saliente**: POST automatico a `n8n_webhook_url` al crear ticket.
**n8n callback entrante**: POST al callback URL para asignar tags.

**Rutas administrativas** (prefijo `/admin`, solo rol admin):

| Area | Rutas |
|---|---|
| Configuracion general | `GET /admin/settings` |
| Gmail OAuth | `GET /admin/settings/gmail-auth` |
| Prueba Gmail | `POST /admin/settings/test-gmail` |
| Prueba WhatsApp | `POST /admin/settings/test-whatsapp` |
| Prueba n8n | `POST /admin/settings/test-n8n` |
| Plantillas email | `GET /admin/settings/email-templates`, `edit-template/{id}`, `preview-template/{id}` |
| Usuarios | `GET /admin/settings/users`, `add-user`, `edit-user/{id}`, `deactivate-user/{id}`, `activate-user/{id}` |
| Etiquetas | `GET /admin/settings/tags`, `add-tag`, `edit-tag/{id}`, `delete-tag/{id}` |
| Organizaciones | `GET /admin/settings/organizations`, `add-organization`, `edit-organization/{id}`, `delete-organization/{id}` |
| SLA | `GET /admin/sla-management`, `POST /admin/sla-management/save`, `GET /admin/sla-management/preview` |
| Archivos config | `POST /admin/config-files/upload`, `GET /admin/config-files/download/{type}`, `POST /admin/config-files/delete/{type}` |

**Health check**: `GET /health` → JSON con estado de BD (para Docker).

---

## 10. Plantillas de Email

El sistema usa plantillas HTML almacenadas en BD con variables sustituibles:

| Template Key | Cuando se envia |
|---|---|
| `nuevo_ticket` | Se crea un ticket |
| `ticket_estado` | Cambia el estado de un ticket |
| `nuevo_comentario` | Se agrega comentario a un ticket |
| `ticket_respuesta` | Comentario + cambio de estado simultaneo |
| `nueva_compra` | Se crea una compra |
| `compra_estado` | Cambia estado de compra |
| `compra_comentario` | Comentario en compra |
| `compra_respuesta` | Respuesta unificada en compra |
| `nuevo_pqrs` | Se crea un PQRS |
| `pqrs_estado` | Cambia estado de PQRS |
| `pqrs_comentario` | Comentario en PQRS |
| `pqrs_respuesta` | Respuesta unificada en PQRS |

**Variables disponibles**: `{{ticket_number}}`, `{{subject}}`, `{{requester_name}}`, `{{status}}`, `{{agent_name}}`, etc. Se editan desde `/admin/settings/email-templates`.

---

## 11. Comandos de Consola

```bash
# Worker continuo de Gmail (Docker)
php bin/cake gmail_worker [--once]

# Importacion manual de emails
php bin/cake import_gmail [--max=50] [--query='is:unread'] [--delay=1000]

# Prueba de email
php bin/cake test_email <ticket_id>

# Migraciones
php bin/cake migrations migrate
php bin/cake migrations status
```

---

## 12. Despliegue con Docker

### Contenedores

| Servicio | Descripcion |
|---|---|
| `web` | PHP-FPM + la aplicacion CakePHP |
| `nginx` | Reverse proxy, sirve assets estaticos |
| `worker` | Gmail worker (mismo codigo, ejecuta `gmail_worker`) |

### Variables de entorno criticas

| Variable | Descripcion |
|---|---|
| `SECURITY_SALT` | Clave de cifrado (64 chars hex). Critico para descifrar credenciales. |
| `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Conexion a MySQL |
| `TRUST_PROXY` | `true` si esta detras de reverse proxy (HTTPS detection) |
| `WORKER_ENABLED` | Habilitar/deshabilitar worker de Gmail |

### Comandos de operacion

```bash
# Levantar entorno
docker compose up -d --build

# Migraciones
docker compose exec web php bin/cake.php migrations migrate

# Logs
docker compose logs -f web
docker compose logs -f worker

# Health check
curl http://localhost:8765/health
```

---

## 13. Resumen ejecutivo para la presentacion

**Mesa de Ayuda unifica tres procesos** en una plataforma web:

1. **Tickets de soporte**: Los emails llegan automaticamente como tickets gracias a la integracion con Gmail. n8n los clasifica con IA. Los agentes gestionan, responden y resuelven desde la interfaz web.

2. **Compras**: Se crean desde cero o convirtiendo un ticket. Pasan por un flujo de aprobacion (revision → aprobacion → ejecucion → completado/rechazado). Conversion bidireccional con tickets.

3. **PQRS**: Los ciudadanos/clientes radican sin necesidad de cuenta. El formulario publico genera un radicado, calcula SLA segun el tipo, y notifica al equipo de servicio al cliente.

**Notificaciones multicanal**: Email (Gmail API) + WhatsApp (Evolution API) mantienen informados a todos los involucrados.

**Trazabilidad completa**: Cada accion queda registrada en tablas de historial con quien, que, cuando y valores antes/despues.

**SLA automatico**: Plazos calculados al crear cada entidad, con monitoreo continuo y alertas visuales en dashboards.
