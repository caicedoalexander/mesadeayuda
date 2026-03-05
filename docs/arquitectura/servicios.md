# Capa de Servicios

Los servicios (`src/Service/`) contienen la logica de negocio del sistema. Se inyectan en los controladores como propiedades tipadas.

## Servicios Principales

### TicketService

Logica central del modulo de tickets.

**Ubicacion**: `src/Service/TicketService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `handleResponse(int $entityId, int $userId, array $data, array $files)` | Procesa respuesta completa (comentario + adjuntos + estado + notificaciones) |
| `createFromEmail(array $emailData)` | Crea ticket desde email parseado por Gmail |
| `createCommentFromEmail(Ticket $ticket, array $emailData)` | Agrega comentario a ticket existente desde email |
| `findOrCreateUser(string $email, string $name)` | Busca o crea usuario por email (para importacion Gmail) |
| `isEmailInTicketRecipients(Ticket $ticket, string $email)` | Valida que el remitente sea participante del ticket |
| `processEmailAttachments(Ticket $ticket, array $attachments, int $userId, ?int $commentId)` | Descarga y guarda adjuntos de email |
| `saveUploadedFile(Ticket $ticket, UploadedFileInterface $file, ?int $commentId, ?int $userId)` | Guarda archivo subido desde formulario |
| `convertToCompra(Ticket $ticket, int $userId, ComprasService $service)` | Convierte ticket a compra (copia comentarios y adjuntos) |
| `createFromCompra(Compra $compra, array $data)` | Crea ticket desde compra |
| `addTag(int $ticketId, int $tagId)` | Agrega etiqueta a ticket |
| `removeTag(int $ticketId, int $tagId)` | Elimina etiqueta de ticket |
| `addFollower(int $ticketId, int $userId)` | Agrega seguidor al ticket |
| `copyCompraData(Compra $compra, Ticket $ticket)` | Copia comentarios/adjuntos de compra a ticket |

**Traits usados**: TicketSystemTrait, NotificationDispatcherTrait, GenericAttachmentTrait, EntityConversionTrait

**Dependencias**: GmailService, EmailService, WhatsappService, N8nService (lazy-loaded)

---

### ComprasService

Logica del modulo de compras con workflow de aprobacion.

**Ubicacion**: `src/Service/ComprasService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `handleResponse(int $entityId, int $userId, array $data, array $files)` | Procesa respuesta completa (comentario + adjuntos + estado + notificaciones) |
| `convertToTicket(Compra $compra, int $userId, TicketService $service)` | Convierte compra a ticket |
| `createFromTicket(Ticket $ticket, array $data)` | Crea compra desde ticket con calculo de SLA |
| `copyTicketData(Ticket $ticket, Compra $compra)` | Copia comentarios/adjuntos de ticket a compra |
| `isSLABreached(Compra $compra)` | Verifica si SLA de resolucion fue excedido |
| `getResolutionSlaDue(Entity $entity)` | Obtiene fecha limite SLA con soporte de campo legacy |
| `getBreachedSLACompras()` | Consulta compras con SLA vencido |
| `saveUploadedFile(Compra $compra, UploadedFileInterface $file, ?int $commentId, ?int $userId)` | Guarda archivo adjunto |

**Traits usados**: TicketSystemTrait, NotificationDispatcherTrait, GenericAttachmentTrait, EntityConversionTrait, SlaAwareTrait

**Dependencias**: TicketService, EmailService, WhatsappService, SlaManagementService

---

### PqrsService

Logica del modulo PQRS, incluyendo creacion desde formulario publico.

**Ubicacion**: `src/Service/PqrsService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `handleResponse(int $entityId, int $userId, array $data, array $files)` | Procesa respuesta completa (comentario + adjuntos + estado + notificaciones) |
| `createFromForm(array $formData, array $files)` | Crea PQRS desde formulario publico (sin auth) con SLA |
| `saveUploadedFile(Pqr $pqrs, UploadedFileInterface $file, ?int $commentId, ?int $userId)` | Guarda archivo adjunto |
| `getBreachedSLAPqrs()` | Consulta PQRS con SLA vencido |

**Traits usados**: TicketSystemTrait, NotificationDispatcherTrait, GenericAttachmentTrait, SlaAwareTrait

**Dependencias**: EmailService, WhatsappService, SlaManagementService

---

### GmailService

Integracion completa con Gmail API para importacion y envio de emails.

**Ubicacion**: `src/Service/GmailService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `loadConfigFromDatabase()` | Carga configuracion Gmail desde BD con cache y descifrado |
| `getAuthUrl()` | Genera URL de autorizacion OAuth2 |
| `authenticate(string $code)` | Intercambia codigo de autorizacion por tokens |
| `getMessages(string $query, int $maxResults)` | Obtiene IDs de mensajes (ej: 'is:unread') |
| `parseMessage(string $messageId)` | Parsea mensaje completo: headers, cuerpo, adjuntos |
| `downloadAttachment(string $messageId, string $attachmentId)` | Descarga adjunto binario |
| `markAsRead(string $messageId)` | Marca mensaje como leido |
| `isAutoReply(array $headers)` | Detecta auto-respuestas (Out of Office) |
| `isSystemNotification(array $headers)` | Detecta respuestas a notificaciones del sistema |
| `sendEmail($to, string $subject, string $htmlBody, array $attachments, array $options)` | Envia email via Gmail API con MIME |
| `extractEmailAddress(string $emailString)` | Extrae email de "Nombre <email@example.com>" |
| `parseRecipients(string $recipientsHeader)` | Parsea headers To/Cc |

**Configuracion** (SystemSettings): `SettingKeys::GMAIL_CLIENT_SECRET_PATH`, `SettingKeys::GMAIL_REFRESH_TOKEN` (cifrado), `SettingKeys::GMAIL_USER_EMAIL`

---

### EmailService

Servicio unificado de notificaciones por email usando plantillas de BD.

**Ubicacion**: `src/Service/EmailService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `sendNewTicketNotification(Ticket $ticket)` | Notificacion de nuevo ticket |
| `sendStatusChangeNotification(Ticket $ticket, string $oldStatus, string $newStatus)` | Cambio de estado |
| `sendNewCommentNotification(Ticket $ticket, TicketComment $comment, ...)` | Nuevo comentario |
| `sendTicketResponseNotification(Ticket $ticket, TicketComment $comment, ...)` | Respuesta unificada (comentario + estado) |
| Metodos analogos para PQRS y Compras | Misma estructura por modulo |
| `sendGenericTemplateEmail(string $entityType, string $templateKey, Entity $entity, ...)` | Envio generico con plantilla |

**Sistema de plantillas**: Carga plantillas de `email_templates` con sustitucion de variables `{{ticket_number}}`, `{{subject}}`, `{{requester_name}}`, etc. Soporta imagenes de perfil de agente, secciones de cambio de estado y listas de adjuntos.

**Dependencias**: GmailService (envio via Gmail API), ConfigResolutionTrait

---

### WhatsappService

Notificaciones por WhatsApp via Evolution API.

**Ubicacion**: `src/Service/WhatsappService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `sendMessage(string $number, string $text)` | Envia mensaje WhatsApp |
| `sendNewTicketNotification(Ticket $ticket)` | Notifica grupo de tickets |
| `sendNewPqrsNotification(Pqr $pqrs)` | Notifica grupo PQRS |
| `sendNewCompraNotification(Compra $compra)` | Notifica grupo compras |
| `testConnection(string $module)` | Prueba conectividad por modulo |

**Configuracion** (SystemSettings): Todas las claves WhatsApp estan centralizadas en `SettingKeys::WHATSAPP_*` — `WHATSAPP_ENABLED`, `WHATSAPP_API_URL`, `WHATSAPP_API_KEY` (cifrado), `WHATSAPP_INSTANCE_NAME`, `WHATSAPP_TICKETS_NUMBER`, `WHATSAPP_PQRS_NUMBER`, `WHATSAPP_COMPRAS_NUMBER`

**Resolucion de configuracion en 3 niveles**:
1. systemConfig del constructor (mas rapido)
2. Cache principal 'system_settings' (desde AppController)
3. Consulta directa a BD con cache propio del servicio

---

### N8nService

Integracion con n8n para clasificacion AI de tickets.

**Ubicacion**: `src/Service/N8nService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `sendTicketCreatedWebhook(Ticket $ticket)` | Envia datos del ticket a n8n para clasificacion |
| `testConnection()` | Prueba conectividad con n8n |

**Metodos privados**: `buildTicketPayload(Ticket $ticket)` (construye payload), `sendWebhook(string $url, array $payload)` (HTTP POST seguro), `getCallbackUrl()` (URL de callback, actualmente placeholder)

**Configuracion** (SystemSettings): Todas las claves n8n estan centralizadas en `SettingKeys::N8N_*` — `N8N_ENABLED`, `N8N_WEBHOOK_URL`, `N8N_API_KEY` (cifrado), `N8N_SEND_TAGS_LIST`, `N8N_TIMEOUT`

---

### SlaManagementService

Gestion centralizada de SLA para todos los modulos.

**Ubicacion**: `src/Service/SlaManagementService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `getPqrsSlaSettings(string $type)` | Obtiene dias de SLA para tipo PQRS |
| `getComprasSlaSettings()` | Obtiene dias de SLA para compras |
| `calculatePqrsSlaDeadlines(string $type, ?DateTime $createdDate)` | Calcula fechas limite PQRS |
| `calculateComprasSlaDeadlines(?DateTime $createdDate)` | Calcula fechas limite compras |
| `isFirstResponseSlaBreached(...)` | Verifica breach de primera respuesta |
| `isResolutionSlaBreached(...)` | Verifica breach de resolucion |
| `getSlaStatus(...)` | Retorna estado: met, breached, approaching, on_track, none |
| `getAllSlaConfigurations()` | Para interfaz admin |
| `saveAllSettings(array $data)` | Guarda configuracion SLA en lote |

**Cache**: Usa clave `sla_settings`, se limpia automaticamente al guardar cambios.

---

### StatisticsService

Queries de analytics para dashboards de cada modulo.

**Ubicacion**: `src/Service/StatisticsService.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `getTicketStats(array $filters)` | Distribucion por estado, prioridad, canal; tiempos de respuesta/resolucion |
| `getTicketAgentPerformance(array $filters)` | Rendimiento por agente |
| `getTicketTrendData(int $days)` | Datos para grafica temporal |
| `getRecentActivity(int $limit)` | Top solicitantes, estadisticas de comentarios |
| `getPqrsStats(array $filters)` | Estadisticas PQRS por tipo, estado, prioridad |
| `getPqrsSlaMetrics(array $filters)` | Metricas de cumplimiento SLA |
| `getComprasStats(array $filters)` | Estadisticas de compras con metricas SLA y aprobacion |
| Metodos `*TrendData(int $days)` | Datos de tendencias para graficas |

**Filtros soportados**: `date_range` ('all', '30days', '7days', 'today', 'custom'), `start_date`, `end_date`

**Cache**: 5 minutos via metodos privados internos (parseDateFilters, buildBaseQuery, etc.)

---

### S3Service

Almacenamiento de archivos en AWS S3 (opcional).

**Ubicacion**: `src/Service/S3Service.php`

**Metodos principales**:

| Metodo | Descripcion |
|---|---|
| `isEnabled()` | Verifica si S3 esta configurado |
| `uploadFile(string $localPath, string $s3Path, string $contentType)` | Sube archivo a S3 |
| `downloadFile(string $s3Path, string $localPath)` | Descarga de S3 |
| `deleteFile(string $s3Path)` | Elimina de S3 |
| `getPresignedUrl(string $s3Path, int $expirationMinutes)` | Genera URL temporal de acceso |
| `getFileStream(string $s3Path)` | Stream directo del archivo |

**Configuracion** (app.php/app_local.php): `AWS_S3_ENABLED`, `AWS_S3_BUCKET`, `AWS_S3_REGION`, `AWS_S3_KEY`, `AWS_S3_SECRET`

---

### SettingsService

Gestion de configuracion del sistema con cifrado automatico.

**Ubicacion**: `src/Service/SettingsService.php`

| Metodo | Descripcion |
|---|---|
| `saveSetting(string $key, string $value)` | Guarda con cifrado automatico para claves sensibles |
| `loadAll()` | Carga todas las configuraciones con descifrado y cache |

**Claves cifradas automaticamente**: `SettingKeys::GMAIL_REFRESH_TOKEN`, `SettingKeys::WHATSAPP_API_KEY`, `SettingKeys::N8N_API_KEY`

Al guardar cualquier configuracion, limpia los caches: `system_settings`, `whatsapp_settings`, `n8n_settings`, `gmail_settings`, `sla_settings`

---

### AuthorizationService

Autorizacion centralizada basada en roles para acciones sobre entidades.

**Ubicacion**: `src/Service/AuthorizationService.php`

| Metodo | Descripcion |
|---|---|
| `isAssignmentDisabled(string $entityType, $user)` | Determina si el usuario puede asignar entidades del tipo dado |

**Roles por tipo de entidad**:
- ticket → admin, agent
- compra → admin, compras
- pqrs → admin, servicio_cliente

---

### ProfileImageService

Gestion de imagenes de perfil de usuario con soporte dual S3/local.

**Ubicacion**: `src/Service/ProfileImageService.php`

| Metodo | Descripcion |
|---|---|
| `saveProfileImage(int $userId, UploadedFileInterface $file)` | Sube imagen con validacion MIME, tamano (max 2MB) y verificacion finfo |
| `deleteProfileImage(string $filename)` | Elimina imagen anterior (S3 o local) |
| `getProfileImageUrl(?string $profileImage)` | Resuelve URL con fallback a avatar por defecto |

**Validaciones**: Extensiones permitidas (JPG, PNG, GIF, WEBP), verificacion de MIME real via `finfo`, limite 2MB. Genera nombre unico con UUID.

---

### NumberGenerationService

Generacion centralizada de numeros secuenciales para entidades.

**Ubicacion**: `src/Service/NumberGenerationService.php`

| Metodo | Descripcion |
|---|---|
| `generate(string $entityType)` | Genera siguiente numero secuencial (TKT-YYYY-NNNNN, CPR-YYYY-NNNNN, PQRS-YYYY-NNNNN) |

**Tipos configurados**: `ticket` (prefijo TKT), `compra` (prefijo CPR), `pqrs` (prefijo PQRS). Consulta el ultimo numero del ano actual y genera el siguiente.

---

### EmailTemplateRenderer

Carga y renderiza plantillas de email desde BD con sustitucion de variables `{{variable}}`.

**Ubicacion**: `src/Service/EmailTemplateRenderer.php`

| Metodo | Descripcion |
|---|---|
| `preloadTemplates()` | Pre-carga todas las plantillas activas en cache (evita N+1) |
| `getTemplate(string $templateKey)` | Obtiene plantilla por clave (usa cache si preloaded) |
| `render(string $templateString, array $variables)` | Reemplaza placeholders `{{key}}` por valores |
| `renderTemplate(string $templateKey, array $variables)` | Carga plantilla y renderiza subject + body en una llamada |
| `getSystemVariables()` | Variables globales: system_title, current_year |
| `clearCache()` | Limpia cache en memoria |

**Traits usados**: ConfigResolutionTrait

---

## Traits Reutilizables

Los traits (`src/Service/Traits/`) encapsulan comportamientos compartidos entre servicios.

### TicketSystemTrait

Operaciones comunes: agregar comentarios, cambiar estado, registrar historial, manejo de respuestas.

| Metodo | Descripcion |
|---|---|
| `addComment(...)` | Crea comentario en cualquier entidad (ticket/compra/pqrs) |
| `changeStatus(...)` | Cambia estado con validacion y registro en historial |
| `logHistory(...)` | Registra cambio en tabla de historial correspondiente |
| `sendResponseNotifications(...)` | Despacha notificaciones segun tipo de accion (comentario, estado, o ambos) |
| `buildResponseResult(...)` | Construye array de resultado estandar para handleResponse |
| `decodeEmailRecipients(...)` | Decodifica destinatarios email (To/Cc) del formulario de respuesta |

### NotificationDispatcherTrait

Despacho de notificaciones multi-canal.

| Metodo | Descripcion |
|---|---|
| `dispatchCreationNotifications(...)` | Envia email + WhatsApp al crear entidad |
| `dispatchUpdateNotifications(...)` | Envia email al actualizar (sin WhatsApp) |

### GenericAttachmentTrait

Gestion unificada de archivos adjuntos.

| Metodo | Descripcion |
|---|---|
| `saveGenericUploadedFile(...)` | Guarda archivo subido (local o S3) |
| `saveAttachmentFromBinary(...)` | Guarda archivo desde datos binarios (Gmail) |
| `getFullPath(...)` | Resuelve ruta completa del archivo |

### EntityConversionTrait

Conversion entre tipos de entidad (Ticket <-> Compra).

| Metodo | Descripcion |
|---|---|
| `copyComments(...)` | Copia comentarios de una entidad a otra |
| `copyAttachments(...)` | Copia adjuntos de una entidad a otra |
| `markAsConverted(...)` | Marca entidad origen como "convertido" |

### SlaAwareTrait

Verificacion de cumplimiento de SLA.

| Metodo | Descripcion |
|---|---|
| `isFirstResponseSLABreached(...)` | Verifica breach de primera respuesta |
| `isResolutionSLABreached(...)` | Verifica breach de resolucion |
| `getSlaStatus(...)` | Retorna badge de estado SLA |

### ConfigResolutionTrait

Resolucion de configuracion en cascada (3 niveles).

| Metodo | Descripcion |
|---|---|
| `resolveSettingValue(...)` | Resuelve valor de una configuracion |
| `resolveSettings(...)` | Resuelve multiples configuraciones |
| `resolveSettingsBatch(...)` | Resolucion batch optimizada |

**Niveles**: Constructor config → Cache principal → Consulta BD con cache propio

### SecureHttpTrait

Peticiones HTTP seguras para webhooks.

| Metodo | Descripcion |
|---|---|
| `secureCurlPost(...)` | POST seguro con timeout, verificacion SSL, headers |

---

## Comandos de Consola

### ImportGmailCommand

Importacion manual de Gmail (ejecucion unica).

```bash
php bin/cake import_gmail [--max=50] [--query='is:unread'] [--delay=1000]
```

### GmailWorkerCommand

Worker continuo para polling de Gmail (Docker).

```bash
php bin/cake gmail_worker [--once]
```

Caracteristicas:
- Loop infinito con sleep interruptible (incrementos de 1 segundo)
- Backoff exponencial en errores (60s-600s)
- Shutdown graceful via senales (SIGTERM/SIGINT)
- Espera a conectividad de BD en startup (10 intentos, 5s entre cada uno)
- **Trigger file**: Detecta `tmp/gmail_worker_trigger` (constante `TRIGGER_FILE`) durante el sleep para ejecucion inmediata tras autorizacion OAuth
- Intervalo configurable via `SettingKeys::GMAIL_CHECK_INTERVAL` (default: 5 min)

### start-worker (script)

Gestiona el worker de Gmail como proceso de fondo. Permite iniciar, detener y verificar el estado del worker sin depender de Docker.

**Ubicacion**: `bin/start-worker`

```bash
bin/start-worker            # Inicia el worker en background
bin/start-worker stop       # Detiene el worker
bin/start-worker status     # Verifica si esta corriendo
bin/start-worker restart    # Reinicia el worker
```

- Usa `nohup` para que el proceso sobreviva al cerrar la terminal
- PID guardado en `tmp/gmail_worker.pid`
- Logs en `logs/gmail_worker.log`
- Detecta si ya hay un worker corriendo para evitar duplicados
- Si falla por permisos: `chmod +x bin/start-worker` o ejecutar con `bash bin/start-worker`

### TestEmailCommand

Prueba del sistema de notificaciones.

```bash
php bin/cake test_email <ticket_id>
```

---

## Clases de Utilidad

### SettingKeys (`src/Utility/SettingKeys.php`)

Centraliza las claves de configuracion del sistema como constantes tipadas. Elimina strings duplicados que antes aparecian en 4-6 archivos por cada clave.

**Grupos de constantes**: `SYSTEM_*`, `GMAIL_*`, `WHATSAPP_*`, `N8N_*`

**Uso tipico**:

```php
use App\Utility\SettingKeys;

// Antes: $this->getSettingValue('gmail_refresh_token')
// Ahora:
$this->getSettingValue(SettingKeys::GMAIL_REFRESH_TOKEN)

// Antes: ->where(['setting_key' => 'n8n_webhook_url'])
// Ahora:
->where(['setting_key' => SettingKeys::N8N_WEBHOOK_URL])
```

### ValidationConstants (`src/Utility/ValidationConstants.php`)

Constantes de validacion para entidades, ademas de roles, cache keys y defaults del sistema.

**Constantes de roles**:

| Constante | Valor | Uso |
|---|---|---|
| `ROLE_ADMIN` | 'admin' | Comparaciones de rol en controllers y views |
| `ROLE_AGENT` | 'agent' | Comparaciones de rol |
| `ROLE_COMPRAS` | 'compras' | Comparaciones de rol |
| `ROLE_SERVICIO_CLIENTE` | 'servicio_cliente' | Comparaciones de rol |
| `ROLE_REQUESTER` | 'requester' | Comparaciones de rol |
| `ROLES` | array de todos | Validacion en UsersTable |
| `STAFF_ROLES` | admin + agent + compras + servicio_cliente | Filtros de usuarios internos |

**Constantes de cache y defaults**:

| Constante | Valor | Uso |
|---|---|---|
| `CACHE_SETTINGS` | 'system_settings' | Clave de cache principal |
| `CACHE_CONFIG` | '_cake_core_' | Configuracion de cache de CakePHP |
| `DEFAULT_SYSTEM_TITLE` | 'Mesa de Ayuda' | Titulo por defecto del sistema |

**Constantes de status individuales**: `STATUS_NUEVO`, `STATUS_ABIERTO`, `STATUS_EN_PROGRESO`, `STATUS_PENDIENTE`, `STATUS_RESUELTO`, `STATUS_CERRADO`, `STATUS_EN_REVISION`, `STATUS_APROBADO`, `STATUS_EN_PROCESO`, `STATUS_COMPLETADO`, `STATUS_RECHAZADO`

### EntityType (`src/Utility/EntityType.php`)

Enum PHP 8.1 que centraliza mapeos de tipo de entidad. Elimina match/switch duplicados en 15+ archivos.

| Caso | Valor | Metodos |
|---|---|---|
| `TICKET` | 'ticket' | tableName(), foreignKey(), commentsTable(), etc. |
| `PQRS` | 'pqrs' | Mismos metodos con valores correspondientes |
| `COMPRA` | 'compra' | Mismos metodos con valores correspondientes |

Metodos: `fromSource()`, `tableName()`, `commentsTable()`, `historyTable()`, `attachmentsTable()`, `tagsTable()`, `foreignKey()`, `commentForeignKey()`, `numberField()`, `commentsAssociation()`, `attachmentsAssociation()`, `s3Prefix()`, `uploadBasePath()`, `uploadedByField()`, `whatsappNumberKey()`, `label()`, `getNumber()`
