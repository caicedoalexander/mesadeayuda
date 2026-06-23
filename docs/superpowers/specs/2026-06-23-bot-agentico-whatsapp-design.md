# Diseño · Bot WhatsApp Agéntico (Fase 3) — migración de FSM a Tools Agent

- **Fecha**: 2026-06-23
- **Audit origen**: `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` (punto #7)
- **Fases previas**: Fase 1 (`…/2026-05-19-n8n-whatsapp-audit-fase-1-design.md`) y Fase 2 (`…/2026-05-19-n8n-whatsapp-audit-fase-2-design.md`)
- **Workflow nuevo**: `Mesa de Ayuda - WhatsApp Bot` (a crear en n8n)
- **Workflow a archivar**: `Mesa de Ayuda - COPC SA` (`YrY1cuaU5YobAUGu`)
- **Alcance**: reemplazar la FSM del bot por un AI Agent (Tools Agent) que crea tickets de forma conversacional, incluyendo la resiliencia (idempotencia, lock, error handling) que quedó pendiente de la Fase 2.

---

## 0. Contexto y estado de partida

El audit del 2026-05-18 dejó tres fases. Estado real a la fecha de este spec:

- **Backend (Fase 1 + Fase 2.1) — completo.** Existen y están testeados:
  `WebhooksController::whatsappImport()` (`POST /webhooks/whatsapp/import`),
  `WebhooksController::ticketTagsAdd()` (`POST /webhooks/tickets/{id}/tags`),
  `WhatsappIngestPayload` (+`WhatsappIngestPayloadAttachment` con soporte `content_base64`),
  `TicketIngestionService::createFromWhatsapp()`, `Ticket::fromWhatsappIngest()`,
  y la columna única `tickets.whatsapp_message_id`.
- **n8n secundarios — listos.** `Mesa de Ayuda - Auto Tagging` (activo, separado del bot) y
  `Mesa de Ayuda - Smoke Tests` (manual). `Gmail Import Trigger` (activo).
- **Bot WhatsApp — sin terminar.** El workflow `COPC SA` sigue siendo el original (38 nodos,
  FSM de 7 estados con 11 nodos `Code` casi idénticos, ruta de creación **por email** a Gmail,
  nodos muertos `OpenAI`/`Asignación de Agente`, `active:false`). Las Tasks 4–10 de la Fase 2
  (split email→HTTP, idempotencia, lock, error handling) **nunca se aplicaron al bot**, y la
  Fase 3 (este spec) no se había iniciado.

Este spec aborda la Fase 3 **y** absorbe la resiliencia pendiente de Fase 2, porque construir un
bot nuevo desde cero hace innecesario remendar el viejo.

## 1. Objetivo

Sustituir el bot WhatsApp por un **AI Agent conversacional** que:

1. Conversa en lenguaje natural con el usuario para entender su problema.
2. Recopila **asunto** y **descripción**, reconociendo los **adjuntos** enviados.
3. **Resume y pide confirmación** antes de crear el ticket.
4. Crea el ticket llamando al endpoint existente `POST /webhooks/whatsapp/import`.
5. Responde con el número de ticket creado.

Todo el trabajo ocurre en **n8n**. No se escribe código de backend nuevo.

## 2. No-objetivos (explícitos)

- **Sin backend nuevo.** No se crean endpoints de lectura/consulta de tickets, ni de comentarios.
  El bot solo **crea** tickets (decisión de alcance v1).
- No se migra el outbound a Evolution API. El bot usa **Meta Cloud API** (`graph.facebook.com`),
  coexistencia por diseño con las notificaciones de Evolution del backend (ver CLAUDE.md).
- No se modifican `Auto Tagging` ni los endpoints de backend.
- No se implementan botones interactivos de WhatsApp en v1 (el agente conversa por texto);
  queda como follow-up opcional.
- No se implementa control de acceso por lista blanca de números en este spec (ver §11).

## 3. Decisiones de diseño

| Tema | Decisión |
|---|---|
| Alcance | Solo crear tickets, conversacional |
| Arquitectura | Agente puro: AI Agent (LangChain Tools Agent) de n8n |
| LLM | OpenCode Zen (suscripción del usuario), vía credencial OpenAI con base URL custom |
| Adjuntos | Soportados (imágenes/documentos): descarga de Meta → base64 → `content_base64` |
| Confirmación | El agente resume y pide confirmación antes de invocar `create_ticket` |
| Resiliencia | Idempotencia por `message.id` + lock por teléfono + error handling end-to-end |

## 4. Arquitectura del workflow

Un único workflow nuevo en n8n: `Mesa de Ayuda - WhatsApp Bot`.

```
[WhatsApp Trigger (Meta Cloud API)]
   → [Parse Data] (Code)
        extrae: phoneNumber, userName, messageId, messageType,
                messageContent, mediaData{id,mime,filename}? , whatsappApiUrl
   → [Redis SET NX  msg:{messageId}  ttl 86400]
        └─(ya existía)─→ [NoOp: salir]                       # idempotencia (reenvíos de Meta)
   → [Redis SET NX  lock:{phoneNumber}  ttl 60]
        └─(no adquirido)─→ [HTTP POST Meta: "⏳ procesando tu mensaje anterior…"] → [salir]
   → [If: ¿el mensaje trae adjunto?]
        ├─ sí → [HTTP GET graph.facebook.com/v24.0/{mediaId}  (Bearer Meta)]   → signed URL
        │       → [HTTP GET {signed_url}  (Bearer Meta), responseFormat=file]   → binario
        │       → [Code: base64 + push a Redis  attachments:{phoneNumber}]
        │       → input para el agente = "[El usuario adjuntó {filename} ({mime})]"
        └─ no → input para el agente = messageContent
   → [AI AGENT]
        ├─ Chat Model:  OpenAI Chat Model → base URL OpenCode Zen, model id manual
        ├─ Memory:      Redis Chat Memory, sessionKey = phoneNumber, ttl ~3600
        ├─ System prompt: rol + objetivo + reglas + gate de confirmación
        └─ Tool:        create_ticket  (HTTP Request Tool → POST /webhooks/whatsapp/import)
   → [HTTP POST Meta: enviar el texto de salida del agente al usuario]
   → [Redis DEL lock:{phoneNumber}]
```

La FSM completa (`Switch` principal + 11 `Code` de estado + `Switch2/3/4` + nodos de menú)
se elimina. La **memoria conversacional** de Redis sustituye al `state` de la sesión.

## 5. El agente (núcleo)

### 5.1 Chat Model — OpenCode Zen

- Sub-nodo "OpenAI Chat Model" (LangChain).
- Credencial n8n tipo OpenAI con:
  - **Base URL**: `https://opencode.ai/zen/v1`
  - **API Key**: la generada en la cuenta de OpenCode.
- **Model id escrito a mano** (ej. `opencode/gpt-5.5`) porque Zen no expone `/v1/models`
  para autopoblar el desplegable. Elegir un modelo fuerte en function-calling.
- Es un sub-nodo intercambiable: cambiar a Groq (`gpt-oss-120b`, credencial ya existente)
  es reemplazar el sub-nodo, sin tocar el resto del workflow.

### 5.2 Memoria — Redis Chat Memory

- Sub-nodo "Redis Chat Memory".
- `sessionKey` = número de teléfono del usuario.
- TTL ~3600s. Mantiene el hilo entre mensajes; reemplaza la máquina de estados.
- Tras crear un ticket, la conversación se considera cerrada; se apoya en TTL + instrucción
  del system prompt ("si tras crear un ticket el usuario plantea algo nuevo, inicia otro
  ticket desde cero"). Limpieza explícita de memoria queda como follow-up opcional (§11).

### 5.3 System prompt (resumen del contenido, en español)

- **Rol**: asistente de la Mesa de Ayuda de soporte interno.
- **Objetivo único**: recopilar el problema del usuario y crear un ticket.
- **Flujo esperado**: saludar brevemente → entender el problema en lenguaje natural →
  asegurarse de tener un **asunto** corto y una **descripción** suficiente → mencionar los
  adjuntos recibidos → **resumir y pedir confirmación explícita** → solo entonces llamar a
  `create_ticket` → comunicar el número de ticket resultante.
- **Reglas**: conciso y cordial; español; no inventar datos (ni teléfono, ni IDs); no llamar
  a `create_ticket` sin confirmación del usuario; si el usuario divaga o pide algo fuera de
  alcance (consultar estado, etc.), explicar con amabilidad que por ahora solo puede crear
  tickets; si el usuario corrige un dato ("mejor el asunto es X"), actualizarlo.

### 5.4 Tool `create_ticket`

- Implementado como **HTTP Request Tool** del agente → `POST {{$env.MESADEAYUDA_URL}}/webhooks/whatsapp/import`.
- Header `X-Webhook-Token` desde credencial n8n (Header Auth) = `webhook_whatsapp_import_token`.
- Cuerpo (JSON):
  - `subject` ← **provisto por el LLM** (`$fromAI`), descrito como "asunto corto del ticket".
  - `description` ← **provisto por el LLM** (`$fromAI`), descrito como "descripción detallada".
  - `message_id` ← expresión determinista `={{ $('Parse Data').first().json.messageId }}`.
  - `phone_number` ← `={{ $('Parse Data').first().json.phoneNumber }}` (normalizado a E.164).
  - `contact_name` ← `={{ $('Parse Data').first().json.userName }}`.
  - `attachments` ← expresión que lee `attachments:{phone}` de Redis (array de
    `{filename, mime, size, content_base64}`); `[]` si no hay.
- **Principio**: el LLM solo controla `subject`/`description`. Identificadores, teléfono y
  adjuntos se inyectan por expresión — el agente nunca los fabrica.
- La respuesta (`{ok, ticket_id, created}`) regresa al agente para que confirme al usuario.
- Tras una creación exitosa (`created:true`), el tramo determinista limpia
  `attachments:{phone}` en Redis.

## 6. Adjuntos (Meta media → base64)

El agente trabaja con texto, así que los binarios se procesan **antes** del agente:

1. Cuando `Parse Data` detecta `mediaData`, se hace `HTTP GET /v24.0/{mediaId}` con el Bearer
   de Meta para obtener la URL firmada, y luego `HTTP GET {url}` (Bearer) para el binario.
2. Se codifica en base64 y se hace push a una lista en Redis `attachments:{phoneNumber}`
   como `{filename, mime, size, content_base64}`.
3. Al agente se le inyecta un texto sintético ("[El usuario adjuntó foto.jpg (image/jpeg)]")
   para que sepa que existe el adjunto y lo mencione.
4. En `create_ticket`, los adjuntos acumulados se envían en el array `attachments`. El backend
   ya valida y persiste `content_base64` (Fase 2.1).

**Guard de tamaño**: no enviar adjuntos cuyo binario decodificado supere el límite del backend
(`MAX_FILE_SIZE`, hoy 10 MiB); el exceso se descarta con un aviso al usuario.

## 7. Resiliencia

| Mecanismo | Implementación | Propósito |
|---|---|---|
| Idempotencia | `Redis SET NX msg:{messageId}` TTL 24h tras `Parse Data` | Ignora reenvíos del mismo mensaje por Meta |
| Lock por teléfono | `Redis SET NX lock:{phone}` TTL 60s, `DEL` al final de cada turno | Serializa turnos del mismo usuario; evita corromper la memoria con dos mensajes concurrentes. Auto-libera por TTL si el workflow muere |
| Error handling | "Continue On Fail" en nodos HTTP críticos (descarga Meta, `create_ticket`, envío de respuesta) → rama de error que envía "⚠️ Ups, tuvimos un problema, reintenta en unos minutos", loguea y libera el lock | El usuario nunca queda sin respuesta |

## 8. Qué se crea / elimina

- **Crear** workflow `Mesa de Ayuda - WhatsApp Bot` (vía MCP `create_workflow_from_code`),
  `active:false` hasta validar.
- **Archivar** `Mesa de Ayuda - COPC SA` (`YrY1cuaU5YobAUGu`): se mantiene inactivo como
  referencia durante la validación y se archiva tras el smoke manual exitoso.
- **No se toca** `Auto Tagging` (se dispara solo: el backend emite `TicketCreated` al crear el
  ticket del bot, y de ahí parte la cadena de tagging existente).

## 9. Configuración / operación (pre-requisitos)

- Credencial n8n **OpenAI** con base URL `https://opencode.ai/zen/v1` + API key de OpenCode.
- Credenciales **Meta Cloud API** (Bearer + Phone Number ID) — ya usadas por el bot viejo.
- Setting backend `whatsapp_enabled = 1` y `webhook_whatsapp_import_token` compartido con la
  credencial Header Auth de n8n.
- Variable de entorno n8n `MESADEAYUDA_URL` apuntando al backend.
- Redis accesible desde n8n (ya en uso por el bot viejo y Auto Tagging).

## 10. Testing y verificación

- **Backend**: sin cambios → la suite de Fase 1/2 (`composer test`) ya cubre el endpoint y el
  soporte `content_base64`. No se escribe test de backend nuevo.
- **n8n**: cada cambio sigue el ciclo del SDK (`get_sdk_reference` → `search_nodes` →
  `get_node_types` → construir → `validate_workflow` hasta verde → `create/update_workflow` →
  `get_workflow_details` para confirmar).
- **Dump versionado**: `docs/operations/n8n/bot-workflow.json` se actualiza tras los cambios.
- **Smoke manual end-to-end** (actualizar `docs/operations/whatsapp-bot-smoke.md` para el
  agente):
  1. Happy path solo texto: describe un problema → el agente resume → confirmar → ticket creado
     con `channel=whatsapp` y `whatsapp_message_id` poblado.
  2. Happy path con foto: adjunta imagen → aparece en el ticket.
  3. Cancelación: el usuario dice que no → no se crea ticket.
  4. Idempotencia: reenvío del mismo mensaje → no se procesa dos veces.
  5. Lock: dos mensajes muy seguidos → el segundo recibe "procesando…".
  6. Error transitorio: backend caído → el usuario recibe el mensaje de error; Redis limpio.
  7. Auto Tagging: tras crear el ticket, el workflow de tagging se dispara y aparecen tags.

## 11. Criterios de éxito (verificables)

- [ ] Workflow `Mesa de Ayuda - WhatsApp Bot` creado y validado en n8n.
- [ ] El bot crea un ticket vía `POST /webhooks/whatsapp/import` (no por email).
- [ ] Conversación 100% en lenguaje natural; sin FSM (ningún `Switch` de estado).
- [ ] Gate de confirmación antes de crear funciona.
- [ ] Adjuntos enviados aparecen en el ticket.
- [ ] Idempotencia, lock y error handling verificados en el smoke manual.
- [ ] `docs/operations/n8n/bot-workflow.json` y `docs/operations/whatsapp-bot-smoke.md` actualizados.
- [ ] `Mesa de Ayuda - COPC SA` archivado tras validar.

## 12. Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| ToS de OpenCode Zen para uso programático en producción 24/7 | El usuario confirma su plan; Chat Model intercambiable a Groq sin tocar el resto |
| Zen no expone `/v1/models` | Escribir model id a mano en el sub-nodo |
| Agente no determinista (crea antes de tiempo / divaga) | System prompt fuerte + gate de confirmación + validación del payload en el backend |
| `$fromAI` produce `subject`/`description` vacíos o demasiado largos | El backend valida (≤200 / ≤65535, no vacíos) y responde 400; el agente reintenta pidiendo el dato |
| Coste por conversación | Suscripción OpenCode; monitorear ejecuciones en n8n |
| Orden de mensajes (adjunto antes que texto, o viceversa) | Adjuntos se acumulan en Redis e independientemente del orden se incluyen al crear |

## 13. Follow-ups (fuera de alcance v1)

- Botones interactivos de WhatsApp para la confirmación (en vez de texto libre).
- Limpieza explícita de la memoria conversacional tras crear el ticket.
- Control de acceso por lista blanca de números autorizados.
- Capacidades de **consulta** (estado de tickets, comentarios de seguimiento) → requieren
  endpoints de lectura nuevos en el backend; serían una Fase 4.
- Métricas/observabilidad de las conversaciones del agente.
