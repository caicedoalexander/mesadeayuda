# Bot WhatsApp Agéntico (Fase 3) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-06-23-bot-agentico-whatsapp-design.md`

**Goal:** Reemplazar la FSM del bot WhatsApp por un AI Agent (Tools Agent de n8n) que crea tickets de forma conversacional vía `POST /webhooks/whatsapp/import`, con idempotencia, lock y manejo de errores.

**Architecture:** Un único workflow nuevo en n8n. Una capa determinista de entrada (trigger Meta → parse → idempotencia → lock → preproceso de adjuntos) alimenta a un AI Agent con memoria conversacional en Redis y un único tool `create_ticket`. El LLM (OpenCode Zen, OpenAI-compatible) solo controla `subject`/`description`; teléfono, message_id y adjuntos se inyectan por expresión. No se escribe código de backend.

**Tech Stack:** n8n (vía `mcp__claude_ai_n8n__*`), Meta Cloud API (`graph.facebook.com/v24.0`), Redis 7, OpenCode Zen (LLM, OpenAI-compatible), CakePHP backend ya existente (no se modifica).

## Global Constraints

- **Sin código de backend nuevo.** Todo el trabajo es en n8n. El endpoint `POST /webhooks/whatsapp/import` ya existe y soporta `content_base64`.
- **Outbound del bot = Meta Cloud API** (`graph.facebook.com/v24.0`). No usar Evolution API en el bot.
- **LLM = OpenCode Zen**, credencial OpenAI con base URL `https://opencode.ai/zen/v1`, model id escrito a mano (ej. `opencode/gpt-5.5`).
- **El LLM solo controla `subject` y `description`.** `message_id`, `phone_number`, `contact_name` y `attachments` se inyectan por expresión determinista; el agente nunca los fabrica.
- **Confirmación obligatoria** antes de invocar `create_ticket` (vía system prompt).
- **Workflow nuevo** `Mesa de Ayuda - WhatsApp Bot`. El viejo `Mesa de Ayuda - COPC SA` (`YrY1cuaU5YobAUGu`) se archiva tras validar.
- **Disciplina n8n SDK (obligatoria por cada task que toque n8n):** `get_sdk_reference` (solo la 1ª vez en la sesión) → `search_nodes` → `get_node_types` para CADA node id usado → construir → `validate_workflow` hasta verde → `create_workflow_from_code`/`update_workflow` → `get_workflow_details` para verificar.
- **Versiones de nodos a fijar:** `n8n-nodes-base.whatsAppTrigger` v1 · `n8n-nodes-base.code` v2 · `n8n-nodes-base.redis` v1 · `n8n-nodes-base.if` v2.3 · `n8n-nodes-base.httpRequest` v4.3 · `@n8n/n8n-nodes-langchain.agent` v3.1 · `@n8n/n8n-nodes-langchain.lmChatOpenAi` v1.3 · `@n8n/n8n-nodes-langchain.memoryRedisChat` v1.6 · `@n8n/n8n-nodes-langchain.toolHttpRequest` v1.1.
- **AI Agent (builder hint):** los subnodos (model/memory/tool) se cablean vía el config `subnodes` con las factory functions `languageModel()`, `memory()`, `tool()`. Dentro de un subnodo, referenciar datos upstream con `nodeJson('NodeName', 'path')`, NO `$json`.
- **No activar** ningún workflow salvo en el Task operacional final (Task 8).
- **No hacer `git push`.** Commits en la rama `feature/bot-agentico-whatsapp`. Versionar los dumps JSON bajo `docs/operations/n8n/`.
- Los tasks de n8n no son TDD; su ciclo de verificación es `validate_workflow` + `get_workflow_details` + (al final) smoke manual.

---

## File Structure

| Acción | Recurso | Responsabilidad |
|---|---|---|
| Create | Workflow n8n `Mesa de Ayuda - WhatsApp Bot` | El bot agéntico completo |
| Create | `docs/operations/n8n/bot-agentico-workflow.json` | Dump versionado del workflow |
| Modify | `docs/operations/whatsapp-bot-smoke.md` | Checklist smoke adaptado al agente |
| Archive | Workflow `YrY1cuaU5YobAUGu` (COPC SA) | Se archiva tras validar (operacional) |

Sin archivos de código fuente: el backend no se toca.

---

## Task 0: Pre-requisitos de configuración en n8n y backend

**Recursos:** n8n (credenciales + variable de entorno), backend settings.

Este task no produce commit de código; valida que el entorno está listo. Reporta el estado de cada ítem.

- [ ] **Step 1: Verificar/crear credencial OpenAI para OpenCode Zen**

Run: `mcp__claude_ai_n8n__list_credentials` con `type: "openAi"`.
Si no existe una credencial OpenAI apuntando a OpenCode Zen, el usuario debe crearla en la UI de n8n (no es creable vía MCP sin el secreto):
- Tipo: OpenAI
- API Key: la generada en la cuenta de OpenCode
- Base URL: `https://opencode.ai/zen/v1`

Reportar el `id` de la credencial (se usa en Task 3). Si el usuario no la ha creado, marcar BLOCKED con la instrucción exacta.

- [ ] **Step 2: Verificar credenciales Meta Cloud API**

Run: `mcp__claude_ai_n8n__list_credentials`.
Confirmar que existe la credencial Bearer de Meta (el bot viejo `YrY1cuaU5YobAUGu` la usa en sus nodos `Enviar Texto`/`Enviar Botones` vía `httpBearerAuth`). Anotar su `id` y el `phone_number_id` de Meta (en el bot viejo está hardcodeado como `964222420097122` en los nodos WhatsApp). Reportar ambos.

- [ ] **Step 3: Verificar credencial Header Auth del webhook de import**

Run: `mcp__claude_ai_n8n__list_credentials` con `type: "httpHeaderAuth"`.
Confirmar que existe una credencial Header Auth con el header `X-Webhook-Token` = `webhook_whatsapp_import_token` del backend. Si no existe, el usuario la crea en la UI. Anotar el `id` (se usa en Task 3).

- [ ] **Step 4: Verificar variable de entorno y settings backend**

Confirmar (preguntando al usuario, no hay tool para leerlo) que:
- La variable de entorno n8n `MESADEAYUDA_URL` apunta al backend (ej. `https://mesadeayuda.example`).
- El setting backend `whatsapp_enabled = 1`.
- El setting backend `webhook_whatsapp_import_token` coincide con la credencial del Step 3.

Reportar estado. Si algo falta, marcar BLOCKED con la lista de lo que falta.

---

## Task 1: Crear el workflow base — Trigger + Parse + Idempotencia + Lock

**Recursos:** n8n MCP. Crea el workflow inicial (la capa determinista de entrada). Sin agente todavía.

### Step 1: Cargar SDK reference (solo 1ª vez en la sesión)

Run: `mcp__claude_ai_n8n__get_sdk_reference` (sección por defecto). Leerlo completo antes de generar código.

### Step 2: Obtener type definitions de los nodos de entrada

Run: `mcp__claude_ai_n8n__get_node_types` con:
```
[
  "n8n-nodes-base.whatsAppTrigger",
  { "nodeId": "n8n-nodes-base.code", "mode": "runOnceForAllItems" },
  { "nodeId": "n8n-nodes-base.redis", "operation": "set" },
  { "nodeId": "n8n-nodes-base.redis", "operation": "get" },
  { "nodeId": "n8n-nodes-base.redis", "operation": "incr" },
  "n8n-nodes-base.if",
  { "nodeId": "n8n-nodes-base.httpRequest" }
]
```
Anotar los parámetros exactos. **Verificar específicamente** si la operation `set` del nodo Redis expone una opción tipo "NX / only if not exists" y una opción de TTL. De esto depende el Step 4.

### Step 3: Código del nodo `Parse Data` (Code, runOnceForAllItems)

Este código extrae los datos del webhook de Meta. Adaptado del bot viejo, simplificado a lo necesario.

```js
// Parse Data — normaliza el webhook entrante de Meta Cloud API.
// Ignora callbacks de status (delivery/read) devolviendo [] (no procesar).
if ($input.first().json.statuses) {
  return [];
}

const value = $input.first().json;
const metadata = value.metadata;
const message = value.messages[0];
const contact = value.contacts && value.contacts[0];

const phoneNumber = message.from;                       // E.164 sin '+' según Meta
const userName = (contact && contact.profile && contact.profile.name) || 'Usuario';
const messageId = message.id;                           // wamid... (idempotencia)
const timestamp = message.timestamp;
const messageType = message.type;

let messageContent = '';
let mediaData = null;

if (messageType === 'text') {
  messageContent = message.text?.body || '';
} else if (messageType === 'interactive') {
  messageContent = message.interactive?.button_reply?.id
    || message.interactive?.list_reply?.id
    || '';
} else if (['image', 'document', 'video', 'audio', 'voice'].includes(messageType)) {
  const media = message[messageType];
  messageContent = media.caption || '';
  const extByType = { image: 'jpg', video: 'mp4', audio: 'ogg', voice: 'ogg' };
  mediaData = {
    id: media.id,
    mime: media.mime_type,
    filename: media.filename || `${messageType}_${media.id}.${extByType[messageType] || 'bin'}`,
  };
}

const phoneNumberId = metadata.phone_number_id;
const apiVersion = 'v24.0';

return [{
  json: {
    phoneNumber,
    userName,
    messageId,
    timestamp,
    messageType,
    messageContent,
    hasAttachment: mediaData !== null,
    mediaId: mediaData?.id || null,
    mediaMime: mediaData?.mime || null,
    mediaFilename: mediaData?.filename || null,
    // URL para responder mensajes salientes a Meta:
    sendUrl: `https://graph.facebook.com/${apiVersion}/${phoneNumberId}/messages`,
    // URL para resolver media (Task 2):
    mediaUrl: mediaData ? `https://graph.facebook.com/${apiVersion}/${mediaData.id}` : null,
  },
}];
```

### Step 4: Idempotencia y lock (Redis)

Diseñar dos checks. **Preferir atomicidad**; si el Step 2 confirmó que `set` soporta NX+TTL, usarlo. Si no, usar el patrón `incr` (atómico) o `get`→`if`→`set`, sabiendo que el backend (unique index `whatsapp_message_id` + `Cache::add` por `message_id` → 409) es la red de seguridad real, así que el dedupe de Redis es best-effort para no gastar tokens.

**Idempotencia (tras `Parse Data`):**
```
[Redis "Dedupe Message"]
   key: ={{ "mesadeayuda:msg:" + $json.messageId }}
   (NX+TTL 86400 si disponible; si no: incr y luego set TTL, o get/if/set)
   → [If "Mensaje ya procesado"]
        true  → [NoOp "Salir (duplicado)"]
        false → continúa al lock
```

**Lock por teléfono (tras idempotencia):**
```
[Redis "Lock Phone"]
   key: ={{ "mesadeayuda:lock:" + $json.phoneNumber }}
   value: ={{ $json.messageId }}
   (NX+TTL 60 si disponible; si no: get/if/set)
   → [If "Lock adquirido"]
        false → [HTTP POST Meta "procesando…"] (ver body abajo) → [NoOp "Salir (lock)"]
        true  → continúa (Task 2 enlazará aquí)
```

Body del aviso "procesando" (nodo `httpRequest`, POST a `={{ $('Parse Data').item.json.sendUrl }}`, auth Bearer Meta, body JSON):
```js
{
  messaging_product: "whatsapp",
  recipient_type: "individual",
  to: "={{ $('Parse Data').item.json.phoneNumber }}",
  type: "text",
  text: { preview_url: false, body: "⏳ Estoy procesando tu mensaje anterior, dame unos segundos…" }
}
```

### Step 5: Construir, validar y crear el workflow

Construir el código SDK con: WhatsApp Trigger → Parse Data → Dedupe → If → Lock → If, con las dos ramas de salida temprana (NoOp). La salida `true` del lock queda como punto de extensión para Task 2 (puede terminar provisionalmente en un NoOp `"(continúa Task 2)"`).

Run: `mcp__claude_ai_n8n__validate_workflow` con el código. Iterar hasta verde.
Run: `mcp__claude_ai_n8n__create_workflow_from_code`:
- Nombre: `Mesa de Ayuda - WhatsApp Bot`
- Descripción: `Bot WhatsApp agéntico (Tools Agent). Crea tickets vía POST /webhooks/whatsapp/import con idempotencia, lock y manejo de errores.`

Permanece `active:false`. Anotar el `workflowId` retornado (se usa en todos los tasks siguientes — referirlo como `BOT_WF_ID`).

### Step 6: Verificar

Run: `mcp__claude_ai_n8n__get_workflow_details` con `BOT_WF_ID`. Confirmar que existen los nodos del Step 5 y las conexiones de salida temprana.

### Step 7: Dump + commit

Guardar el JSON completo (vía Write) en `docs/operations/n8n/bot-agentico-workflow.json`.
```bash
git add docs/operations/n8n/bot-agentico-workflow.json
git commit -m "feat(n8n): bot agentico base (trigger+parse+idempotencia+lock)"
```

---

## Task 2: Rama de adjuntos (Meta media → base64 → Redis)

**Recursos:** Workflow `BOT_WF_ID`.

Cuando un mensaje trae media, se descarga de Meta (Bearer), se pasa a base64 y se acumula en una lista Redis por teléfono. Independiente del orden (texto antes/después).

### Step 1: get_node_types (si faltan)

Si no se obtuvieron en Task 1, run `get_node_types` para `n8n-nodes-base.httpRequest` (GET file) y `n8n-nodes-base.redis` (operation `push`, `get`, `delete`).

### Step 2: Diseñar la rama

Desde la salida `true` del lock:
```
[If "¿Trae adjunto?"]   condición: ={{ $('Parse Data').item.json.hasAttachment }} === true
   true →
     [HTTP GET media URL]   url: ={{ $('Parse Data').item.json.mediaUrl }}   auth: Bearer Meta
        → devuelve { url: <signed>, mime_type, file_size, ... }
     [HTTP GET binario]     url: ={{ $json.url }}   auth: Bearer Meta
        opciones: response → responseFormat: file  (propiedad binaria "data")
     [Code "Encode Base64 + Guard"]   (ver Step 3)
     [Redis "Push Attachment"]   operation: push
        key: ={{ "mesadeayuda:att:" + $('Parse Data').item.json.phoneNumber }}
        value: ={{ JSON.stringify($json.attachment) }}
        (setear TTL del key si el nodo lo permite, ~3600; si no, se limpia en Task 4)
        → continúa al agente (Task 3) con input sintético
   false → continúa al agente con el texto del usuario
```

Ambas ramas convergen en el agente (Task 3). El input textual del agente se prepara en un nodo `Set`/`Code` "Build Agent Input":
- Si `hasAttachment`: `messageContent || "[El usuario adjuntó " + mediaFilename + " (" + mediaMime + ")]"`.
- Si no: `messageContent`.

### Step 3: Código `Encode Base64 + Guard` (Code, runOnceForEachItem)

```js
// Convierte el binario descargado a base64 y aplica guard de tamaño.
const MAX_BYTES = 10 * 1024 * 1024; // espejo de GenericAttachmentTrait::MAX_FILE_SIZE (10 MiB)

const binary = $input.item.binary?.data;
if (!binary) {
  // Sin binario: no adjuntar nada.
  return [{ json: { attachment: null, skipped: true, reason: 'no_binary' } }];
}

const buffer = await this.helpers.getBinaryDataBuffer($input.item.index, 'data');
const size = buffer.length;
const parsed = $('Parse Data').item.json;

if (size > MAX_BYTES) {
  return [{ json: { attachment: null, skipped: true, reason: 'too_large', size } }];
}

return [{
  json: {
    attachment: {
      filename: parsed.mediaFilename,
      mime: parsed.mediaMime,
      size,
      content_base64: buffer.toString('base64'),
    },
  },
}];
```

Nota: si el guard marca `skipped: too_large`, enrutar (vía un `If`) a un `HTTP POST Meta` que avise "📎 El archivo supera el límite de 10 MB y no se adjuntó; describe el problema por texto." y NO hacer push a Redis.

### Step 4: Build, validate, update, verify

Construir el fragmento, `validate_workflow` (iterar), `update_workflow` con `BOT_WF_ID` (operaciones addNode/addConnection), `get_workflow_details` para confirmar que la rama de adjuntos existe y converge hacia el punto del agente.

### Step 5: Dump + commit

Re-dump a `docs/operations/n8n/bot-agentico-workflow.json`.
```bash
git add docs/operations/n8n/bot-agentico-workflow.json
git commit -m "feat(n8n): rama de adjuntos Meta media -> base64 -> Redis"
```

---

## Task 3: El AI Agent (model OpenCode Zen + memoria Redis + tool create_ticket)

**Recursos:** Workflow `BOT_WF_ID`. Credenciales del Task 0.

### Step 1: get_node_types de los nodos del agente

Run: `mcp__claude_ai_n8n__get_node_types` con:
```
[
  "@n8n/n8n-nodes-langchain.agent",
  "@n8n/n8n-nodes-langchain.lmChatOpenAi",
  "@n8n/n8n-nodes-langchain.memoryRedisChat",
  "@n8n/n8n-nodes-langchain.toolHttpRequest"
]
```
Leer cómo el SDK declara `subnodes` (factory functions `languageModel()`, `memory()`, `tool()`). Confirmar cómo el `lmChatOpenAi` recibe base URL/credencial y cómo se fija el model id manual.

### Step 2: Chat Model — OpenCode Zen

Sub-nodo `lmChatOpenAi` v1.3:
- Credencial: la OpenAI del Task 0 (base URL `https://opencode.ai/zen/v1`).
- Model: escrito a mano (ej. `opencode/gpt-5.5`). Si el SDK exige un valor de lista, usar el campo de expresión/string para forzar el id.
- Options: `temperature` ~0.3 (respuestas estables).

### Step 3: Memory — Redis Chat Memory

Sub-nodo `memoryRedisChat` v1.6:
- `sessionKey`: ={{ $('Parse Data').item.json.phoneNumber }}  (verificar en subnode usar `nodeJson('Parse Data','phoneNumber')` según builder hint).
- `sessionTTL`: 3600.
- Credencial Redis (la misma que usan los nodos Redis del workflow).

### Step 4: Tool — create_ticket (toolHttpRequest v1.1)

Configurar el HTTP Request Tool:
- Method: POST
- URL: `={{ $env.MESADEAYUDA_URL }}/webhooks/whatsapp/import`
- Auth: Header Auth (credencial del Task 0, `X-Webhook-Token`).
- Body (JSON). Campos del LLM via `$fromAI`; el resto por expresión determinista (en subnode, preferir `nodeJson('Parse Data', ...)`):
  - `subject`      ← `$fromAI('subject', 'Asunto corto y descriptivo del ticket', 'string')`
  - `description`  ← `$fromAI('description', 'Descripción detallada del problema del usuario', 'string')`
  - `message_id`   ← `={{ nodeJson('Parse Data', 'messageId') }}`
  - `phone_number` ← `={{ nodeJson('Parse Data', 'phoneNumber') }}` (el backend normaliza a E.164 anteponiendo '+')
  - `contact_name` ← `={{ nodeJson('Parse Data', 'userName') }}`
  - `attachments`  ← cargados de Redis (ver Step 5)
- Tool description (para el agente): "Crea un ticket de soporte. Úsalo SOLO después de que el usuario confirme. Provee subject y description."

### Step 5: Inyección de adjuntos en el tool

El tool no puede leer Redis directamente. Antes del agente, añadir un nodo `Redis "Load Attachments"` (operation `get`/`llen`+`pop` o `keys`; lo más simple: leer la lista con `get`/`llen` y traerla como array) que deje los adjuntos disponibles, y referenciarlos en el body del tool con `nodeJson('Load Attachments', 'attachments')`. El body debe enviar `[]` si no hay.

Verificación explícita: si las expresiones de subnode no resuelven `nodeJson('Load Attachments', ...)` correctamente (porque el nodo no es el trigger), aplicar el **fallback**: convertir `create_ticket` en un **Call n8n Workflow Tool** que recibe `subject`/`description` del agente y `phone`/`message_id` por parámetro, lee los adjuntos de Redis dentro del sub-workflow, arma el body y hace el POST. Documentar cuál de las dos se usó.

### Step 6: System prompt del agente

```
Eres el asistente virtual de la Mesa de Ayuda de soporte interno. Conversas por WhatsApp en español, de forma cordial y concisa.

Tu ÚNICA función es ayudar al usuario a CREAR un ticket de soporte. No puedes consultar el estado de tickets existentes ni hacer otras gestiones; si te lo piden, explícalo con amabilidad y ofrece crear un ticket.

Flujo que debes seguir:
1. Saluda brevemente y pregunta en qué puedes ayudar (si el usuario ya describió su problema, no repreguntes lo obvio).
2. Asegúrate de tener dos cosas: un ASUNTO corto (una frase) y una DESCRIPCIÓN con el detalle suficiente para que soporte entienda el problema. Si falta detalle, pide lo mínimo necesario, una pregunta a la vez.
3. Si el usuario adjuntó archivos, verás notas como "[El usuario adjuntó foto.jpg]". Reconócelos; se incluirán automáticamente en el ticket. No pidas que reenvíen archivos ya adjuntados.
4. Antes de crear el ticket, RESUME el asunto y la descripción y pide confirmación explícita (por ejemplo: "¿Confirmo la creación del ticket con estos datos?").
5. Solo cuando el usuario confirme, usa la herramienta create_ticket con subject y description. NUNCA la uses sin confirmación.
6. Tras crear el ticket, comunica el número de ticket que devuelve la herramienta y despídete ofreciendo ayuda futura.

Reglas:
- No inventes datos. No pidas ni manejes números de teléfono, IDs ni datos internos: el sistema los añade solo.
- Si el usuario cambia un dato ("mejor el asunto es X"), actualízalo antes de confirmar.
- Si la herramienta create_ticket devuelve un error, discúlpate y pide que lo intente de nuevo en unos minutos.
- Sé breve: mensajes cortos, sin formato Markdown pesado (WhatsApp es texto plano).
```

### Step 7: Cablear y conectar

Cablear el agente con `subnodes: { languageModel: ..., memory: ..., tool: [create_ticket] }`. Conectar el flujo principal: salida del preproceso (texto / adjunto) → `Build Agent Input` → `Load Attachments` → AI Agent.

### Step 8: Build, validate, update, verify

`validate_workflow` (iterar hasta verde) → `update_workflow` con `BOT_WF_ID` → `get_workflow_details` confirmando que el agente tiene model, memory y el tool cableados.

### Step 9: Dump + commit

```bash
git add docs/operations/n8n/bot-agentico-workflow.json
git commit -m "feat(n8n): AI Agent (OpenCode Zen + Redis memory + tool create_ticket)"
```

---

## Task 4: Respuesta al usuario + liberación de lock + limpieza de adjuntos

**Recursos:** Workflow `BOT_WF_ID`.

### Step 1: Enviar la salida del agente a WhatsApp

Tras el AI Agent, añadir `HTTP POST Meta` (httpRequest, auth Bearer Meta):
- URL: `={{ $('Parse Data').item.json.sendUrl }}`
- Body JSON:
```js
{
  messaging_product: "whatsapp",
  recipient_type: "individual",
  to: "={{ $('Parse Data').item.json.phoneNumber }}",
  type: "text",
  text: { preview_url: false, body: "={{ $json.output }}" }  // salida de texto del agente
}
```
(Confirmar el nombre del campo de salida del agente — típicamente `output` — vía `get_node_types`/`get_workflow_details`.)

### Step 2: Limpieza tras crear + liberar lock

Añadir al final del turno (después de enviar la respuesta):
```
[Redis "DEL lock"]   operation: delete   key: ={{ "mesadeayuda:lock:" + $('Parse Data').item.json.phoneNumber }}
```
Para los adjuntos: limpiar `mesadeayuda:att:{phone}` cuando el ticket se creó. Como saber si el tool corrió es indirecto, la estrategia v1 es: limpiar la lista de adjuntos en el mismo punto que el lock (fin de turno) **solo si** el turno llamó create_ticket con éxito. Si esa detección no es fiable en n8n, dejar que el TTL del key (Step de Task 2) la limpie y documentarlo como follow-up. Implementar la opción fiable disponible y anotar cuál.

### Step 3: Build, validate, update, verify

`validate_workflow` → `update_workflow` → `get_workflow_details` (confirmar envío de respuesta + DEL lock en el path de éxito).

### Step 4: Dump + commit

```bash
git add docs/operations/n8n/bot-agentico-workflow.json
git commit -m "feat(n8n): enviar respuesta del agente + liberar lock"
```

---

## Task 5: Manejo de errores end-to-end

**Recursos:** Workflow `BOT_WF_ID`.

### Step 1: Sub-flujo "Notificar Error al Usuario"

Añadir un sub-grafo (sin trigger) invocado desde puntos de error:
```
[Code "Build Error Message"]
   → [HTTP POST Meta "Enviar error"]
   → [Redis DEL lock]
```
Código `Build Error Message` (Code):
```js
const phone = $('Parse Data').first().json.phoneNumber;
const sendUrl = $('Parse Data').first().json.sendUrl;
return [{
  json: {
    sendUrl,
    payload: {
      messaging_product: "whatsapp",
      recipient_type: "individual",
      to: phone,
      type: "text",
      text: {
        preview_url: false,
        body: "⚠️ Ups, tuvimos un problema procesando tu solicitud. Por favor reintenta en unos minutos. Si persiste, contacta a soporte.",
      },
    },
  },
}];
```
El `HTTP POST Meta "Enviar error"` postea a `={{ $json.sendUrl }}` con body `={{ $json.payload }}`.

### Step 2: Conectar puntos de error

En cada nodo HTTP crítico (descarga de media en Task 2, y — si se usó — el POST del tool / sub-workflow), activar **"Continue On Fail"** y enrutar la salida de error (vía un `If "¿hubo error?"`) hacia `Build Error Message`. El AI Agent: activar su manejo de error de modo que un fallo del modelo/tool no deje al usuario sin respuesta (enrutar al sub-flujo de error).

### Step 3: Build, validate, update, verify

`validate_workflow` → `update_workflow` → `get_workflow_details` (confirmar que los nodos críticos tienen Continue On Fail y conectan al sub-flujo de error).

### Step 4: Dump + commit

```bash
git add docs/operations/n8n/bot-agentico-workflow.json
git commit -m "feat(n8n): manejo de errores end-to-end con aviso al usuario"
```

---

## Task 6: Actualizar el checklist de smoke manual

**Files:**
- Modify: `docs/operations/whatsapp-bot-smoke.md`

### Step 1: Reescribir los casos al modelo conversacional

Reemplazar los casos basados en FSM/botones por los del agente. Mantener pre-requisitos (ahora incluyendo credencial OpenAI de OpenCode Zen y model id). Casos:

```markdown
- [ ] Caso 1 — Crear por texto: describe un problema en lenguaje natural → el agente resume y pide confirmación → confirmar → el bot responde con el número de ticket. Verifica: ticket en `/` con channel=whatsapp y whatsapp_message_id poblado.
- [ ] Caso 2 — Crear con adjunto: igual al 1 pero envía una foto → aparece como adjunto del ticket.
- [ ] Caso 3 — Cancelar: en la confirmación, el usuario dice que no → NO se crea ticket.
- [ ] Caso 4 — Idempotencia: reenvío del mismo mensaje (Meta) → no se duplica el procesamiento ni el ticket.
- [ ] Caso 5 — Lock: dos mensajes muy seguidos → el segundo recibe "procesando…".
- [ ] Caso 6 — Auto Tagging: tras crear el ticket, el workflow Auto Tagging se dispara y aparecen tags.
- [ ] Caso 7 — Error transitorio: con el backend caído, completa el flujo → el usuario recibe el mensaje de error; Redis (lock) queda limpio.
```

### Step 2: Commit

```bash
git add docs/operations/whatsapp-bot-smoke.md
git commit -m "docs(operations): smoke checklist adaptado al bot agentico"
```

---

## Task 7: Re-dump final + verificación de criterios

**Recursos:** Workflow `BOT_WF_ID`.

### Step 1: Re-dump

Run: `mcp__claude_ai_n8n__get_workflow_details` con `BOT_WF_ID`. Guardar el JSON final en `docs/operations/n8n/bot-agentico-workflow.json`.

### Step 2: Verificar criterios de éxito del spec (§11)

Confirmar, leyendo el dump: no hay nodos de FSM (`Switch` de estado), el tool apunta a `/webhooks/whatsapp/import`, el agente tiene model+memory+tool, existen idempotencia/lock/error handling. Anotar cualquier desvío.

### Step 3: Commit

```bash
git add docs/operations/n8n/bot-agentico-workflow.json
git commit -m "docs(n8n): snapshot final del bot agentico"
```

---

## Task 8: Activación y archivado (operacional, sin commit)

**Files:** ninguno.

### Step 1: Validación pre-activación

Ejecutar el workflow `Mesa de Ayuda - Smoke Tests` (manual) para confirmar que los endpoints backend responden. Verificar 5/5 asserts.

### Step 2: Activar el bot

Activar `Mesa de Ayuda - WhatsApp Bot` (`active:true`) en la UI de n8n. Confirmar que el webhook de Meta apunta a este workflow (no al viejo). `Auto Tagging` ya está activo.

### Step 3: Ejecutar el smoke manual

Seguir `docs/operations/whatsapp-bot-smoke.md` con un número real. Marcar cada caso.

### Step 4: Archivar el bot viejo

Tras smoke exitoso, archivar `Mesa de Ayuda - COPC SA` (`YrY1cuaU5YobAUGu`) (`archive_workflow` o toggle en UI). Mantenerlo inactivo (no borrar) como referencia histórica.

### Step 5: Reportar

Status: DONE | partial | ROLLED_BACK. Si un caso falla: capturar logs n8n + backend, decidir rollback (bot a `active:false`, reactivar el viejo) o fix-forward.

---

## Self-Review (cobertura del spec)

- Alcance "solo crear, conversacional" → Tasks 1-5 (agente + tool create_ticket). ✓
- Arquitectura agente puro → Task 3. ✓
- LLM OpenCode Zen base URL custom → Task 0 (credencial) + Task 3 (Chat Model). ✓
- Adjuntos Meta→base64→content_base64 → Task 2 + Task 3 Step 5. ✓
- Confirmación antes de crear → Task 3 Step 6 (system prompt). ✓
- Idempotencia + lock → Task 1 Step 4. ✓
- Error handling end-to-end → Task 5. ✓
- LLM solo controla subject/description → Task 3 Step 4. ✓
- Crear workflow nuevo + archivar viejo → Task 1 + Task 8. ✓
- Dumps versionados + smoke doc → Tasks 1-7 + Task 6. ✓
- Auto Tagging intacto (se dispara solo) → no se toca; verificado en smoke Caso 6. ✓

Riesgos del spec con punto de verificación en el plan: NX de Redis (Task 1 Step 2/4), expresiones de subnode `nodeJson` + fallback a Call Workflow Tool (Task 3 Step 5), nombre del campo de salida del agente (Task 4 Step 1), detección de "tool ejecutado" para limpiar adjuntos (Task 4 Step 2). Todos quedan con instrucción explícita de verificar y fallback.
