# WhatsApp Bot Agéntico · Smoke Test Checklist

Casos manuales a ejecutar tras asignar credenciales y activar el workflow
**`Mesa de Ayuda - WhatsApp Bot`** (`lO3uLa8uKFTHFW1l`).

> Bot conversacional basado en AI Agent (Tools Agent). Reemplaza la FSM del
> workflow viejo `Mesa de Ayuda - COPC SA`. Diseño:
> `docs/superpowers/specs/2026-06-23-bot-agentico-whatsapp-design.md`.

## Pre-requisitos

- Workflow `Mesa de Ayuda - WhatsApp Bot` activo, y `Mesa de Ayuda - Auto Tagging` activo.
- Backend deployado con los endpoints de Fase 1 (`/webhooks/whatsapp/import`).
- **Credenciales en n8n asignadas** a los nodos del bot:
  - `Meta WhatsApp Trigger` (whatsAppTriggerApi) → recibir mensajes.
  - `Meta WhatsApp Bearer` (httpBearerAuth) → enviar mensajes a `graph.facebook.com`.
  - `OpenCode Zen` (openAiApi con Base URL `https://opencode.ai/zen/v1`) → modelo del agente.
  - `Mesa de Ayuda - WhatsApp Import Token` (httpHeaderAuth, `X-Webhook-Token`) → tool `create_ticket`.
  - `Redis` → memoria conversacional, lock y adjuntos.
- Settings backend: `whatsapp_enabled = 1`, `webhook_whatsapp_import_token` = el del Header Auth.
- Variable de entorno n8n `MESADEAYUDA_URL` apuntando al backend.

## Checklist (7 casos)

- [ ] **Caso 1 — Crear por texto.** Escribe un problema en lenguaje natural (ej. "la impresora del piso 3 no imprime desde ayer"). El agente debe resumir asunto + descripción y **pedir confirmación**; al confirmar, responde con el número de ticket. Verifica:
  - El ticket aparece en `/` con `channel=whatsapp` y `whatsapp_message_id` poblado.
- [ ] **Caso 2 — Crear con adjunto.** Igual al 1 pero envía una foto. Verifica:
  - El adjunto aparece en el ticket. (Verifica el flujo Meta media → base64 → Redis → `content_base64`.)
- [ ] **Caso 3 — Cancelar.** En la confirmación, responde que no. Verifica: NO se crea ticket.
- [ ] **Caso 4 — Sin confirmación.** Describe el problema pero NO confirmes. Verifica: el agente NO llama a `create_ticket` (no se crea ticket prematuro).
- [ ] **Caso 5 — Lock.** Envía dos mensajes muy seguidos. Verifica: el segundo recibe "Estoy procesando tu mensaje anterior…". (Verifica `Lock Get`/`Lock Set`/`Liberar Lock` en Redis.)
- [ ] **Caso 6 — Auto Tagging.** Tras crear el ticket (caso 1), verifica que el workflow `Auto Tagging` se disparó y aparecen tags en el ticket.
- [ ] **Caso 7 — Error transitorio.** Con el backend caído (`docker compose stop` del backend), completa el flujo y confirma. Verifica:
  - El usuario recibe "Ups, tuvimos un problema…". (Verifica el `onError` de `Enviar Respuesta WhatsApp` → `Notificar Error al Usuario` → `Liberar Lock (error)`.)
  - El lock de Redis queda liberado tras el error.

## Puntos a verificar en vivo (construidos sin poder ejecutar)

Estos detalles dependen del comportamiento en runtime de Redis/Meta/n8n y NO se
pudieron probar al construir el workflow (faltaban credenciales). Revisar en el
primer smoke y ajustar en n8n si hace falta:

1. **Lock por teléfono** — el patrón es `Redis GET` (propertyName `lockStatus`) → `If Lock Libre?` (operator string `empty`) → `Redis SET` con `expire`/`ttl 60`. Confirmar que `GET` sobre clave inexistente deja `lockStatus` vacío y que el `If` enruta correcto. (La idempotencia real de tickets ya la garantiza el backend vía índice único `whatsapp_message_id` + `Cache::add`, por eso no se duplicó en n8n.)
2. **Adjuntos** — `Get Media URL` → `Get Media Binary` (responseFormat file, prop `data`) → `Encode Base64 + Push` (`getBinaryDataBuffer`) → `Push Attachment` (Redis list) → `Convergencia Adjunto` (merge append) → `Load Attachments` (Redis get keyType list, `pendingAttRaw`) → `Parse Attachments` (JSON.parse de cada elemento) → tool `create_ticket` campo `attachments`. Verificar el shape de la lista Redis y que el array llega bien al body del tool.
3. **Modelo OpenCode Zen** — el id `opencode/gpt-5.5` está escrito a mano (Zen no expone `/v1/models`). Ajustar al id real disponible en tu cuenta. Confirmar que el tool-calling funciona (el agente invoca `create_ticket`).
4. **Salida del agente** — `Enviar Respuesta WhatsApp` usa `{{ JSON.stringify($json.output) }}`. Confirmar que el campo de salida del agente es `output`.
5. **Tool `create_ticket` en subnodo** — usa `nodeJson('Parse Data', …)` y `nodeJson('Parse Attachments', …)`. Confirmar que las expresiones resuelven dentro del tool (si no, mover la construcción del body a un Call n8n Workflow Tool, ver plan §Task 3 Step 5 fallback).

## Tras el smoke

- Marcar cada caso. Archivar `Mesa de Ayuda - COPC SA` (`YrY1cuaU5YobAUGu`) cuando el bot nuevo quede validado.
- Regenerar el snapshot `docs/operations/n8n/bot-agentico-workflow.js` si se ajustó algo en la UI.
