# Diseño · Fase 2 — n8n WhatsApp hardening + integración con Fase 1

- **Fecha**: 2026-05-19
- **Audit origen**: `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md`
- **Fase 1 referenciada**: `docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-1-design.md`
- **Workflow ID**: `YrY1cuaU5YobAUGu` (`Mesa de Ayuda - COPC SA`, actualmente `active:false`)
- **Alcance**: Audit items #1 (split), #4 (validación tag_ids + retry Groq), #5 (lock Redis), #6 (idempotencia), #8 (cleanup nodos muertos), #10 (error handling), más extensión backend para media de Meta y corrección documental.
- **Fase 3 (fuera de alcance)**: #7 (consolidación FSM o migración a Tools Agent).

---

## 0. Premisa actualizada (corrige Fase 1 §5)

Tras conversación con el usuario, **dos integraciones WhatsApp coexisten por diseño**:

- **Bot WhatsApp (inbound + outbound)** = Meta Cloud API (`graph.facebook.com/v24.0`). Vive en n8n.
- **Notificaciones de ticket creado a equipo de soporte (outbound)** = Evolution API. Vive en backend (`WhatsappService::sendNewEntityNotification`).

La conclusión de Fase 1 §5 ("Evolution API canónica") era incorrecta. Esta Fase incluye corregir `CLAUDE.md` y la nota del audit.

---

## 1. Objetivo

Dejar el sistema bot + auto-tagging en producción, conectado a los endpoints de Fase 1, robusto frente a reintentos de Meta, carreras de mensajes del mismo usuario, y fallos transitorios de Groq/HTTP.

## 2. No-objetivos

- Migrar el bot a Evolution API (no es necesario — los dos canales coexisten por diseño).
- Consolidar los 11 nodos Code de la FSM o migrar a Tools Agent (Fase 3).
- Cambiar el TTL de la sesión Redis (queda 1h).
- Construir UI de admin para editar el workflow desde el backend.
- Implementar un webhook de monitoreo externo (Slack/Discord) si no existe ya.

## 3. Pre-requisito · Backend extensión para Meta media (`content_base64`)

Meta Cloud API entrega media en dos pasos, ambos con Bearer del app de WhatsApp Business. El backend de Fase 1 espera URLs descargables públicamente (sin Bearer), lo que no funciona con Meta. La solución más limpia es que n8n descargue el binario (ya tiene el patrón en `Descargar Archivos`) y lo envíe en base64.

### 3.1 Cambios en el contrato

`WhatsappIngestPayloadAttachment` acepta **exactamente uno de** `url` o `content_base64`:

```json
{
  "filename": "foto.jpg",
  "mime": "image/jpeg",
  "size": 12345,
  "content_base64": "iVBORw0KGgo..."
}
```

o:

```json
{
  "filename": "doc.pdf",
  "mime": "application/pdf",
  "size": 98765,
  "url": "https://otro-origen.example/file.pdf"
}
```

Validación: presencia mutuamente exclusiva. Faltar ambos → 400. Tener ambos → 400.

### 3.2 Cambios en código

| Archivo | Cambio |
|---|---|
| `src/Service/Dto/WhatsappIngestPayloadAttachment.php` | `url` y `content_base64` pasan a opcionales; XOR en validación; `content_base64` validado como base64 estándar; tamaño después de decodificar respeta `MAX_FILE_SIZE`. |
| `src/Service/TicketIngestionService.php` | `downloadAndStoreWhatsappAttachment` ramifica: si `content_base64 != null`, `base64_decode(strict)` y pasa al `saveAttachmentFromBinary`; si no, mantiene el path actual de `file_get_contents`. |
| `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php` | Añade 4 tests: happy path base64, rechazo si faltan ambos, rechazo si vienen ambos, rechazo base64 inválido. |

### 3.3 Compatibilidad

- Smoke tests bash existentes siguen pasando (usan `url` ficticio que en la práctica el smoke ignora).
- Endpoint en producción no rompe consumidores existentes (no había ninguno; Fase 1 está merged pero el workflow n8n aún no llama).

## 4. Fase 2A · Estructural

Split del workflow en dos + integración HTTP con Fase 1 + limpieza de nodos muertos. No introduce idempotencia ni locks todavía (eso es 2B). El resultado de 2A es un sistema funcional pero con las mismas razas del original.

### 4.1 Workflow 1 nuevo · `Mesa de Ayuda - Auto Tagging`

Se crea desde cero en n8n vía MCP. Reemplaza el sub-flujo de tagging del workflow actual.

**Nodos**:

```
[Webhook /tagging]
  → [Set Data Webhook]        (extrae ticket.id, subject, description, available_tags del body)
  → [Basic LLM Chain (Groq)]  (system prompt actual; retry config en §5.5)
  → [Code: Validar tag_ids]   (filtra contra available_tags; emite array dropped[])
  → [HTTP POST /webhooks/tickets/{id}/tags]
  → [Code: Log resultado]     (registra added, skipped_existing, skipped_unknown del response)
```

**Removed del workflow original**: `Set Data Agent`, `Formatear datos`, `Insert rows in a table`. La llamada al endpoint reemplaza el INSERT.

**Trigger del workflow original**: el nodo `Asignacion de Tags` se mueve al nuevo workflow. La ruta `/asignación-tags-mesa-de-ayuda` se mantiene para no romper consumidores existentes; la documentación recomienda llamar el nombre nuevo `/tagging` cuando se reescriba el caller.

### 4.2 Workflow 2 modificado · `Mesa de Ayuda - WhatsApp Bot`

El workflow actual `Mesa de Ayuda - COPC SA` se renombra a `Mesa de Ayuda - WhatsApp Bot` (un solo cambio de nombre + remover sub-flujo de tagging).

**Path de creación de ticket reemplazado** (lo que era `Crear Ticket → Send message → Switch1 → email path`):

```
[Crear Ticket (Code, existente)]
  → [Redis Update Session]
  → [If: state === confirm]   (la condición del Switch1 actual)
  → [Code: Build import payload]
        Construye el body para POST /webhooks/whatsapp/import:
        - message_id    = $('Parse Data Whatsapp').first().json.messageId
        - phone_number  = $sessionData.phoneNumber
        - contact_name  = $sessionData.userName
        - subject       = $sessionData.subject
        - description   = $sessionData.description
        - attachments   = (ver 4.3)
  → [HTTP POST /webhooks/whatsapp/import]
        Headers: X-Webhook-Token (credencial n8n), Content-Type: application/json
        Body: $json.payload
  → [If: $statusCode === 200 && body.ok]
        ✓ → [Send WhatsApp Cloud: "¡Listo! Tu ticket #{ticket_number} fue creado."]
             → [Redis DEL session]
        ✗ → [Notificar Error al Usuario]   (sub-flujo §5.5)
             → [Redis DEL session]
```

**Branch "cancel"** del Switch1 mantiene su path actual (responder cancel + Redis DEL session).

**Nodos eliminados** del workflow del bot:
- `Aggregate`, `Descargar Archivos`, `Enviar Ticket`, `Enviar Ticket con Archivos`, `Parse Email Data`, `Parse Attachments Data`, `If1` (todo el path de email).
- `Send message` (era el ack post-email; reemplazado por respuesta basada en ticket_number real).
- `OpenAI` (disabled).
- `Asignación de Agente` + `Set Data Webhook1` (disabled).
- Sub-flujo completo de tagging que se mueve al nuevo workflow.

Resultado: **~25–28 nodos** (down from 38).

### 4.3 Resolución de adjuntos en el bot (Meta media → base64)

Cuando `sessionData.attachments` tiene items, el nodo `Code: Build import payload` debe descargar cada uno desde Meta antes de armar el body. n8n ya tiene el patrón (`Descargar Archivos` del workflow viejo). Lo refactoreamos a un sub-flujo inline:

```
[Code: Build import payload]
  // sessionData.attachments es string JSON con array de
  // { id, type, name, mimeType, mediaUrl }
  // Para cada item: GET https://graph.facebook.com/v24.0/{id}  con Bearer
  //   → response.url (signed)
  // Luego: GET {signed_url} con Bearer → binary
  //   → base64 encode
  // Construye attachment object con content_base64.
```

n8n no soporta loops dentro de un Code que hagan HTTP. Dos opciones:

| Opción | Implementación |
|---|---|
| **A. Split-into-items + HTTP nodes** (recomendada) | El array de attachments se "explota" con `Item Lists - Split Out`. Cada item pasa por un sub-flow `HTTP Get Meta media URL` → `HTTP Get binary (response.url)` → `Move binary to base64`. Luego `Aggregate` para reagrupar y mandar al payload final. |
| B. Single Code with fetch() | Posible pero menos legible y bypassa el manejo de retry/error del HTTP node. |

Implementamos **Opción A**. Sub-flow:

```
[Item Lists: Split sessionData.attachments]
  → [HTTP Get /v24.0/{id}]          (con Bearer header de credencial Meta)
  → [HTTP Get {response.url}]       (response binary)
  → [Code: Encode base64]
  → [Aggregate: collect all]
  → main payload builder
```

Si no hay adjuntos, el split-out emite 0 items y `Aggregate` produce array vacío. Caso natural.

### 4.4 Limpieza de nodos muertos (#8)

Lista exacta a eliminar (verificable por `id` de nodo):

| Nombre | id | Razón |
|---|---|---|
| `OpenAI` | `effd2210-e7dc-4dad-a246-c45d82083e1c` | disabled, no usado |
| `Asignación de Agente` | `04b97a91-aba1-4e45-aade-9ff58c5fb2be` | disabled |
| `Set Data Webhook1` | `cc01246c-62dd-46c1-aba0-45f50f4e68c7` | disabled, consume #anterior |

Y todos los nodos del sub-flujo de tagging (`Asignacion de Tags`, `Set Data Webhook`, `Formatear JSON`, `Basic LLM Chain`, `Groq Chat Model`, `Structured Output Parser`, `Set Data Agent`, `Formatear datos`, `Insert rows in a table`) se mueven al nuevo workflow `Auto Tagging`.

## 5. Fase 2B · Resiliencia

Añade idempotencia, lock por phone, validación de tag_ids, retry Groq, y error handling end-to-end. Cada uno es un cambio aditivo sobre el grafo limpio de 2A.

### 5.1 Idempotencia por `message.id` (#6)

Justo después de `Parse Data Whatsapp` (que ya emite `messageId`):

```
[Parse Data Whatsapp]
  → [Redis SET NX EX]
        key: mesadeayuda:msg:{messageId}
        value: 1
        ttl: 86400  (24h)
        operation: setIfNotExists
  → [If: was already set (resultado != "OK")]
        true  → [No Operation, exit]  // mensaje ya procesado
        false → [Redis Get Session]   // continúa el flujo normal
```

Notas:
- TTL de 24h es generoso. Meta reenvía hasta ~5min según docs; 24h cubre escenarios degenerados.
- `Redis SET NX` retorna `OK` si se escribió, `null` si la clave ya existía. n8n's redis node con operation `setIfNotExists` expone esto via `$json.success` o `$json.result` según versión — verificar al implementar.

### 5.2 Lock por `phoneNumber` (#5)

Después del check de idempotencia, antes del FSM:

```
  → [Redis SET NX EX]
        key: mesadeayuda:lock:{phoneNumber}
        value: 1
        ttl: 60   (60s de mutex; auto-release si el workflow muere)
        operation: setIfNotExists
  → [If: lock acquired]
        no  → [Send WhatsApp text "⏳ Procesando tu mensaje anterior, espera unos segundos..."]
              → [No Operation, exit]
        yes → [Redis Get Session]
              ...
              [Redis DEL lock]    (liberar en cada path final: confirm, cancel, error)
```

**Auto-release por TTL**: si el workflow muere, el lock expira solo en 60s. Es el "safety net" análogo al lock de Fase 1 controller.

### 5.3 Validación tag_ids contra `available_tags` (#4)

En el workflow `Auto Tagging`, **después** del `Basic LLM Chain` y **antes** del `HTTP POST /webhooks/tickets/{id}/tags`:

```javascript
// Code: Validar tag_ids
const llmOutput = $input.first().json.output;
const available = $('Set Data Webhook').first().json.available_tags;
const availableIds = available.map(t => Number(t.id));

const requested = (llmOutput.tag_ids || []).map(Number);
const valid = requested.filter(id => availableIds.includes(id));
const dropped = requested.filter(id => !availableIds.includes(id));

if (dropped.length > 0) {
  console.warn('LLM hallucinated tag_ids', { dropped, ticket_id: llmOutput.ticket_id });
}

return [{ json: {
  ticket_id: Number(llmOutput.ticket_id),
  tag_ids: valid,
}}];
```

El backend de Fase 1 ya ignora `tag_ids` desconocidos (los reporta en `skipped_unknown`), así que esto es defensa en profundidad — pero permite log en el lado n8n con contexto del prompt.

### 5.4 Retry/backoff Groq (#4)

En el nodo `Basic LLM Chain` (settings del nodo):

| Setting | Valor |
|---|---|
| Continue On Fail | enabled |
| Retry On Fail | enabled |
| Max Tries | 3 |
| Wait Between Tries (ms) | 1000 (n8n hace exponential automático en versiones nuevas) |

Si tras 3 intentos sigue fallando, el nodo emite el error en su output. Un `If` posterior detecta error y enruta a un log + exit (no notifica al usuario porque el tagging es asíncrono al ticket — el ticket ya existe).

### 5.5 Error handling end-to-end del bot (#10)

Sub-flow `Notificar Error al Usuario` (reutilizable, llamado desde cualquier path de error en el bot):

```
[Code: Build error message]
  // input: { reason, phoneNumber, errorCode? }
  // construye whatsappPayload con texto:
  //   "⚠️ Ups, tuvimos un problema procesando tu solicitud.
  //    Por favor reintenta en unos minutos.
  //    Si el problema persiste, contacta a soporte."
  → [HTTP POST Meta Cloud API]   (mismo patrón que Enviar Texto)
  → [Log error structured]
  → [Redis DEL session]          (limpiar para que el usuario reinicie)
  → [Redis DEL lock]
```

**Puntos donde se enrutará a este sub-flow**:
- HTTP POST `/whatsapp/import` retorna status != 200 o `ok:false`.
- HTTP POST `/whatsapp/import` timeout o conexión rechazada.
- `Build import payload` lanza excepción (raro, pero por completitud).
- Descarga de Meta media falla tras retry.

**Error Workflow global** (n8n setting nivel workflow): apunta a un workflow externo de monitoreo. Por ahora dejamos el setting vacío (n8n loggea al error workflow del proyecto si existe). Se puede ajustar luego sin tocar el bot.

## 6. Smoke testing integrado Fase 1 + Fase 2

Tres niveles, cada uno verificable independientemente.

### 6.1 Bash smoke (ya existe)

`tests/smoke/whatsapp_import.sh` y `tests/smoke/tickets_tags.sh` quedan **sin cambios**. Validan el contrato HTTP del backend con curl.

Añadimos un caso extra a `whatsapp_import.sh`:

- Caso con `content_base64`: payload con un PNG de 1x1 codificado, verifica 200 + creación de attachment.

### 6.2 Smoke n8n workflow nuevo · `Mesa de Ayuda - Smoke Tests`

Creado vía MCP (`mcp__claude_ai_n8n__create_workflow_from_code`). Permanece `active:false`; se dispara manualmente desde el editor n8n.

```
[Manual Trigger]
  → [HTTP POST /whatsapp/import (caso happy con base64)]
  → [Assert: status 200, created:true]
  → [HTTP POST /whatsapp/import (mismo message_id)]
  → [Assert: status 200, created:false]
  → [HTTP POST /tickets/{id}/tags (mix de válidos + inválidos)]
  → [Assert: skipped_unknown contiene los IDs inválidos]
  → [HTTP POST /tickets/9999999/tags]
  → [Assert: status 404]
  → [HTTP POST /whatsapp/import sin X-Webhook-Token]
  → [Assert: status 401]
  → [Log: resumen de los 5 casos]
```

Cada `Assert` es un nodo `If` que rompe la cadena si falla, dejando un log con el caso roto.

### 6.3 Smoke end-to-end manual con teléfono real

Lista de casos a ejecutar tras activar el workflow del bot en prod (con `active:true`). Documentado en `docs/operations/whatsapp-bot-smoke.md`:

1. **Happy path sin archivos**: enviar mensaje al número del bot → menú → "Crear Ticket" → asunto → descripción → "Saltar" → "Crear Ticket". Verificar: respuesta con `ticket_number`, ticket existe en `/` con `channel=whatsapp` y `whatsapp_message_id` poblado.
2. **Happy path con archivo**: igual pero adjunta una foto en el paso de archivos. Verificar: attachment guardado en `webroot/uploads/attachments/{ticket_number}/`.
3. **Cancelación**: llegar a confirmación y elegir "Cancelar". Verificar: sin ticket creado, sesión Redis borrada.
4. **Idempotencia**: forzar reenvío del mismo mensaje por Meta (simulable cancelando+reactivando webhook). Verificar: NO se crea segundo ticket, NO se procesa el FSM dos veces.
5. **Lock**: enviar dos mensajes consecutivos rápidos. Verificar: segundo recibe "procesando…", se procesa después.
6. **Tagging**: tras crear un ticket vía bot, verificar que `Auto Tagging` workflow fue invocado por el backend (registro en logs n8n) y los tags aparecen en el ticket.
7. **Error transitorio**: apagar backend deliberadamente, completar flujo del bot. Verificar: usuario recibe "ups, reintenta", sesión limpia.

## 7. Documentación a actualizar

### 7.1 `CLAUDE.md` — corregir línea 92-95

Reemplazar el párrafo "WhatsApp = Evolution API (canónica)…" con:

```markdown
**WhatsApp: dos integraciones por diseño.**
- **Bot WhatsApp (inbound + outbound conversacional)** → Meta Cloud API (`graph.facebook.com`), gestionado en n8n.
- **Notificaciones outbound de ticket creado al equipo de soporte** → Evolution API, gestionado en backend (`WhatsappService::sendNewEntityNotification`).

Cada API tiene su propio caso de uso y credenciales en `system_settings`.
```

### 7.2 `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` — corregir cierre #9

En la nota de cierre del audit, el punto #9 dice "resuelto en Fase 1" con la decisión Evolution canónica. Reescribir como:

```markdown
✅ 9 — Coexistencia documentada (no migración): Bot WhatsApp usa Meta Cloud API en n8n; notificaciones de ticket usan Evolution API en backend. Ver `docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-2-design.md` §0.
```

### 7.3 `docs/operations/whatsapp-bot-smoke.md` (nuevo)

Documenta los 7 casos de smoke manual del §6.3.

### 7.4 `docs/operations/n8n/` (nuevo dir)

Dumps JSON de los dos workflows finales (Bot + Auto Tagging) para que git tenga la historia de cambios. La fuente de verdad sigue siendo n8n; los dumps son snapshot referenciables. Se actualizan vía `mcp__claude_ai_n8n__get_workflow_details` después de cada cambio.

## 8. Plan de despliegue

Orden de merge (cada paso independientemente verificable):

| # | Paso | Tipo | Verificación |
|---|---|---|---|
| 0 | Corrección CLAUDE.md + nota audit #9 (§7.1, §7.2) | doc | revisión humana |
| 1 | Backend: `content_base64` support (§3) | código + tests | `composer test` + nuevo smoke con base64 |
| 2 | Crear workflow `Mesa de Ayuda - Auto Tagging` vía MCP (§4.1) | n8n | smoke n8n caso tags |
| 3 | Modificar workflow del bot: split del sub-flujo de tagging (mueve nodos al de #2) | n8n | el bot sigue funcionando offline en test |
| 4 | Modificar workflow del bot: reemplazar email path por HTTP POST (§4.2, §4.3) | n8n | smoke end-to-end caso 1 + 2 |
| 5 | Cleanup nodos muertos (§4.4) | n8n | el workflow corre |
| 6 | Añadir idempotencia (§5.1) | n8n | smoke caso 4 |
| 7 | Añadir lock (§5.2) | n8n | smoke caso 5 |
| 8 | Añadir validación tag_ids + retry Groq (§5.3, §5.4) | n8n | smoke `Auto Tagging` |
| 9 | Añadir error handling sub-flow (§5.5) | n8n | smoke caso 7 |
| 10 | Crear workflow `Mesa de Ayuda - Smoke Tests` vía MCP (§6.2) | n8n | ejecutar manualmente |
| 11 | Actualizar `docs/operations/whatsapp-bot-smoke.md` (§7.3) + dumps n8n (§7.4) | doc | revisión |
| 12 | Activar ambos workflows del bot y tagging (`active: true`) | n8n | smoke end-to-end manual |

Cada paso del 1 al 11 produce un commit en el repo PHP (los workflows n8n se versionan vía dumps JSON bajo `docs/operations/n8n/`). El paso 12 es operacional (un toggle en n8n UI), no produce commit.

## 9. Criterios de éxito

- [ ] Workflow `Mesa de Ayuda - WhatsApp Bot` y `Mesa de Ayuda - Auto Tagging` existen y están activos en n8n.
- [ ] El workflow del bot tiene ≤ 28 nodos (down from 38).
- [ ] `tests/smoke/whatsapp_import.sh` pasa con caso `content_base64` añadido.
- [ ] `composer test` verde tras la extensión backend (Fase 1.5).
- [ ] El workflow `Smoke Tests` n8n ejecutado manualmente pasa los 5 casos.
- [ ] Los 7 casos de smoke manual del §6.3 ejecutados con éxito y registrados en `docs/operations/whatsapp-bot-smoke.md`.
- [ ] `CLAUDE.md` línea 92-95 corregida.
- [ ] Audit nota #9 corregida.
- [ ] Dumps JSON de los dos workflows en `docs/operations/n8n/`.

## 10. Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| Token Bearer Meta cambia/revoca en credenciales n8n y se rompe la descarga de media | n8n alerta vía workflow error; el flujo cae en `Notificar Error al Usuario`. Configurar rotación documentada en `docs/operations/whatsapp-bot-smoke.md`. |
| `content_base64` infla payloads >10 MiB (que es el cap actual) | Backend ya rechaza en `MAX_FILE_SIZE`. n8n NO debe enviar adjuntos que excedan; añadir guard en `Build import payload`. |
| El lock por phoneNumber bloquea legítimamente a un usuario muy rápido | TTL 60s es la peor espera. Aceptable. Si se vuelve problema, reducir a 30s. |
| Race entre idempotency check y FSM si la ventana es exactamente entre los dos `SET NX` | Imposible: el segundo `SET NX` es para el lock, no para el message_id. El message_id ya fue marcado. Cualquier reentrada con mismo id es rechazada por el primer check. |
| Workflow `Auto Tagging` retry tras 3 intentos genera duplicados | El endpoint `POST /tickets/{id}/tags` es idempotente (Fase 1 §4.3). Reintentar es seguro. |
| Backend ahora acepta payloads más grandes (base64 inflado) | El Cake request size limit debe permitir ~14 MiB. Verificar `post_max_size` y `upload_max_filesize` en docker/PHP-FPM config; ajustar si es < 14 MiB. |

## 11. Follow-ups explícitos (fuera de alcance)

- **Fase 3**: consolidar 11 nodos Code FSM o migrar a Tools Agent.
- Validación de filename más estricta (`[A-Za-z0-9._-]+` o similar) — código quality reviewer ya lo flageó en Fase 1.
- Métricas Prometheus para los endpoints — no hay stack todavía.
- LogMasker::phone() extender a también enmascarar en el path de email (`createFromEmail` + `findOrCreateUser`).
- UI de admin para visualizar status del bot, rotar Meta token sin entrar a n8n.
