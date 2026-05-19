# Diseño · Fase 1 — Resolución audit n8n WhatsApp (boundary n8n ↔ backend)

- **Fecha**: 2026-05-19
- **Audit origen**: `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md`
- **Alcance**: Puntos críticos #2 (whatsapp/import), #3 (tickets/tags) y #9 (decisión Evolution API) del audit.
- **Fases siguientes** (fuera de alcance, ver §11): #1, #4, #5, #6, #7, #8, #10.

---

## 1. Objetivo

Cerrar la frontera entre el workflow n8n y el backend CakePHP con dos endpoints HTTP estables, autenticados y testeados, que reemplacen:

1. **Email→Gmail Import** como mecanismo de creación de tickets desde WhatsApp.
2. **INSERT directo a `tickets_tags`** como mecanismo de auto-tagging.

Y resolver formalmente la inconsistencia entre Evolution API y Meta Cloud API a nivel de documentación.

## 2. No-objetivos (explícitos)

- No se modifica el workflow n8n en esta fase (eso es Fase 2). Solo se entregan los contratos HTTP que consumirá.
- No se migra el outbound del bot a Evolution API. La migración ocurre cuando se separe el workflow.
- No se borra ni renombra código existente en `WhatsappService` ni en `WebhooksController::gmailImport()`.
- No se introduce borrado de tags vía webhook (`DELETE`).

## 3. Componente A — `POST /webhooks/whatsapp/import`

### 3.1 Contrato HTTP

```
POST /webhooks/whatsapp/import
Headers:
  X-Webhook-Token: <token>
  Content-Type:    application/json

Body (JSON):
{
  "message_id":   "wamid.HBgM…",          // string, requerido, idempotency key
  "phone_number": "+573001234567",         // string E.164, requerido
  "contact_name": "Ana Pérez",             // string, opcional, ≤ 120
  "subject":      "Impresora del piso 3",  // string, requerido, ≤ 200
  "description":  "Desde ayer no imprime…", // string, requerido, ≤ 65535
  "attachments": [                          // array, opcional, ≤ 10 items
    {
      "url":      "https://media.example/abc",  // string, requerido
      "filename": "foto.jpg",                   // string, requerido, ≤ 255
      "mime":     "image/jpeg",                 // string, requerido
      "size":     12345                         // int, requerido, ≤ GenericAttachmentTrait::MAX_FILE_SIZE
    }
  ]
}
```

### 3.2 Respuestas

| Código | Body | Caso |
|---|---|---|
| `200` | `{ok:true, ticket_id, ticket_number, created:true}` | Ticket creado |
| `200` | `{ok:true, ticket_id, ticket_number, created:false}` | `message_id` ya importado (idempotente) |
| `400` | `{ok:false, error:"invalid_payload", details:[…]}` | Validación falla |
| `401` | `{ok:false, error:"invalid_token"}` | Token inválido o expirado |
| `409` | `{ok:false, error:"already_running"}` | Lock por `message_id` activo |
| `413` | `{ok:false, error:"attachment_too_large"}` | Suma de adjuntos excede límite |
| `500` | `{ok:false, error:"ingest_failed"}` | Error interno (loggeado) |
| `503` | `{ok:false, error:"not_configured"}` | WhatsApp deshabilitado |

Nota: 401, 409, 413 y 503 no encolan rate-limit (mismo criterio que `gmailImport`).

### 3.3 Componentes nuevos

| Archivo | Rol |
|---|---|
| `src/Service/Dto/WhatsappIngestPayload.php` | VO inmutable que valida el body (constructor named args + `fromRequest()`); incluye normalización E.164 del teléfono. |
| `src/Service/TicketIngestionService.php` (método nuevo) | `createFromWhatsapp(WhatsappIngestPayload $payload): ?Ticket` — sibling de `createFromEmail`. |
| `src/Model/Entity/Ticket.php` (método nuevo) | `fromWhatsappIngest(...)` — factory paralelo a `fromEmailIngest`. |
| `src/Controller/WebhooksController.php` (método nuevo) | `whatsappImport(): Response`. |
| `config/Migrations/YYYYMMDDHHMMSS_AddWhatsappMessageIdToTickets.php` | Columna + índice. |
| `src/Constants/SettingKeys.php` (constante nueva) | `WEBHOOK_WHATSAPP_IMPORT_TOKEN`. |
| `src/Constants/CacheConstants.php` (constante nueva) | `WEBHOOK_WHATSAPP_PREVIOUS_TOKEN`. |

### 3.4 Migración de BD

```php
$this->table('tickets')
    ->addColumn('whatsapp_message_id', 'string', [
        'limit' => 120,
        'null' => true,
        'default' => null,
        'after' => 'gmail_thread_id',
    ])
    ->addIndex(['whatsapp_message_id'], [
        'name' => 'idx_tickets_whatsapp_message_id',
        'unique' => true,
    ])
    ->update();
```

Reversible (`->removeIndex()` + `->removeColumn()` en `down()`).

### 3.5 Flujo de ejecución (`whatsappImport()`)

```
1. allowMethod(['POST'])
2. verifyToken() → 401 si falla
3. Cache::add('whatsapp_import:'.message_id, 1, 60s) → 409 si ya existe
4. WhatsappIngestPayload::fromRequest($this->request) → 400 si validación falla
5. Resolver SystemConfig::fromSettings(); si whatsapp_enabled = 0 → 503
6. TicketIngestionService::createFromWhatsapp(payload)
     a. Dedupe: tickets.find(whatsapp_message_id = $payload->messageId)
        - si existe → retorna existing, controller responde 200 created:false
     b. Resolver requester: Users.find(phone = $payload->phoneNumber)
        - si no existe → crear User con role=requester, name=contact_name ?? phone_number
     c. NumberGenerationService::allocate()
     d. Ticket::fromWhatsappIngest(number, requesterId, subject, sanitizedDescription,
                                    sourceWhatsapp=phone_number, whatsappMessageId)
        - channel = CHANNEL_WHATSAPP
        - description pasa por HtmlSanitizerTrait::sanitizeHtml() (igual que email)
     e. Transacción:
         - tickets->saveOrFail($ticket)
         - Por cada adjunto: GenericAttachmentTrait::downloadAndStore($url, $ticketNumber)
           · falla individual → comment "Adjunto X no disponible: <reason>", continúa
     f. Commit → dispatch TicketCreated($ticket->id)
7. responder 200 con {ticket_id, ticket_number, created:true|false}
8. finally: Cache::delete('whatsapp_import:'.message_id)
```

**Invariantes:**
- `TicketCreated` se dispatcha SOLO tras commit (no antes).
- Si la transacción falla, no se modifica nada y el lock se libera.
- Adjunto fallido NO aborta la transacción — el ticket vale más que el adjunto.

### 3.6 Autenticación y rotación

Reusa el patrón de `gmailImport`:

- Setting `webhook_whatsapp_import_token` (cifrado vía `SettingsEncryptionTrait`).
- Cache de token previo en `CacheConstants::WEBHOOK_WHATSAPP_PREVIOUS_TOKEN` con TTL de grace.
- Constant-time compare con `hash_equals`.
- No se reusa el token de Gmail (least privilege).

### 3.7 Idempotencia y locking

| Mecanismo | Propósito |
|---|---|
| `Cache::add('whatsapp_import:<message_id>', 1, 60s)` | Lock por mensaje. Dos posts del mismo `message_id` en < 60s → segundo recibe 409. |
| Unique index `whatsapp_message_id` en `tickets` | Lock persistente. El reintento de n8n post-creación devuelve 200 con `created:false`. |
| Rate-limit por phone (`Cache::write('wa_phone_rate:<phone>', t, 5s)`) | Mitiga ráfaga de un mismo usuario. Devuelve 429 con `retry_after_seconds`. |

NO se usa file lock global como Gmail — el caso de uso es paralelo entre usuarios distintos.

### 3.8 Validación del payload (`WhatsappIngestPayload`)

| Campo | Regla |
|---|---|
| `message_id` | string no vacío, ≤ 120 chars, no whitespace |
| `phone_number` | E.164 (`^\+[1-9]\d{6,14}$`). Si llega sin `+` y empieza con dígito, agrega `+`. |
| `contact_name` | string ≤ 120 chars, opcional, default `null` |
| `subject` | string no vacío tras `trim`, ≤ 200 chars |
| `description` | string no vacío tras `trim`, ≤ 65535 chars |
| `attachments` | array ≤ 10. Cada item: `url` https://…, `filename` sin path traversal (basename), extensión + `mime` deben matchear `GenericAttachmentTrait::ALLOWED_TYPES` (keyed por extensión), `size` ≤ `GenericAttachmentTrait::MAX_FILE_SIZE` (hoy 10 MiB). |

Errores acumulados → 400 con `details: ["field 'phone_number': not E.164", …]`.

## 4. Componente B — `POST /webhooks/tickets/{id}/tags`

### 4.1 Contrato

```
POST /webhooks/tickets/{id}/tags
Headers: X-Webhook-Token: <token>
Body:    { "tag_ids": [3, 7, 12], "source": "auto" }
```

| Campo | Regla |
|---|---|
| `tag_ids` | array de int positivos, requerido, no vacío, ≤ 20 items, sin duplicados |
| `source` | enum `"auto" \| "manual"`, opcional (default `"auto"`); se loggea |

### 4.2 Respuestas

| Código | Body |
|---|---|
| `200` | `{ok:true, added:[3,7], skipped_existing:[12], skipped_unknown:[]}` |
| `400` | `{ok:false, error:"invalid_payload", details:[…]}` |
| `401` | `{ok:false, error:"invalid_token"}` |
| `404` | `{ok:false, error:"ticket_not_found"}` |
| `500` | `{ok:false, error:"persist_failed"}` |

### 4.3 Flujo

```
1. allowMethod(['POST'])
2. verifyTagsToken() → 401 si falla
3. Validar id route param (regex \d+ ya en routes)
4. Cargar ticket: TicketsTable::find()->where(['id'=>$id])->first() → 404 si null
5. Parsear body → 400 si falla
6. tag_ids ∩ Tags.find()->all() → known/unknown
7. Por cada known: TicketPipelineService::addTag($ticketId, $tagId)
     - éxito → added[]
     - "ya agregada" → skipped_existing[]
     - otro fallo → log error, no agregar a ningún array
8. Responder 200 con counts
```

`TicketPipelineService::addTag()` ya:
- Verifica existencia del ticket (`get()` lanza 404 si no existe).
- Valida `isUnique` y `existsIn` FK via rules.
- Hace `save` por el Table layer (no SQL crudo).

### 4.4 Setting y token

`webhook_tickets_tags_token` separado del de import (revocación granular). Mismo patrón de cifrado y grace window.

### 4.5 Logging

`Log::info('Tags webhook applied', ['ticket_id'=>…, 'added'=>[…], 'skipped_unknown'=>[…], 'source'=>…])`.
Si `skipped_unknown` no está vacío, escalar a `Log::warning` (señal de LLM alucinando).

## 5. Componente C — Decisión Evolution API vs Meta Cloud API

### 5.1 Decisión

**Evolution API es la integración canónica de WhatsApp** para Mesa de Ayuda.

Justificación:
- Alineación con CLAUDE.md y con el código de prod (`WhatsappService`, `WhatsappChannel`, `WhatsappConfig`).
- Self-hosted ⇒ sin costos por conversación.
- Una sola instancia para inbound y outbound del bot.

### 5.2 Entregables documentales (en Fase 1)

- Añadir nota en `CLAUDE.md` sección "Notifications and integrations": "WhatsApp = Evolution API. Cualquier uso de Meta Cloud API (`graph.facebook.com`) en n8n es deuda de Fase 2."
- Editar `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` §2.3 marcando el punto #9 como **resuelto** con link a este spec.

### 5.3 Lo que NO cambia en Fase 1

- El workflow n8n sigue llamando `graph.facebook.com`. Su migración a Evolution API es Fase 2 (parte del split de workflow).
- `WhatsappService` no se toca.

## 6. Routing

En `config/routes.php`, dentro del scope `/webhooks` existente (que ya skipea CSRF):

```php
$builder->post(
    '/whatsapp/import',
    ['controller' => 'Webhooks', 'action' => 'whatsappImport'],
    'webhook_whatsapp_import'
);

$builder->post(
    '/tickets/{id}/tags',
    ['controller' => 'Webhooks', 'action' => 'ticketTagsAdd'],
    'webhook_tickets_tags_add'
)
    ->setPatterns(['id' => '\d+'])
    ->setPass(['id']);
```

## 7. Modelo de datos

### 7.1 Tabla `tickets` (alterada)

| Campo nuevo | Tipo | Null | Default | Index |
|---|---|---|---|---|
| `whatsapp_message_id` | `VARCHAR(120)` | YES | NULL | UNIQUE `idx_tickets_whatsapp_message_id` |

### 7.2 Tabla `tickets_tags` (sin cambios)

Reusa estructura actual. La unicidad ya está garantizada por la PK compuesta (`ticket_id`, `tag_id`).

### 7.3 Settings nuevos

| Key | Tipo | Cifrado | Notas |
|---|---|---|---|
| `webhook_whatsapp_import_token` | string | sí | Generar via `bin/cake` o admin UI |
| `webhook_tickets_tags_token` | string | sí | Idem |

No se persisten en `app_local.php` — viven en `system_settings`.

## 8. Manejo de errores

| Escenario | Comportamiento |
|---|---|
| DB caída durante save | Excepción captada → log + 500. No se dispatcha `TicketCreated`. |
| Adjunto: HTTP 4xx/5xx al descargar | Log warning, comment "Adjunto `<filename>` no disponible". Ticket se crea. |
| Adjunto: payload > tamaño | 413 (rechaza request completa antes de tocar DB). |
| `whatsapp_enabled = 0` | 503 antes de tocar DB. |
| Race condition entre dos POST del mismo `message_id` | Lock de cache + unique index. Segundo intento recibe 409 o 200/created:false. |
| Race condition entre dos POST del mismo `phone_number` con `message_id` distintos | Permitido (caso real: usuario manda dos mensajes rápidos). Solo se aplica rate-limit suave. |
| Tag inexistente en `tag_ids` | Se omite, se devuelve en `skipped_unknown[]`. No falla la request. |
| Todos los `tag_ids` inexistentes | 200 con `added:[]`. No es error. |

## 9. Observabilidad

Logs estructurados (json-friendly via PSR-3 context):

- `WhatsApp webhook import OK` con `message_id`, `ticket_id`, `created`, `phone_number_hash` (no el plaintext).
- `WhatsApp webhook import rejected` con `reason` (token/payload/disabled/locked).
- `Tags webhook applied` (info) / `Tags webhook unknown tag_ids` (warning).
- Excepciones: `Log::error` con `error`, `class`, `request_id` (si se llega a propagar uno).

No se añaden métricas Prometheus en esta fase (no existe ese stack en el repo aún).

## 10. Testing

| Archivo | Tipo | Cubre |
|---|---|---|
| `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php` | unit | Validación: requeridos, longitudes, normalización E.164, allowlist MIME, límite de attachments |
| `tests/TestCase/Service/TicketIngestionServiceWhatsappTest.php` | unit/integration | `createFromWhatsapp`: éxito, mensaje duplicado retorna existing, user nuevo, user existente, adjunto que falla → comment, dispatch `TicketCreated` tras commit |
| `tests/TestCase/Controller/WebhooksControllerWhatsappImportTest.php` | integration | 401 sin token, 400 payload inválido, 409 lock, 200 happy path, 200 idempotente, 503 disabled, 413 attachments grandes |
| `tests/TestCase/Controller/WebhooksControllerTicketTagsTest.php` | integration | 401, 400, 404, 200 happy, 200 con `skipped_existing`, 200 con `skipped_unknown` |

Fixtures requeridas: `TicketsFixture`, `UsersFixture`, `TagsFixture`, `TicketTagsFixture`, `SystemSettingsFixture`.

`composer test` debe pasar verde. `composer cs-fix && composer cs-check` antes del merge.

## 11. Follow-ups (fuera de alcance de Fase 1)

Estos puntos del audit quedan vivos y se atacan en fases posteriores:

| # audit | Acción | Fase propuesta |
|---|---|---|
| 1 | Separar en `Mesa de Ayuda - WhatsApp Bot` y `Mesa de Ayuda - Auto Tagging` | 2 |
| 4 | Validar tag_ids contra `available_tags` (LLM-side) + retry/backoff Groq | 2 |
| 5 | Lock Redis (`SET NX EX`) por phoneNumber dentro del workflow | 2 |
| 6 | Idempotencia por `messageId` dentro del workflow | 2 (cinturón + tirantes con el unique index) |
| 7 | Consolidar 11 Code FSM en uno parametrizado o migrar a Agent | 3 |
| 8 | Eliminar nodos deshabilitados (`OpenAI`, `Asignación de Agente`) | 2 |
| 10 | Manejo de errores end-to-end en el workflow con notificación al usuario | 2 |

Migración del outbound del bot a Evolution API ocurre en Fase 2 junto con el split de workflow.

## 12. Criterios de éxito (verificables)

1. `composer test` verde con los 4 archivos de test nuevos.
2. `composer cs-check` verde.
3. `vendor/bin/phpstan analyse src` sin nuevos errores.
4. Migración `bin/cake migrations migrate` aplica y revierte (`migrations rollback`) limpiamente.
5. Smoke test manual con `curl` contra los dos endpoints (token correcto + payload válido) retorna 200.
6. `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` actualizado marcando #2, #3 y #9 como **resueltos en Fase 1** con link a este spec.
7. Nota en `CLAUDE.md` declarando Evolution API como única integración WhatsApp.

## 13. Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| n8n no se ha desplegado en prod (`active:false`); este spec entrega endpoints sin consumidor inmediato | Aceptable — son contratos. Smoke test via `curl` y tests integrados cubren la verificación. |
| Adjuntos descargados desde URLs externas exponen SSRF | `GenericAttachmentTrait` ya valida hosts/IPs via `SecureHttpTrait`. Reusar sin modificar. |
| Usuario con teléfono duplicado entre canales | `Users.phone` ya es único en el esquema actual. Si no, se ajusta en una migración separada — no se mezcla con esta. |
| `User` creado en runtime sin email | `UsersTable` debe permitir `email = NULL` para requesters WhatsApp. Verificar antes de implementar; si no, ajustar entity o crear email placeholder `<phone>@whatsapp.local`. |

Notar el último riesgo: el plan de implementación debe abrir `UsersTable` y verificarlo como primer paso.
