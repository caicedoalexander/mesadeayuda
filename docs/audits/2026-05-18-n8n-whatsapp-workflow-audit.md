# Auditoría · Workflow n8n "Mesa de Ayuda - COPC SA"

- **Fecha**: 2026-05-18
- **Workflow ID**: `YrY1cuaU5YobAUGu`
- **Estado**: `active: false` (no en producción)
- **Versión auditada**: `f2d7cf52-0fc4-4608-95fe-9aa59ca4a174`
- **Estado de resolución**: #2, #3, #9 — **resueltos en Fase 1** (ver `docs/superpowers/plans/2026-05-19-n8n-whatsapp-audit-fase-1.md`). #1, #4, #5, #6, #7, #8, #10 pendientes (Fase 2/3).

El workflow mezcla dos funciones independientes con triggers distintos y cero estado compartido.

---

## 1. El workflow está haciendo dos cosas que no deberían convivir

| Función | Trigger | Camino |
|---|---|---|
| **Bot WhatsApp** | `WhatsApp Trigger` → Redis FSM | Recolecta asunto/descripción/archivos y envía email a `mesadeayuda.email@gmail.com` |
| **Asignación de Tags** | Webhook `POST /asignación-tags-mesa-de-ayuda` | LLM (Groq) → INSERT a `tickets_tags` |

No comparten payload, contexto ni dependencias. Mantenerlos juntos cuesta:

- **Rendimiento**: cualquier ejecución del bot mantiene el workflow caliente y ocupa el worker; un pico en WhatsApp puede competir con tagging que es síncrono al webhook del backend.
- **Despliegue**: cualquier cambio (incluso renombrar un nodo del bot) genera nueva `versionId` del workflow entero. Ya existe el patrón correcto en `Mesa de Ayuda - Gmail Import Trigger` separado.
- **Observabilidad**: las métricas por workflow se contaminan. Un fallo de Groq aparece en el mismo dashboard que un timeout de WhatsApp Cloud API.
- **Permisos / credenciales**: WhatsApp + Gmail + MySQL + Groq + (futuro) OpenAI en un solo workflow. Mucho blast radius.

**Recomendación**: separar en `Mesa de Ayuda - WhatsApp Bot` y `Mesa de Ayuda - Auto Tagging`. Cada uno con su lifecycle.

---

## 2. Problemas arquitecturales (críticos)

### 2.1 El bot bypassa la Mesa de Ayuda

Al final del flujo del bot, `Enviar Ticket` / `Enviar Ticket con Archivos` mandan un **email** a `mesadeayuda.email@gmail.com` para que entre por el pipeline de Gmail Import. Esto:

- Acopla WhatsApp al ingest de Gmail (cualquier cambio en `TicketIngestionService` afecta WhatsApp).
- Pierde el canal de origen (en la BD aparecerá como ticket de Gmail, no de WhatsApp).
- Añade latencia (espera el cron de 5 min del Gmail Import Trigger).
- Imposibilita adjuntar metadata del bot (phoneNumber, sessionId, etc.) más allá del cuerpo.

**Recomendación**: exponer en CakePHP un endpoint análogo al de Gmail, p.ej. `POST /webhooks/whatsapp/import`, con su CSRF skipped (patrón ya en `config/routes.php`). El bot postea directo. El backend resuelve `Channel`, llama `NumberGenerationService` y dispara `TicketCreated` con `channel='whatsapp'`. Esto respeta el modelo de eventos del CLAUDE.md.

### 2.2 INSERT directo a `tickets_tags` viola `AuditBehavior`

`Insert rows in a table` ejecuta SQL directo a MySQL. CLAUDE.md es explícito:

> **Never bypass [AuditBehavior] when mutating audited entities** — go through the Table layer; don't issue raw SQL updates that skip behaviors.

Esto significa que las asignaciones automáticas de tags **no aparecen en el `*_history`**. Es un agujero de auditoría.

**Recomendación**: el workflow de tagging debe terminar con un `HTTP POST` a un endpoint nuevo `POST /webhooks/tickets/{id}/tags` que internamente use el Table layer. n8n se queda solo con la inferencia LLM y la llamada HTTP.

### 2.3 Inconsistencia con el canal WhatsApp del backend

El nodo `Parse Data Whatsapp` y los `Enviar Botones/Texto` llaman a `graph.facebook.com/v24.0` (WhatsApp Cloud API de Meta). Pero CLAUDE.md dice:

> Native integrations with Gmail API, n8n (webhooks), and WhatsApp (**Evolution API**).

O bien la doc está desactualizada, o hay dos integraciones de WhatsApp distintas en producción. Cualquiera de las dos es un problema (decidir cuál es la canónica).

---

## 3. El bot WhatsApp: la FSM en n8n es deuda técnica

Estructura actual: **7 estados** (`start`, `awaiting_subject`, `awaiting_description`, `awaiting_file`, `saved_files`, `awaiting_confirmation`, …) modelados con:

- 1 Switch principal de 7 ramas.
- 3 sub-Switches (`Switch2`, `Switch3`, `Switch4`) para distinguir botones interactivos.
- **11 nodos Code casi idénticos** que solo cambian el texto del mensaje, los botones y el siguiente `state`.
- Redis con TTL 3600s para sesión.

Problemas concretos:

1. **Replicación del payload de WhatsApp**: cada uno de los 11 Code arma el mismo `whatsappPayload = { messaging_product: "whatsapp", recipient_type: "individual", to: ..., type: "interactive"/"text", ... }`. Cambiar el formato (p. ej. añadir un footer común) implica editar 11 nodos.

2. **`session.attachments` como string JSON** dentro de Redis: cada handler hace `JSON.parse` → mutar → `JSON.stringify`. Frágil (ya tiene un `try/catch` defensivo en `Almacenar Archivo` precisamente porque ya falló). Soluciones: usar `RedisJSON` (`JSON.SET`), `HSET` por campo, o guardar la sesión completa serializada con un solo punto de parse en `Redis Get Session`.

3. **Race condition Redis GET → Code → Redis UPDATE**: dos mensajes consecutivos del mismo número entran como dos ejecuciones paralelas del workflow, ambas leen el mismo state, ambas escriben — la segunda gana. Necesitas un lock (`SET NX EX`) por `phoneNumber` al inicio.

4. **Validación cero**: el asunto puede ser un sticker, una imagen sin caption (caería como string vacío), 5000 caracteres, un comando con tildes mal codificadas (`"añadir"` tiene ñ → trampa para comparaciones).

5. **Sin idempotencia**: WhatsApp Cloud API reenvía webhooks. No hay deduplicación por `messageId`.

6. **Sin manejo de errores**: si Groq, WhatsApp API o Redis fallan, el flujo se cae silenciosamente sin notificar al usuario ni reintentar.

7. **Doble rama "con archivos" / "sin archivos"** (`Switch1` → `Enviar Ticket` vs `Enviar Ticket con Archivos`) duplica el envío de email. Es consolidable.

8. **"No entiendo"** como fallback débil: en `awaiting_subject` cualquier texto se acepta como asunto, incluyendo "cancelar" o "menu". No hay vía de escape mid-flow.

9. **Código muerto**: el nodo `OpenAI` (deshabilitado), `Asignación de Agente` (webhook + Set, ambos deshabilitados). Vestigios que confunden.

---

## 4. ¿Migrar a un Agent?

**Sí, pero con matices.** Un AI Agent (Tools Agent en n8n) cambiaría la conversación de **árbol de decisión rígido** a **lenguaje natural con tools**:

```
Tools del agente:
- create_ticket(subject, description, attachments[])
- add_attachment(media_id) → registra en session
- get_session_state()
- cancel_session()
- send_whatsapp_message(text|buttons)
```

**Ventajas**:

- Adiós a las 11 ramas y los 4 Switches. Una sola conversación.
- El usuario puede decir "se me olvidó, el asunto en realidad es X" y el agente corrige; la FSM actual no lo permite.
- Comprende texto libre ("tengo un problema con la impresora del piso 3 desde ayer y te mando una foto") sin guiar paso a paso.
- Memoria conversacional vía LangChain Memory en Redis (que ya está disponible).

**Desventajas / riesgos**:

- **Costo por mensaje**: cada turno consume tokens (con Groq es barato, con GPT-4 no).
- **Determinismo**: usuarios no técnicos esperan flujos guiados. Un agente puede divagar, hacer preguntas innecesarias, o llamar `create_ticket` antes de tiempo. Hay que limitarlo con un system prompt fuerte y validar en el endpoint de creación.
- **Latencia**: 2–5s por turno vs <500ms con la FSM actual.
- **Auditoría**: más difícil reproducir conversaciones cuando algo sale mal.

**Recomendación pragmática**:

- **Si la prioridad es UX y reducir mantenimiento del bot** → migrar a Agent + tools (el patrón estándar 2026).
- **Si la prioridad es reducir mantenimiento sin tocar UX** → mantener la FSM pero **consolidar** los 11 Code en uno solo parametrizado por estado (un solo Code que recibe `session` y `event`, retorna `{nextState, payload}`). Y mover esa lógica a un sub-workflow.
- **Híbrido recomendado**: FSM determinista para los pasos críticos (confirmación final, registro en BD), Agent solo para parsear/normalizar el asunto y descripción del usuario (extraer entities, sugerir categoría/prioridad). Lo mejor de los dos.

---

## 5. El sub-flujo de Auto Tagging

Es funcionalmente correcto pero tiene huecos:

1. **No valida el output del LLM contra `available_tags`**. Si Groq alucina un `tag_id` que no existe, se ejecuta el INSERT, MySQL falla por FK, y el ticket queda sin tags y con error.
2. **No es idempotente**. Si la Mesa de Ayuda reintenta el webhook (porque no devuelve 200 a tiempo — el `Asignacion de Tags` está configurado para responder *immediately* con "Workflow got started", lo cual mitiga, pero si el LLM se reactiva por otro motivo se duplican rows).
3. **`JSON.parse($json.output.ticket_id)`** envuelto en `JSON.parse` sobre un número — innecesario y propenso a romper si el LLM devuelve `ticket_id: 123` (sin comillas), porque `JSON.parse(123)` lanza error en algunos casos.
4. **Sin reintentos** ante 429/5xx de Groq. El nodo HTTP/LLM debería tener retry con backoff (consistente con lo que ya se hizo en H-2 del audit Gmail).
5. **Sin logging estructurado**: cuando un ticket no recibe tags, no se sabe si fue porque el LLM devolvió `[]`, porque falló, o porque no se invocó.

---

## 6. Plan de acción priorizado

| # | Acción | Esfuerzo | Impacto |
|---|---|---|---|
| 1 | Separar en dos workflows independientes | XS | Alto (mantenibilidad y rendimiento) |
| 2 | ✅ Sustituir email→Gmail Import por endpoint `POST /webhooks/whatsapp/import` que use el Table layer | M | **Crítico** (auditoría + canal correcto) |
| 3 | ✅ Sustituir INSERT directo a `tickets_tags` por endpoint `POST /webhooks/tickets/{id}/tags` que pase por `TicketsTable` | S | **Crítico** (viola AuditBehavior) |
| 4 | Validar tag_ids contra `available_tags` antes de INSERT; retry con backoff en Groq | XS | Medio |
| 5 | Lock Redis (`SET NX EX`) por phoneNumber para evitar race conditions | S | Medio |
| 6 | Idempotencia por `messageId` de WhatsApp | S | Medio |
| 7 | Consolidar los 11 Code de la FSM en uno parametrizado (o migrar a Agent) | M / L | Alto (mantenibilidad) |
| 8 | Eliminar nodos deshabilitados (`OpenAI`, `Asignación de Agente`) | XS | Bajo (limpieza) |
| 9 | ✅ Decidir Evolution API vs WhatsApp Cloud API y alinear con CLAUDE.md | XS | Alto (consistencia) |
| 10 | Manejo de errores end-to-end con notificación al usuario ("ups, reintenta") | S | Alto (UX) |

Los puntos **2, 3 y 9** son los más críticos porque cruzan la línea entre n8n y el dominio de negocio.
