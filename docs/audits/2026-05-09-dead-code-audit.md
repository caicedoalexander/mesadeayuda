# Auditoría — Código muerto, funciones sin uso y abstracciones

**Fecha:** 2026-05-09
**Rama:** `main` (limpia)
**Alcance:** todo el proyecto
**Foco solicitado:** funciones sin uso, código muerto y abstracciones (ej. validaciones de `smtp_username` en vistas cuando se usa Gmail API).

Cada hallazgo incluye su corrección concreta. Marcado con prioridad (🔴 Crítico / 🟠 Mayor / 🟡 Menor / 🟢 Sugerencia).

---

## 🔴 1. Residuos SMTP (camino completamente muerto)

El envío real va por Gmail API; toda la rama SMTP es ballast.

| Hallazgo | Ubicación | Cómo arreglarlo |
|---|---|---|
| Lookup de `smtp_username` que siempre cae al fallback | `templates/element/tickets/reply_editor.php:192` | Reemplazar por `$systemConfig['gmail_user_email'] ?? ''` (la clave SMTP nunca existe en el array). |
| Variable JS `systemEmail` siempre vacía | `templates/element/tickets/reply_editor.php:222` | Idem: `json_encode($systemConfig['gmail_user_email'] ?? '')`. |
| `SmtpConfig` DTO sin consumidor | `src/Service/Dto/SmtpConfig.php` + composición en `SystemConfig.php:19,26,47,74` | Eliminar la propiedad `smtp` de `SystemConfig`, quitar `fromArray`/`toArray` y borrar `SmtpConfig.php`. Si en el futuro hay fallback SMTP, agregarlo entonces — no antes. |
| `TestEmailCommand` huérfano (debug only, sin docs/CI) | `src/Command/TestEmailCommand.php` | Confirmar con el usuario y eliminar (o documentarlo en `CLAUDE.md` como debug command si se conserva). |

---

## 🟠 2. Métodos públicos sin un solo caller (verificado vía grep)

| Método | Archivo:línea | Cómo arreglarlo |
|---|---|---|
| `EmailTemplateRenderer::preloadTemplates()` | `src/Service/EmailTemplateRenderer.php:51` | Eliminar método y la propiedad `$preloaded` asociada. `getTemplate()` ya hace lazy load. |
| `EmailTemplateRenderer::renderTemplate()` | `src/Service/EmailTemplateRenderer.php:121` | Eliminar. Los callers usan `getTemplate()` + `render()` por separado. |
| `EmailTemplateRenderer::clearCache()` | `src/Service/EmailTemplateRenderer.php:154` | Eliminar. No hay invalidation hook en el sistema. |
| `ProfileImageService::getProfileImageUrl()` | `src/Service/ProfileImageService.php:162` | Eliminar — duplica lógica de `View/Helper/UserHelper::profileImage()` que es la realmente usada. |
| `TicketNotificationService::sendStatusChangeEmail()` | `src/Service/TicketNotificationService.php:202` | Eliminar — wrapper público que nadie invoca; `dispatchUpdateNotifications` ya cubre el caso. |

**Beneficio:** ~60 LOC menos y `EmailTemplateRenderer` queda con su superficie real (`getTemplate`, `render`, `getSystemVariables`).

---

## 🟡 3. API de dominio en `Ticket` declarada pero no adoptada

`CLAUDE.md` declara estos predicados como fuente de verdad, pero la mayoría tiene **cero callers externos** y la app sigue haciendo comparaciones inline.

### 3a. Predicados sin uso externo (decidir: adoptar o eliminar)

| Predicado | Callers externos |
|---|---|
| `isOpen()` | 0 |
| `isPending()` | 0 |
| `isNew()` (custom) | 0 — **además sombrea `EntityInterface::isNew()`** (peligroso) |
| `hasAssignee()` | 0 |
| `belongsTo($userId)` | 0 |
| `isAssignedTo($userId)` | 0 |
| `wasCreatedFromEmail()` | 0 |
| `isResolved()` | solo vía `isLocked()` |

**Cómo arreglarlo (elegir UNA estrategia, no dejar a medias):**

- **Opción A — Adoptar:** reemplazar comparaciones inline por predicados:
  - `templates/element/tickets/header.php:9` → `$entity->isResolved()`
  - `templates/element/tickets/left_sidebar.php:7` → `$ticket->isResolved()`
  - `templates/Tickets/index.php:120` → `$ticket->isResolved()`
- **Opción B — Trim:** eliminar predicados sin caller real (`isOpen`, `isPending`, `belongsTo`, `isAssignedTo`, `wasCreatedFromEmail`, `hasAssignee`) y conservar solo los que se usan (`isLocked`, `isResolved`, `canTransitionTo`, `canBeAssignedTo`).

### 3b. `isNew()` sombrea `EntityInterface::isNew()`

**Riesgo:** un `if ($entity->isNew())` ejecutado en código de persistencia devolverá ahora el chequeo de status en vez del flag de "registro nuevo". Bug latente.

**Cómo arreglarlo:** renombrar a `isStatusNew()` (o eliminar si se opta por la estrategia B de arriba).

---

## 🟡 4. Templates near-duplicate

`status_badge.php` y `priority_badge.php` son idénticos byte-a-byte salvo `badge-status-X` vs `badge-priority-X`.

| Hallazgo | Ubicación | Cómo arreglarlo |
|---|---|---|
| 2 elements casi idénticos (~20 LOC duplicados) | `templates/element/tickets/status_badge.php` + `priority_badge.php` | Crear `templates/element/badge.php` parametrizado por `$kind` (`'status'` o `'priority'`); actualizar `View/Helper/StatusHelper.php:64,80` para pasar `$kind`. Eliminar los dos archivos viejos. |

---

## 🟢 5. Oportunidades de abstracción

| # | Patrón | Ubicación | Esfuerzo | Cómo arreglarlo |
|---|---|---|---|---|
| 5.1 | Loop bulk-op (success/error/unauthorized + Log + flash) repetido 4× | `src/Controller/Trait/TicketBulkTrait.php:79, 119, 165, 209` | M | Extraer trait `BulkOperationExecutorTrait::executeBulkOperation(callable, array $ids): array` con conteo de resultados. Cada bulk pasa el callback de su operación. |
| 5.2 | Guard + try/catch + flash + redirect repetido en acciones single | `src/Controller/Trait/TicketActionsTrait.php:110, 160, 199` | M | Helper privado `executeServiceAction(int $entityId, callable, string $successMsg, bool $checkLocked): Response` en el mismo trait. |
| 5.3 | Header-build + `secureCurlPost` duplicado | `src/Service/N8nService.php:224` + `src/Service/WhatsappService.php:138` | S | Trait `WebhookDispatcherTrait::sendWebhookRequest(string $url, array $payload, array $headers, int $timeout): array`. |
| 5.4 | Try/catch logging duplicado | `src/Service/TicketNotificationService.php` (3 spots: 69, 79, 104) | S | Helper privado `wrapNotificationCall(callable, string $channel, int $entityId): bool`. |
| 5.5 | Inconsistencia de DI: `GmailService` recibe `array $config`, `N8n`/`Whatsapp` reciben `?SystemConfig` | constructores | M | Refactor `GmailService` para aceptar `?SystemConfig`. Mover `loadConfigFromDatabase` a un factory o constructor. |
| 5.6 | `EmailService::sendEmail` arma to/cc con loops casi idénticos | `src/Service/EmailService.php:124–186` | S | Extraer `private function buildRecipientArray(array\|string $input, array $exclude = []): array`. |
| 5.7 | `TicketPipelineService::handleResponse` (138 LOC, 6 responsabilidades) | `src/Service/TicketPipelineService.php:72` | M | Partir en `parseResponseData()` y `processCommentAndAttachments()`. |
| 5.8 | JS inline grande dentro de element | `templates/element/tickets/reply_editor.php:27–225` | S | Mover a `webroot/js/reply-editor.js` y cargar vía `$this->Html->script()` en `styles_and_scripts.php` (en línea con commit `1fdd15c`). |

---

## Plan de implementación recomendado

PRs por orden de menor riesgo / mayor signal:

### PR 1 — Limpieza directa (≈1 hora, sin cambios funcionales)
- 1: borrar lookups `smtp_username` en `reply_editor.php`
- 2: borrar 5 métodos sin caller
- 4: colapsar badge templates en uno solo
- 3b: renombrar/eliminar `Ticket::isNew()` para no sombrear

### PR 2 — Decisión sobre SMTP / Test command
- 1: eliminar `SmtpConfig` y propiedad en `SystemConfig` (o documentar)
- 1: decidir destino de `TestEmailCommand`

### PR 3 — Adopción de predicados (3a)
- Reemplazar comparaciones inline o trim de predicados sin uso

### PR 4 — Abstracciones (5.x)
- Empezar por 5.4 (más pequeño, alta visibilidad), luego 5.1 y 5.2 (más LOC ahorrados), después 5.5 (más arquitectónico)

### Fuera de alcance (decidir después)
- 5.7 (`handleResponse` split) — requiere tests primero
- 5.8 (extraer JS) — depende de cómo evolucione la integración con WhatsApp/Gmail

---

## Verificación

- Cada hallazgo verificado con `grep` directo antes de reportar.
- Predicados de `Ticket` originalmente reportados como muertos por sub-agente; verificación demostró que son API intencional pero no adoptada.
- Sin issues de seguridad, ni bugs detectados como side-effect.

**Veredicto:** ⚠️ APROBAR CON COMENTARIOS — el código está sano; el patrón dominante es "empezado pero no aterrizado" (predicados, DTOs, abstracciones). Cerrar esos circuitos es la acción principal.
