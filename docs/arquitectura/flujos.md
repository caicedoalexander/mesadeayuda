# Flujos de Negocio

## Ciclo de Vida del Ticket

```
nuevo → abierto → pendiente → resuelto
                                 │
                                 └──→ convertido
                                  (conversion a compra)
```

| Estado | Descripcion |
|---|---|
| `nuevo` | Recien creado (via email, web o API). Sin asignar. |
| `abierto` | Asignado a un agente. Esperando accion del agente. |
| `pendiente` | Esperando respuesta del solicitante o de un tercero. |
| `resuelto` | Solucion aplicada. |
| `convertido` | Convertido a compra. El ticket se marca como convertido y se crea una compra vinculada. |

**Numeracion**: `TKT-YYYY-NNNNN` (ejemplo: TKT-2026-00042)

**Prioridades**: `baja`, `media`, `alta`, `urgente`

**Canales de entrada**: `email` (Gmail), `web` (manual), `api` (futuro)

---

## Ciclo de Vida de Compra

```
nuevo → en_revision → aprobado → en_proceso → completado
                  │
                  └──→ rechazado
```

| Estado | Descripcion |
|---|---|
| `nuevo` | Solicitud de compra recien creada. |
| `en_revision` | En revision por el area de compras. |
| `aprobado` | Compra aprobada. Pendiente de ejecucion. |
| `en_proceso` | Compra en proceso de adquisicion. |
| `completado` | Compra finalizada exitosamente. |
| `rechazado` | Compra rechazada. |

**Nota**: La conversion a ticket (`ComprasService::convertToTicket()`) no cambia el estado de la compra a `convertido` ya que ese valor no existe en el enum de la BD. La conversion crea un ticket vinculado y se registra en el historial.

**Numeracion**: `CPR-YYYY-NNNNN` (ejemplo: CPR-2026-00015)

**Campos SLA**:
- `first_response_sla_due` - Plazo para primera respuesta
- `resolution_sla_due` - Plazo para resolucion
- `sla_due_date` - Campo legacy (fallback)

---

## Ciclo de Vida de PQRS

```
nuevo → en_revision → en_proceso → resuelto → cerrado
```

| Estado | Descripcion |
|---|---|
| `nuevo` | PQRS recien creado desde formulario publico. |
| `en_revision` | En revision por servicio al cliente. |
| `en_proceso` | En proceso de atencion. |
| `resuelto` | Resuelto. Pendiente de cierre. |
| `cerrado` | Cerrado definitivamente. |

**Numeracion**: `PQRS-YYYY-NNNNN` (ejemplo: PQRS-2026-00008)

**Tipos**: `peticion`, `queja`, `reclamo`, `sugerencia`

**Caracteristica especial**: No requiere autenticacion. Los datos del solicitante se almacenan directamente en la tabla (nombre, email, telefono). Se registra IP y User-Agent para trazabilidad.

---

## Conversion Ticket <-> Compra

### Ticket a Compra

1. Agente/admin selecciona "Convertir a Compra" en un ticket
2. `TicketService::convertToCompra()` ejecuta:
   - Crea nueva compra con datos del ticket (subject, description, requester, etc.)
   - Calcula SLA para la compra via `SlaManagementService`
   - Copia comentarios del ticket como comentarios de compra (via `EntityConversionTrait`)
   - Copia adjuntos del ticket como adjuntos de compra
   - Cambia estado del ticket a `convertido`
   - Registra en historial del ticket: "Convertido a compra CPR-YYYY-NNNNN"
   - Guarda `original_ticket_number` en la compra
3. Despacha notificaciones de creacion de compra (email + WhatsApp)

### Compra a Ticket

1. Personal de compras selecciona "Convertir a Ticket"
2. `ComprasService::convertToTicket()` ejecuta:
   - Crea nuevo ticket con datos de la compra
   - Copia comentarios y adjuntos
   - Cambia estado de la compra a `convertido`
   - Registra en historial de la compra
3. Despacha notificaciones de creacion de ticket

---

## Importacion de Gmail

### Flujo del Worker

```
GmailWorkerCommand (loop infinito)
  │
  ├── Verifica conectividad a BD (backoff exponencial en startup)
  ├── Verifica que Gmail este configurado en SystemSettings
  │
  └── Cada N minutos (configurable, default 5):
      │
      ├── GmailService::getMessages('is:unread')
      │
      └── Por cada mensaje:
          │
          ├── GmailService::parseMessage(messageId)
          │   ├── Extrae headers (From, To, Cc, Subject, Message-ID, In-Reply-To)
          │   ├── Extrae cuerpo (HTML + texto plano)
          │   └── Lista adjuntos e imagenes inline
          │
          ├── Deteccion de auto-replies y notificaciones del sistema
          │   ├── isAutoReply() → headers Auto-Submitted, X-Auto-Response
          │   └── isSystemNotification() → detecta respuestas a emails enviados por el sistema
          │   (Si es auto-reply o notificacion del sistema → skip, mark as read)
          │
          ├── Busca hilo existente por gmail_thread_id
          │   ├── Si existe ticket → TicketService::createCommentFromEmail()
          │   └── Si no existe → TicketService::createFromEmail()
          │       ├── findOrCreateUser() (busca o crea usuario por email)
          │       ├── Sanitiza HTML (prevencion XSS)
          │       ├── Genera ticket_number (TKT-YYYY-NNNNN)
          │       └── Guarda ticket
          │
          ├── processEmailAttachments() → descarga adjuntos via Gmail API → S3 o local
          │
          ├── GmailService::markAsRead(messageId)
          │
          └── Despacha notificaciones:
              ├── EmailService → notificacion de nuevo ticket
              ├── WhatsappService → notificacion al grupo de tickets
              └── N8nService → webhook ticket.created (clasificacion AI)
```

### Backoff exponencial en errores
- Error consecutivo: espera 60s iniciales, duplica hasta 600s max
- Despues de demasiados errores consecutivos: el worker se detiene
- Senales SIGTERM/SIGINT: shutdown graceful

### Comando manual
```bash
php bin/cake import_gmail --max=50 --query='is:unread' --delay=1000
```

---

## Formulario Publico PQRS

```
Usuario externo
  │
  ├── Accede a /pqrs/formulario (sin autenticacion)
  │
  ├── Completa formulario:
  │   ├── Tipo (peticion/queja/reclamo/sugerencia)
  │   ├── Datos personales (nombre, email, telefono)
  │   ├── Asunto y descripcion
  │   └── Adjuntos opcionales
  │
  ├── PqrsController::create() → PqrsService::createFromForm()
  │   ├── Registra IP y User-Agent
  │   ├── Genera pqrs_number (PQRS-YYYY-NNNNN)
  │   ├── Calcula SLA por tipo via SlaManagementService
  │   ├── Guarda adjuntos
  │   └── Despacha notificaciones:
  │       ├── EmailService → notificacion al equipo + confirmacion al solicitante
  │       └── WhatsappService → notificacion al grupo de PQRS
  │
  └── Redirige a /pqrs/success/{pqrsNumber}
      └── Muestra numero de radicado al usuario
```

---

## Gestion de SLA

### Configuracion por tipo PQRS

| Tipo | Primera respuesta (dias) | Resolucion (dias) |
|---|---|---|
| Peticion | 2 | 5 |
| Queja | 1 | 3 |
| Reclamo | 1 | 3 |
| Sugerencia | 3 | 7 |

### Configuracion para Compras

| Metrica | Dias |
|---|---|
| Primera respuesta | 1 |
| Resolucion | 3 |

### Calculo de deadlines

Al crear una entidad, `SlaManagementService` calcula:
- `first_response_sla_due` = fecha de creacion + dias de primera respuesta
- `resolution_sla_due` = fecha de creacion + dias de resolucion

### Estados de SLA

| Estado | Condicion |
|---|---|
| `met` | Respondido/resuelto dentro del plazo |
| `breached` | Plazo vencido sin respuesta/resolucion |
| `approaching` | Proximo a vencer (< 24 horas) |
| `on_track` | Dentro del plazo con margen |
| `none` | Sin SLA configurado |

### Deteccion de breaches

- `SlaManagementService::isFirstResponseSlaBreached()` - Verifica si la primera respuesta excedio el plazo
- `SlaManagementService::isResolutionSlaBreached()` - Verifica si la resolucion excedio el plazo
- `getBreachedSLACompras()` / `getBreachedSLAPqrs()` - Consultas para dashboards

---

## Flujo de Respuesta (handleResponse)

Cuando un agente responde a un ticket/compra/PQRS, el controlador delega a `$service->handleResponse()` del servicio correspondiente:

```
Controller → TicketSystemActionsTrait::addEntityComment()
  └── $service->handleResponse($entityId, $userId, $data, $files)
      │
      ├── 1. Crea comentario (via TicketSystemTrait::addComment)
      ├── 2. Guarda adjuntos (si los hay)
      ├── 3. Cambia estado (si se solicito cambio)
      │   └── Registra en historial
      └── 4. Despacha notificaciones (via TicketSystemTrait::sendResponseNotifications):
          ├── Comentario + estado → email tipo "respuesta" (unificado)
          ├── Solo comentario → email tipo "comentario"
          └── Solo estado → email tipo "estado"
          (WhatsApp solo se envia en creacion, no en actualizaciones)
```

Cada servicio (TicketService, ComprasService, PqrsService) implementa su propio `handleResponse()` usando los metodos compartidos de `TicketSystemTrait`.
