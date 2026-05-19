# Auditoría — Uso de la Gmail API

- **Fecha:** 2026-05-16
- **Alcance:** Toda la superficie de integración con Gmail (OAuth, ingestión, envío, attachments, settings, webhook).
- **Versiones relevantes:** `google/apiclient ^2.19.3`, CakePHP 5.x, PHP 8.5.
- **Archivos auditados:**
  - `src/Service/GmailService.php`
  - `src/Service/GmailImportService.php`
  - `src/Service/TicketIngestionService.php`
  - `src/Service/TicketAttachmentService.php`
  - `src/Service/EmailService.php`
  - `src/Service/Util/EmailHeaderParser.php`
  - `src/Service/Exception/Gmail{Authentication,NotConfigured}Exception.php`
  - `src/Controller/WebhooksController.php`
  - `src/Controller/Admin/SettingsController.php` (acciones `gmailAuth`, `gmailClientSecret`, `testGmail`)
  - `src/Command/ImportGmailCommand.php`
  - `src/Service/SettingsService.php` (invalida `gmail_settings` en `saveSetting`)

---

## 1. Resumen ejecutivo

La integración con Gmail está bien encapsulada en una sola clase (`GmailService`) con un orquestador delgado (`GmailImportService`), un transport para outbound (`EmailService`) y un webhook autenticado por shared secret (`WebhooksController::gmailImport`). El **flujo es funcional y seguro en superficie** — sanitización HTML con HTMLPurifier, validación de MIME por contenido en attachments, hash_equals para tokens, encriptación en reposo de `refresh_token` y `client_secret_json`, locks de archivo para evitar dobles ejecuciones, throttle interno entre downloads de attachments.

Sin embargo hay **hallazgos importantes** que afectan privilegios OAuth, resiliencia frente a límites de cuota / fallos transitorios, eficiencia del refresh OAuth y robustez de la detección anti-loop. Ninguno es una vulnerabilidad explotable remotamente hoy; varios son riesgos operativos o brechas de defensa en profundidad.

**Hallazgos por severidad:**

| Severidad | Cantidad inicial | Estado actual (2026-05-16) |
|-----------|------------------|----------------------------|
| Alto      | 3 | 0 (H-1, H-2, H-3 cerrados) |
| Medio     | 5 | 3 (M-1, M-3 cerrados) |
| Bajo      | 4 | 2 (B-1 WONT_FIX, B-4 cerrado) |
| Informativo | 3 | 3 |

**P0 cerrado el mismo día:** los tres ítems P0 del §8 (H-1, H-3, M-1) fueron implementados y mergeados a `main` en commits `b8e3d2a`, `5b21651`, `8ae81f0`. Ver §11 para detalles operativos.

**P1 cerrado el mismo día:** los tres ítems P1 del §8 (H-2, M-3, B-4) fueron implementados y mergeados a `main` en commits `78b9487`, `7894d98`, `0204c18`. Ver §11 para detalles operativos.

---

## 2. Mejores prácticas de referencia (Gmail API 2025-2026)

Estas son las prácticas contra las que se compara la implementación actual. Fuentes en §10.

### 2.1 Scopes (least-privilege)

- `gmail.readonly` — ver mensajes y settings (solo lectura).
- `gmail.modify` — leer, componer, enviar, modificar labels (incluye lo que hace `readonly` y `send`). **No** permite eliminación permanente.
- `gmail.send` — solo enviar (no lee).
- `https://mail.google.com/` — acceso total, incluye eliminación permanente. **A evitar.**

Un sistema que (a) lee inbox, (b) marca como leído, (c) envía respuestas transaccionales necesita **solo `gmail.modify`**.

### 2.2 OAuth tokens

- Refresh tokens deben almacenarse cifrados en reposo.
- `fetchAccessTokenWithRefreshToken` produce un access_token de **~1 hora**. Cachearlo (PSR-6) y reusarlo entre invocaciones; no llamar al token endpoint en cada construcción del cliente.
- Manejar `invalid_grant` específicamente: significa que el refresh_token fue revocado, expiró por inactividad (>6 meses), o el proyecto OAuth está en estado "Testing" (caducidad a 7 días para usuarios test).
- Si el proyecto está en "Testing" en GCP, todo refresh_token caduca a 7 días para External users. Debe estar en **Production** (Published).
- Hard limit: 100 refresh tokens vivos por (usuario, client_id); los más antiguos se invalidan silenciosamente.

### 2.3 Sincronización (pull vs push)

- **Polling con `users.messages.list`**: simple, pero recorre todos los IDs y requiere `messages.get` por cada uno. Costos de cuota: `list = 5`, `get = 20`, `attachments.get = 20`, `modify = 5`, `send = 100`.
- **`users.history.list`** con `historyId` checkpoint: devuelve solo cambios desde el último punto. `historyId` se mantiene **≥ 1 semana** (no garantizado); ante 404 hacer **full sync** y refrescar el `historyId`.
- **Push (`watch()` + Pub/Sub)**: lease máximo **7 días** (Google recomienda renovar a diario). Tasa máxima: 1 evento/segundo/usuario; el exceso se descarta. Recomendado para tiempo real.

### 2.4 Cuotas y backoff

- Per-project: 1.2M unidades/minuto, 80M/día.
- Per-user-per-project: 6,000 unidades/minuto.
- 429 (`rate_limit_exceeded`) y 5xx deben tratarse con **exponential backoff + jitter** (`min(2^n + rand_ms, max_backoff)`, max típico 32-64s, jitter hasta 1000 ms).
- `messages.send` cuesta 100 unidades — el más caro de la suite. Marca un techo práctico de ~60 envíos/min por proyecto en un solo usuario.

### 2.5 Seguridad de email entrante

- No confiar en `From:` para autorización: spoofeable salvo que el servidor receptor valide DKIM/SPF/DMARC. Gmail entrega `Authentication-Results:` con el resultado de SPF/DKIM/DMARC verificado por Google — usar **ese** header, no los del sender.
- `Message-ID:` (RFC 5322) es el identificador canónico de threading entre servidores; `In-Reply-To:` y `References:` arman el árbol de la conversación. El `gmail_message_id` interno de Gmail no es portable entre cuentas/sistemas.
- HTML body siempre vía allowlist (HTMLPurifier o equivalente). Stripping de inline scripts, event handlers, `<style>` con `expression()`.
- Attachments: validar por contenido (`finfo`/`mime_content_type`) y no por la extensión declarada; rechazar binarios ejecutables; aplicar tamaños máximos.
- Loops anti-self: header propio firmado o ID en subject con HMAC; el header X-* propio es trivialmente spoofeable si el sender es externo.

### 2.6 Operación

- Logs estructurados (no PII en claro).
- Métricas (fetched / created / skipped / errors / latency).
- Healthcheck explícito que pruebe `users.getProfile`, no solo `users.messages.list`.
- Tracking del `historyId` activo y de la fecha de último refresh exitoso.

---

## 3. Hallazgos — Alto

### H-1 — Scopes OAuth excesivos

> **Cerrado 2026-05-16 — commit `8ae81f0`.** `GmailService::initializeClient` ahora solicita únicamente `Gmail::GMAIL_MODIFY`. Test de regresión: `GmailServiceTest::testOnlyGmailModifyScopeIsRequested`. Acción operativa pendiente: remover los scopes `gmail.readonly` / `gmail.send` del OAuth consent screen en GCP y re-autorizar en `/admin/settings/gmailAuth`.

**Archivo:** `src/Service/GmailService.php:132-134`

```php
$this->client->addScope(Gmail::GMAIL_READONLY);
$this->client->addScope(Gmail::GMAIL_SEND);
$this->client->addScope(Gmail::GMAIL_MODIFY);
```

`gmail.modify` **subsume** `gmail.readonly` y `gmail.send` para los tres usos del sistema: leer mensajes, marcar como leídos (label `UNREAD`), enviar transaccionales. Los otros dos son redundantes y aumentan la superficie en caso de que el refresh_token sea comprometido. También complica la verificación de OAuth de Google (más scopes ⇒ más revisión).

**Recomendación:** dejar **solo** `Gmail::GMAIL_MODIFY`. Si se decide separar lectura y envío en cuentas distintas en el futuro, mantener un único scope por cuenta.

### H-2 — Sin retry/backoff frente a 429/5xx

> **Cerrado 2026-05-16 — commit `7894d98`.** Nuevo `App\Service\Gmail\RetryHandler` (factory stateless de middleware Guzzle) inyectado en `GoogleClient` vía `setHttpClient` en `GmailService::initializeClient`. Política: `MAX_RETRIES=5` (6 intentos totales), backoff exponencial con full jitter (`min(2^n * 250ms + rand(0, 1000ms), 32000ms)`), respeta header `Retry-After` (segundos o HTTP-date). Reintenta `429/500/502/503/504` y `ConnectException`. Log `Gmail API warning Gmail API retry` por intento. Tests: `RetryHandlerTest` (10 casos) + `GmailServiceTest::testRetryMiddlewareIsRegisteredOnTheGoogleClient` + `testRetryMiddlewareSucceedsAfter429Retries`. Trade-off explícito: `sendEmail` no persiste outbox; tras 6 intentos fallidos la notificación se loguea y se pierde (mismo contrato best-effort de hoy, solo más resiliente).

**Archivos:** `GmailService::getMessages`, `parseMessage`, `downloadAttachment`, `markAsRead`, `sendEmail`.

Todas las llamadas a la API están envueltas en un `try/catch (Exception)` que loguea y o bien retorna `[]`/`false`, o re-lanza. **No hay retry**, ni respeto a `Retry-After`, ni backoff exponencial. Bajo cuotas saturadas o errores 5xx transitorios:

- `getMessages` retorna `[]` ⇒ el orquestador concluye "0 mensajes" sin diferenciar "no hay" de "Gmail temporalmente no responde".
- `parseMessage` re-lanza ⇒ el mensaje queda como `error` en `GmailImportService::run()` pero el siguiente run lo reintenta solo si todavía está `unread`. Si en el ínterin alguien lo abrió en la web, se pierde silenciosamente.
- `sendEmail` retorna `false`, el listener de notificaciones loguea y sigue: **emails de cliente potencialmente nunca enviados** sin alerta.

**Recomendación:** envolver el `GoogleClient` con un Guzzle middleware de retries (`kevinrob/guzzle-cache-middleware` no — eso es para cache; usar `GuzzleHttp\Middleware::retry` propio o `caseyamcl/guzzle_retry_middleware`) que reintente 429 y 5xx con `min(2^n + jitter, 32s)`, max 5 intentos. Para `sendEmail` específicamente, ante fallo persistente: persistir un outbox de notificaciones (ya hay `App\Notification\*` — usar una Outbox Pattern simple en DB) en lugar de perder el mensaje.

### H-3 — Detección de "system notification" es trivialmente spoofeable

> **Cerrado 2026-05-16 — commit `5b21651`.** Implementado el HMAC en el subject vía nueva clase `App\Service\Util\NotificationStamp` (input `'ticket:<N>'`, key `Security.salt`, 8 hex truncados). `EmailService::sendEmail` lo aplica a todo subject que contenga `#<N>`. `GmailService::isSystemNotification` lo trata como evidencia canónica; el header legacy `X-Mesa-Ayuda-Notification` se sigue aceptando **solo** cuando `Authentication-Results` reporta `dkim=pass` con `header.d=<dominio propio>` — esto es una ventana de gracia para hilos en vuelo, programada para retirarse hacia 2026-06-15 (~30 días post-deploy). Eliminada también la rama dead-code de prefijos de subject (`Re: [Ticket #`, `Re: Tu Solicitud`). Tests: `NotificationStampTest` (6 casos) + `GmailServiceTest::testIsSystemNotification*` (6 casos cubriendo stamped, unstamped, legacy sin DKIM, legacy con DKIM de atacante, legacy con DKIM propio, From==system).

**Archivo:** `src/Service/GmailService.php:459-492`

`isSystemNotification()` cierra el loop de auto-respuesta con tres heurísticas:

1. Header `X-Mesa-Ayuda-Notification: true` — añadido por `EmailService::sendEmail` (`src/Service/EmailService.php:108`). **Cualquier sender externo puede añadir este header** y será entregado por Gmail tal cual.
2. `From` igual al `gmail_user_email`. Spoofeable salvo que se valide DKIM (Gmail rechaza por DMARC los obviamente spoofeados desde el dominio propio cuando hay política `reject`, pero no es garantía universal).
3. Subject empieza con `Re: [Ticket #` — coincide con cualquier email que un atacante haga llegar con ese subject.

**Impacto:** un externo que envíe un email con `X-Mesa-Ayuda-Notification: true` será **silenciosamente descartado** (marcado como leído y skipped). Permite que un actor malicioso evite la creación de un ticket legítimo conociendo el flujo. Es una **denegación de servicio para clientes** específicos, no una elevación de privilegios.

**Recomendación:**
- Reemplazar el header booleano por un HMAC corto en el subject (`[Ticket #1234 · h=abc12345]`) computado con `SECURITY_SALT`. La validación es: recalcular el HMAC del ticket extraído y comparar con `hash_equals`. Un actor externo no puede falsificarlo sin la salt.
- Alternativamente, validar el header `Authentication-Results:` (Gmail anota DKIM/SPF/DMARC verificados por sí mismo) y solo confiar en `X-Mesa-Ayuda-Notification` cuando `dkim=pass` con `d=<dominio propio>`. Es estricto pero correcto.
- En cualquier caso, **no confiar en el header `From`** para decisiones de seguridad.

---

## 4. Hallazgos — Medio

### M-1 — `fetchAccessTokenWithRefreshToken` en cada `new GmailService(...)`

> **Cerrado 2026-05-16 — commit `b8e3d2a`.** `GmailService::initializeClient` ahora envuelve el `GoogleClient` con un `Symfony\Component\Cache\Adapter\FilesystemAdapter` (PSR-6) apuntando a `TMP/gmail_oauth_cache`, `lifetime=3500s` (< 1h del access_token). `setTokenCallback` registra un log debug cuando el SDK rota el token. `SettingsService::saveSetting` purga el directorio cuando se reescribe `GMAIL_CLIENT_SECRET_JSON` o `GMAIL_REFRESH_TOKEN` para evitar tokens fantasma post-rotación. Test: `GmailServiceTest::testPsr6CacheIsConfiguredOnInitialize`. **Nota de dependencia:** se usa `symfony/cache:^7.4` en vez de `cache/filesystem-adapter` (que la auditoría recomendaba) porque la versión 1.x del segundo requiere `psr/cache ^1||^2` y este proyecto ya tiene `psr/cache:3.0.0` pinneado por dependencias transitivas.

**Archivo:** `src/Service/GmailService.php:144-157`

`initializeClient()` se invoca en el constructor y llama al token endpoint en cada instancia. El access_token vale ~1 hora, pero:

- `EmailService::getGmailService()` (lazy) crea uno.
- `GmailImportService::fromSettings()` crea otro.
- `TicketAttachmentService::getGmailService()` crea otro.
- `SettingsController::testGmail()` crea uno por test.

En un import HTTP típico (`/webhooks/gmail/import`) se construyen al menos 2 instancias (orquestador + adjuntos), cada una con un round-trip OAuth de 100-300 ms adicionales y una llamada extra a Google que no cuenta para la cuota Gmail pero sí para Identity/OAuth quota.

**Recomendación:** configurar `GoogleClient::setCache(new Psr6Cache(...))` apuntando al cache profile `CacheConstants::CACHE_CONFIG`. El SDK detecta el cache y reusará el access_token entre llamadas. Adicionalmente, `setTokenCallback($cb)` permite persistir el token actualizado si Google rota.

### M-2 — Polling de `is:unread` sin checkpoint

> **Cerrado 2026-05-18 — commit `e45a98b`.** `GmailImportService::run()` ahora ejecuta una state machine de checkpoint: bootstrap (sin checkpoint → `getProfileHistoryId()` + full sync `in:inbox newer_than:7d`), delta (`users.history.list`), fallback (`history.list` 404 → refresh + full sync) y manual_override (CLI `--query` o webhook `query=`). El checkpoint se persiste en `system_settings.gmail_last_history_id` (nueva `SettingKeys::GMAIL_LAST_HISTORY_ID`) y avanza al `max(gmail_history_id)` observado en el loop. Nuevas API en `GmailService`: `getProfileHistoryId()` y `getHistoryDelta(startHistoryId)`. `GmailImportResult` añade `history_mode` y `history_fallbacks`. `SettingsService::keyRequiresOAuthCachePurge()` (pure predicate) excluye `GMAIL_LAST_HISTORY_ID` para que las escrituras por minuto no purguen el OAuth cache. Tests: `HistoryModeTest` (2), `GmailServiceTest` (5 nuevos: profile + delta paginado/404/error/dedup + parseMessage history_id), `GmailImportResultTest` (2), `SettingsServiceTest` (5 pure predicate).

**Archivo:** `src/Service/GmailImportService.php:75`

El query default es `is:unread` con cap 200. Esto:

- Pierde mensajes ya leídos manualmente en la UI de Gmail antes del polling.
- No tiene noción de "desde cuándo" — siempre escanea desde el más reciente unread.
- Si el cap (200) se llena en una sola corrida (e.g. tras un outage), las demás quedan ahí hasta el siguiente run. Con un intervalo de 60s y >200 nuevos por minuto, hay drift permanente.

**Recomendación de mediano plazo:** migrar a `users.history.list` con `historyId` persistido en `system_settings` (e.g. `gmail_last_history_id`). Algoritmo:

1. Si no hay `lastHistoryId`: full sync vía `messages.list` con `q='in:inbox newer_than:7d'`, anota el `historyId` máximo de los mensajes procesados.
2. Si hay `lastHistoryId`: `history.list(startHistoryId=lastHistoryId)`. Si Google responde 404, fallback a full sync.
3. Cuota por mensaje cae de 25 (list 5 + get 20) a 22 (history 2 + get 20) y la query es proporcional al delta real, no a 200 mensajes fijos.

**Largo plazo:** evaluar `watch()` + Pub/Sub. Solo si el volumen justifica la infraestructura (Pub/Sub topic, suscripción push apuntando al webhook `/webhooks/gmail/import`, renovación diaria de la lease). Para el volumen típico de helpdesk pyme, el polling cada 60s suele ser suficiente.

### M-3 — `catch (Exception)` genérico ahoga errores

> **Cerrado 2026-05-16 — commit `78b9487`.** Nuevo `App\Service\Gmail\GmailErrorCategory` (constantes `auth`/`rate`/`transient`/`permanent`/`unknown` + mappers `categorize(Throwable)` / `fromHttpCode(int)`) y nuevo `App\Service\Exception\GmailApiException` (wrap con `category` readonly, preserva `getPrevious()`). Los cinco catches de `GmailService` (getMessages, parseMessage, downloadAttachment, markAsRead, sendEmail) ahora desdoblan `Google\Service\Exception` vs `Exception` genérico, loguean con `category` y `code`, y los métodos que propagan ahora lanzan `GmailApiException` en vez del SDK exception cruda. `GmailImportResult` agrega 5 campos readonly (`authErrors`, `rateErrors`, `transientErrors`, `permanentErrors`, `unknownErrors`) expuestos como `auth_errors`/etc en `toArray()`; `GmailImportService::run()` los acumula. Tests: `GmailErrorCategoryTest` (12 casos), `GmailApiExceptionTest` (2), `GmailImportResultTest` (3), `GmailServiceTest` typed-catch (3 nuevos vía `stubHttp`).

**Archivo:** `src/Service/GmailService.php:230-234, 285-288, 376-379, 397-402, 555-562`

Cada método del cliente captura `\Exception` genérico, loguea y retorna un valor neutral. Pierde la distinción crítica:

- `Google\Service\Exception` con código 401/403 ⇒ token revocado, alertar al operador.
- Código 429 / 5xx ⇒ retry con backoff (ver H-2).
- Código 4xx no-401 ⇒ bug propio (e.g. payload mal armado), no reintentar.
- `RuntimeException` de red ⇒ retry.

Hoy todo se trata igual y la única señal es la línea de log.

**Recomendación:** capturar `Google\Service\Exception` explícitamente y exponer un método `getCode()` al orquestador. `GmailImportService` puede acumular contadores por categoría (`auth_errors`, `rate_errors`, `transient_errors`, `permanent_errors`) y `GmailImportResult` puede incluirlos para el dashboard de admin.

### M-4 — Ausencia de `Message-ID` (RFC 5322) y headers de threading

> **Cerrado 2026-05-18 — commit `7575072`.** Migración `AddRfcThreadingToTickets` agrega `rfc_message_id` (255 + índice), `in_reply_to` (255) y `references_header` (TEXT) tanto a `tickets` como a `ticket_comments`. `GmailService::parseMessage` extrae los tres headers vía nuevo `EmailHeaderParser::extractMessageId()` (normaliza angle-brackets / whitespace). `Ticket::fromEmailIngest()` y `TicketIngestionService::createCommentFromEmail` los persisten. Nueva `TicketIngestionService::findExistingTicketByThreading($emailData)` resuelve hilos en orden: `In-Reply-To` → `References` (newest-first) → fallback a `gmail_thread_id`. Recencia gateada por `TicketConstants::THREAD_REATTACH_WINDOW_DAYS = 90` para evitar resurrección de tickets cerrados antiguos. `GmailImportService` reemplaza la búsqueda inline por una sola llamada. Tests: `EmailHeaderParserTest` (5 nuevos para `extractMessageId`), `GmailServiceTest::testParseMessageExtractsRfcThreadingHeaders` (1).

**Archivos:** `GmailService::parseMessage` (extracción), schema (`tickets.gmail_message_id`).

Solo se persiste `gmail_message_id` (ID interno Gmail) y `gmail_thread_id`. No se captura el `Message-ID:` real ni `In-Reply-To:` / `References:`. Consecuencias:

- Si un cliente reenvía desde Outlook/Apple Mail a la cuenta de soporte, el `gmail_message_id` cambia (es nuevo en el buzón del helpdesk) y se crea un ticket duplicado del mismo hilo original.
- Threading roto cuando un agente responde fuera de Gmail y luego el cliente contesta a esa respuesta — Gmail asigna un thread_id distinto.
- Imposibilidad de correlacionar con servidores SMTP en logs externos.

**Recomendación:** agregar columnas `rfc_message_id`, `in_reply_to`, `references` (TEXT) a `tickets` y `ticket_comments`. Antes de crear comentario/ticket, buscar match por `In-Reply-To` contra el `rfc_message_id` de comentarios existentes — fallback más robusto que solo `gmail_thread_id`.

### M-5 — `mark as read` happens-after-save, sin idempotencia transaccional

> **Cerrado 2026-05-18 — commit `c231dac`.** Nueva tabla `gmail_mark_read_pending` (`gmail_message_id` único + `attempts` + `last_error` + `last_category`) backed por `GmailMarkReadPendingTable`. Nuevo `MarkReadQueueService` con `enqueue(messageId, error, category)` y `processPending(GmailService, batch=20)`: éxito → delete, categoría `PERMANENT` (e.g. 404) → drop inmediato, transitoria → incremento de `attempts` hasta `MAX_ATTEMPTS=3` y entonces drop con log. `GmailService::markAsRead()` ahora lanza `GmailApiException` (antes retornaba `false`) para que el wrapper `safeMarkAsRead()` en `GmailImportService` enqueue el messageId. `GmailImportService::run()` drena la cola al inicio. `GmailImportResult` añade `mark_read_retried`, `mark_read_dropped`, `mark_read_enqueued`. Tests: `MarkReadQueueServiceTest` (7 con anonymous Table double + Entity rows), `GmailImportResultTest` (2 nuevos), `GmailServiceTest::testMarkAsReadThrowsGmailApiExceptionOnAuthError` (reescrito).

**Archivo:** `src/Service/GmailImportService.php:138, 143`

Flow: `save(ticket)` ⇒ `markAsRead(messageId)`. Si `markAsRead` falla (red, 5xx), el ticket queda creado pero el mensaje Gmail sigue `UNREAD`. En la siguiente corrida, la dedup por `gmail_message_id` lo skip-ea correctamente (incremento de `$skipped`), pero **el mensaje queda permanentemente unread** consumiendo el cap de cada corrida.

**Recomendación:**
1. Ya hay dedup por `gmail_message_id` antes de crear (`GmailImportService::run` línea 89-95) — está bien.
2. Agregar un `markAsRead` *fire-and-forget reintentable*: si falla, registrar `messageId` en una cola simple (tabla `gmail_mark_read_pending` con columna `attempts`) y procesarlo en el próximo run antes del fetch principal. Tres intentos máximo, después solo log.

---

## 5. Hallazgos — Bajo

### B-1 — `usleep(200ms)` síncrono dentro del request HTTP

> **Cerrado 2026-05-19 — WONT_FIX (riesgo aceptado).** El `usleep(200ms)` preventivo es defendible: con el volumen actual (pyme) el tiempo acumulado de sleeps cabe holgadamente en el `set_time_limit(300)` del webhook, y post-M-2 (delta polling) el número de adjuntos por corrida está limitado por el delta real, no por el cap de 200 mensajes. El `RetryHandler` introducido en H-2 ya absorbe 429/5xx con backoff exponencial; un token-bucket compartido duplicaría esa defensa con coste extra (cache miss = throttle inefectivo) sin atender un modo de falla nuevo. Reabrir si métricas futuras muestran >5 adjuntos/segundo sostenidos. Spec: `docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md` §4.

**Archivo:** `src/Service/GmailService.php:369` (`ATTACHMENT_THROTTLE_US = 200_000`)

Con 5 attachments en un mismo email, el request bloquea 1s solo en sleeps; el `set_time_limit(300)` del webhook deja margen pero si la corrida tiene 50 emails con 5 adjuntos cada uno son 50s solo en sleeps. El throttle es defensivo y sensato, pero podría ser **server-side rate-limiter compartido** (token bucket en cache) en lugar de sleep ciego: si el run anterior ya consumió cuota, el siguiente espera; si no, no espera.

### B-2 — Concatenación de `body_html` en `multipart/alternative`

**Archivo:** `src/Service/GmailService.php:305-311`

`extractMessageParts()` acumula con `\n` cada parte HTML que encuentra. En `multipart/alternative` solo se debe usar **una** de las alternativas (la más rica que el cliente soporte), no concatenar. Funcional pero potencialmente duplica contenido cuando hay forwards anidados.

**Recomendación:** detectar `multipart/alternative` y elegir HTML si existe, ignorando el plain de esa misma rama.

### B-3 — `isAutoReply` no contempla `List-Unsubscribe` ni `Feedback-ID`

**Archivo:** `src/Service/GmailService.php:417-444`

Para newsletters/bulk modernos (RFC 8058) los indicadores más fiables son `List-Unsubscribe`, `Precedence: list` (ya cubierto), y `Feedback-ID`. Falso negativo bajo, pero ocurre.

### B-4 — `gmail_user_email` setting no se valida contra `users.getProfile`

> **Cerrado 2026-05-16 — commit `0204c18`.** Nuevo `GmailService::getUserEmail()` que llama `users.getProfile('me')` (hereda retry de H-2 y typing de M-3 vía el GoogleClient envuelto); lanza `GmailApiException(PERMANENT)` si el `emailAddress` viene vacío. `SettingsController::gmailAuth` ahora delega a un helper privado `syncGmailUserEmail()` después de persistir el refresh_token (sea token nuevo o reautorización con token existente). El helper reconstruye `GmailService` contra el token recién guardado, lee el email y lo persiste en `GMAIL_USER_EMAIL`. Override silencioso de cualquier valor previo, con log `Gmail user email changed via OAuth` (old/new enmascarados vía helper local `maskEmail`). Fail-soft: si `getProfile` falla, el OAuth ya quedó persistido y el operador recibe un Flash warning. Tests: `GmailServiceTest::testGetUserEmail*` (3 casos: success, empty emailAddress, 401 wrap).

Si un operador rota la cuenta Gmail (nuevo `client_secret_json` + reautorización) pero olvida actualizar `GMAIL_USER_EMAIL`, todos los checks de `From == systemEmail` dejan de funcionar y se vuelven a abrir loops.

**Recomendación:** al guardar el refresh_token en `gmailAuth`, llamar `users.getProfile('me')` y persistir el `emailAddress` retornado en `GMAIL_USER_EMAIL` automáticamente.

---

## 6. Hallazgos — Informativo

### I-1 — `google/apiclient` en modo mantenimiento

El repositorio oficial declara la librería como "maintenance mode" — solo bugs críticos y seguridad, sin features nuevas. Para Gmail aún es el camino oficial, pero conviene seguir el `SECURITY.md` del repo y considerar la migración futura a los SDKs per-product (`googleapis/google-api-php-client-services`).

### I-2 — Proyecto OAuth en GCP debe estar **Published / Production**

Si el proyecto OAuth está en `Testing` con tipo `External`, **todo refresh_token caduca a 7 días**. Documentar en `docs/operations/` que el proyecto debe estar publicado (y que los scopes solicitados deben pasar la verificación de Google, lo cual refuerza H-1: a menos scopes, menos fricción de verificación).

### I-3 — Logs incluyen `to`/`subject`

**Archivos:** `EmailService.php:117-128`, `GmailService.php:556-562`, `TicketIngestionService.php:146-150`.

No es PII grave pero sí dato personal bajo LOPD/GDPR equivalentes. Considerar enmascarar la parte local del email en producción (`a***@example.com`) o controlar por nivel de log (`debug` muestra completo, `info` enmascarado).

---

## 7. Aciertos a preservar

- **Refresh token y client_secret cifrados** vía `SettingsEncryptionTrait`.
- **Sanitización HTML doble**: en `GmailService::parseMessage` (defensa al borde) y en `TicketIngestionService::createFromEmail` (defensa al persistir).
- **Truncado UTF-8-safe + re-purify** en `HtmlSanitizerTrait::truncateSanitizedHtml`.
- **Webhook con token rotation grace window** (`hash_equals` + 401 explícito), rate limit 60s, lock de archivo no bloqueante, `set_time_limit` + `ignore_user_abort`.
- **Dedup por `gmail_message_id` antes de fetch** (línea 90-95 de `GmailImportService`) — minimiza re-trabajo.
- **Atomic ticket number generation** vía `NumberGenerationService` (documentado en CLAUDE.md).
- **Autorización de comments por recipientes**: `TicketIngestionService::isEmailInTicketRecipients` exige que el sender estuviera en `To`/`CC` o sea el requester — buen control para evitar inyección de comentarios cruzados.
- **`CRLF injection` defendido** en construcción de MIME (`sendEmail`), todos los campos pasan por `str_replace(["\r","\n"], '', ...)`.
- **MIME validation por contenido** en `GenericAttachmentTrait::verifyMimeTypeFromBinary` (no confía en `mime_type` declarado por Gmail).
- **Cache invalidation explícita** al guardar settings (`SettingsService::clearAllCaches` borra `gmail_settings`).

---

## 8. Plan de acción priorizado

### P0 (esta iteración) — **Completado 2026-05-16**

1. **H-1** Reducir scopes a solo `gmail.modify`. Cambio de 3 líneas en `GmailService::initializeClient`. Requiere re-autorización del usuario admin (nuevo consent screen). — **Completado** (commit `8ae81f0`).
2. **H-3** Reemplazar header `X-Mesa-Ayuda-Notification` por HMAC en subject o validar contra `Authentication-Results`. Pieza más sensible para evitar DoS por spoofing. — **Completado** (commit `5b21651`, ambas estrategias combinadas: HMAC canónico + DKIM-gated legacy en ventana de gracia).
3. **M-1** `setCache()` PSR-6 en el `GoogleClient` + `setTokenCallback`. Reduce latencia de cada request y consumo de Identity quota. — **Completado** (commit `b8e3d2a`).

### P1 (próximas dos iteraciones) — **Completado 2026-05-16**

4. **H-2** Wrappear `GoogleClient` con retry middleware (Guzzle). — **Completado** (commit `7894d98`). Implementación cubre las 5 llamadas API de una sola vez vía `setHttpClient` global, no solo `sendEmail`.
5. **M-3** Tipar excepciones (`Google\Service\Exception` con `getCode()`) y enriquecer `GmailImportResult`. — **Completado** (commit `78b9487`).
6. **B-4** `users.getProfile('me')` tras OAuth callback para persistir `gmail_user_email` automáticamente. — **Completado** (commit `0204c18`).

### P2 (mediano plazo) — **Completado 2026-05-18**

7. **M-4** Persistir `Message-ID` / `In-Reply-To` / `References` + reattach lookup. — **Completado** (commit `7575072`).
8. **M-5** Cola de retry para `markAsRead`. — **Completado** (commit `c231dac`).
9. **M-2** Migrar polling a `history.list` + checkpoint persistido. — **Completado** (commit `e45a98b`).

### P3 (evaluar valor)

10. `watch()` + Pub/Sub si crece volumen.
11. `B-1` token-bucket compartido en lugar de sleep. — **Cerrado 2026-05-19 — WONT_FIX**. Ver spec P3 §4 y banner en §5.
12. `B-2` selección correcta de rama en `multipart/alternative`.
13. `I-3` enmascarar PII en logs `info`.

---

## 9. Diagrama de la integración actual

```
                              ┌───────────────────────────┐
                              │ n8n (cron cada 60s)       │
                              │ POST /webhooks/gmail/import│
                              └─────────────┬─────────────┘
                                            │ X-Webhook-Token
                                            ▼
                              ┌──────────────────────────┐
                              │ WebhooksController       │
                              │  • verifyToken           │
                              │  • ranRecently (60s)     │
                              │  • flock                 │
                              └─────────────┬────────────┘
                                            │
                                            ▼
                              ┌──────────────────────────┐
                              │ GmailImportService       │
                              │  ::fromSettings()        │
                              └─────┬──────────────┬─────┘
                                    │              │
                          ┌─────────▼──┐    ┌──────▼──────────┐
                          │ GmailService│    │ TicketIngestion │
                          │ (OAuth +    │    │ Service          │
                          │  parsing)   │    │  • findOrCreate  │
                          │             │    │    User          │
                          │             │    │  • sanitize HTML │
                          │             │    │  • save Ticket   │
                          │             │    │  • dispatch      │
                          │             │    │    TicketCreated │
                          └──┬──────────┘    └────────┬─────────┘
                             │                       │
                ┌────────────▼──┐         ┌──────────▼──────────┐
                │ Google APIs    │         │ TicketAttachment    │
                │  • users.msg.* │         │ Service (downloads  │
                │  • attachments │◄────────┤ via GmailService)   │
                └────────────────┘         └─────────────────────┘
                             ▲
                             │ outbound (EmailService → GmailService::sendEmail)
              ┌──────────────┴────────────────────┐
              │ TicketNotificationListener        │
              │  ← EventManager (TicketCreated,   │
              │     TicketAssigned, etc.)         │
              └───────────────────────────────────┘
```

---

## 10. Fuentes consultadas

- [Gmail API — OAuth 2.0 scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)
- [Gmail API — Synchronization guide](https://developers.google.com/workspace/gmail/api/guides/sync)
- [Gmail API — Usage limits / quota units](https://developers.google.com/workspace/gmail/api/reference/quota)
- [Gmail API — Push notifications (watch + Pub/Sub)](https://developers.google.com/workspace/gmail/api/guides/push)
- [OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Nango — `invalid_grant` token expired/revoked causes](https://nango.dev/blog/google-oauth-invalid-grant-token-has-been-expired-or-revoked/)
- [google/apiclient — repositorio oficial](https://github.com/googleapis/google-api-php-client)
- [Shaped — Data ingestion best practices](https://www.shaped.ai/blog/10-best-practices-in-data-ingestion)
- [DMARCLY — DMARC/DKIM/SPF implementation guide](https://dmarcly.com/blog/how-to-implement-dmarc-dkim-spf-to-stop-email-spoofing-phishing-the-definitive-guide)

---

## 11. Notas para implementación

- Cambios de scope OAuth (H-1) **requieren re-consentimiento del usuario**. Coordinar con operaciones; el flujo `/admin/settings/gmailAuth` ya soporta re-autorización.
- Para H-3, si se elige HMAC en subject, mantener compatibilidad con el header viejo durante una ventana de gracia (lo mismo que se hace con `WebhookGmailPreviousToken`).
- Las pruebas existentes son unitarias puras (`tests/bootstrap.php` no carga fixtures por defecto). Las recomendaciones P0/P1 deberían venir acompañadas de tests que mockeen `Google\Service\Gmail` y validen el comportamiento ante 401/429/5xx.

### 2026-05-16 — P0 cerrado (H-1 + H-3 + M-1)

**Hallazgos cubiertos:** los tres ítems P0 del §8. Implementados como tres commits secuenciales en `main` siguiendo el plan en `docs/superpowers/plans/2026-05-16-gmail-audit-p0.md` y la spec `docs/superpowers/specs/2026-05-16-gmail-audit-p0-design.md`.

**Commits:**

| Commit | Hallazgo | Resumen |
|---|---|---|
| `b8e3d2a` | M-1 | PSR-6 `FilesystemAdapter` para el access_token (TMP/gmail_oauth_cache, 3500s TTL). Invalidación automática en `SettingsService::saveSetting` cuando se rotan `GMAIL_CLIENT_SECRET_JSON` o `GMAIL_REFRESH_TOKEN`. |
| `5b21651` | H-3 | Stamp HMAC anti-spoof en subject vía `NotificationStamp` (input `'ticket:<N>'` + `Security.salt`, 8 hex truncados). `isSystemNotification` reescrito: stamp canónico → legacy header gateado por `dkim=pass header.d=<propio>` (ventana de gracia) → `From==system_email`. Eliminadas dos ramas dead-code de subject-prefix. |
| `8ae81f0` | H-1 | Drop de `gmail.readonly` + `gmail.send`. Único scope ahora: `gmail.modify`. |

**Desviaciones del plan original (documentadas en commit bodies):**

1. **Dependencia M-1:** el plan especificaba `cache/filesystem-adapter:^1.2`, pero esa versión requiere `psr/cache ^1||^2` y el lockfile pinea `psr/cache:3.0.0`. Sustituido por `symfony/cache:^7.4` (`FilesystemAdapter`), mismo contrato PSR-6.
2. **Testabilidad de `getSystemEmail()`:** se añadió un fallback de configuración (`$this->config['user_email']`) antes de la lectura a DB, para permitir unit tests sin fixtures. Los call sites de producción no pasan esa clave, así que el comportamiento runtime no cambia.

**Verificación ejecutada:**

- `composer cs-check` sobre archivos tocados: sin nuevos errores/warnings versus la línea base pre-existente. H-3 net-elimina dos errores `Double space found` que vivían dentro de la rama dead-code removida.
- `vendor/bin/phpstan analyse src`: 38 errores de línea base (todos en `UserHelper.php`, pre-existentes); sin nuevos errores en archivos tocados.
- `composer test`: 195 tests (13 nuevos), 7 fallos idénticos a la línea base pre-trabajo (rendering de templates, sanitización de paths Windows, shape de circuit breaker — todos no relacionados con Gmail).
- `bin/cake import_gmail --max 1`: omitido en el entorno de ejecución (sin DB/Gmail config).

**Pendiente operativo post-deploy:**

1. GCP Console → OAuth consent screen → remover `gmail.readonly` y `gmail.send` de los scopes sensibles declarados.
2. `/admin/settings/gmailAuth` → completar re-consent para alinear el `refresh_token` con el nuevo scope set.
3. Smoke manual: asignar un test ticket, esperar la notificación, responder desde un email externo y confirmar que NO se crea un ticket duplicado (valida el stamp + `isSystemNotification` en conjunto).

**Recordatorio calendario:** alrededor de **2026-06-15** (~30 días post-deploy), remover la rama legacy `X-Mesa-Ayuda-Notification` de `isSystemNotification` y la inyección del header en `EmailService::sendEmail`. Para entonces, los stamps habrán cubierto todos los hilos en vuelo.

### 2026-05-16 — P1 cerrado (H-2 + M-3 + B-4)

**Hallazgos cubiertos:** los tres ítems P1 del §8. Implementados como tres commits secuenciales en `main` siguiendo el plan en `docs/superpowers/plans/2026-05-16-gmail-audit-p1.md` y la spec `docs/superpowers/specs/2026-05-16-gmail-audit-p1-design.md`.

**Commits:**

| Commit | Hallazgo | Resumen |
|---|---|---|
| `78b9487` | M-3 | Nuevo `GmailErrorCategory` (constantes + mappers) + `GmailApiException` (wrap categorizado). Los cinco catches de `GmailService` (getMessages, parseMessage, downloadAttachment, markAsRead, sendEmail) ahora desdoblan `Google\Service\Exception` vs `Exception` genérico y loguean con categoría. `GmailImportResult` agrega cinco campos readonly de contadores expuestos en `toArray()`; `GmailImportService::run()` los acumula. |
| `7894d98` | H-2 | Nuevo `RetryHandler` (factory stateless de middleware Guzzle) inyectado en `GoogleClient::setHttpClient` con backoff exponencial + full jitter, cap 32s, retry sobre 429/500/502/503/504/ConnectException, respeta `Retry-After`. Cubre las cinco llamadas API de un solo punto (no por llamada). |
| `0204c18` | B-4 | Nuevo `GmailService::getUserEmail()` (vía `users.getProfile('me')`); `SettingsController::gmailAuth` delega a `syncGmailUserEmail()` helper privado tras el saveSetting del refresh_token y persiste el email en `GMAIL_USER_EMAIL`. Helper `maskEmail` privado para logs. Fail-soft con Flash warning si `getProfile` falla. |

**Desviaciones del plan original (documentadas en commit bodies):**

1. **PHPUnit 13 attributes:** el plan listaba el `dataProvider` como anotación PHPDoc; PHPUnit 13.1 lo deprecó, así que se usó `#[DataProvider]` en su lugar.
2. **Middleware naming:** el plan no nombraba el push del retry middleware, lo que dejaba el name como `''` y rompía el test `testRetryMiddlewareIsRegisteredOnTheGoogleClient` (la HandlerStack stringificada no contenía la palabra `retry`). Se pushea con name explícito `'retry'` para que el test inspeccione el stack de forma estable.
3. **Reflection setAccessible:** PHP 8.1+ hace innecesario `setAccessible(true)` sobre propiedades typed accesibles; se removió para evitar la deprecación de PHP 8.5.

**Verificación ejecutada:**

- `composer cs-check` sobre archivos tocados: sin nuevos errores/warnings versus la línea base pre-existente.
- `vendor/bin/phpstan analyse src`: 38 errores de línea base (en `UserHelper.php`, `AppController.php`, `TicketActionsTrait.php`, `TicketBulkTrait.php`, `N8nService.php`, `TicketPipelineService.php` — funciones globales de CakePHP como `__()`, `h()`, `env()` y la clase `FrozenTime`, todos pre-existentes); sin nuevos errores en archivos tocados por P1.
- `composer test`: 230 tests (35 nuevos a lo largo del bloque P1), 7 fallos idénticos a la línea base pre-trabajo (rendering de templates, sanitización de paths Windows, shape de circuit breaker — todos no relacionados con Gmail).
- `bin/cake import_gmail --max 1`: omitido en el entorno de ejecución (sin DB/Gmail config).

**Pendiente operativo post-deploy:**

1. Monitorear logs `Gmail API retry` durante 24h post-deploy para dimensionar la tasa real de 429/5xx que la instalación absorbe; si la tasa es alta y consistente, considerar pre-empujar M-2 (history.list) en la próxima iteración.
2. Re-OAuth en `/admin/settings/gmailAuth` con la cuenta actual; confirmar que el setting `GMAIL_USER_EMAIL` queda alineado y que el log `Gmail user email persisted` aparece (email enmascarado).
3. Opcional: re-OAuth con una cuenta distinta para validar el log `Gmail user email changed via OAuth` con `old`/`new` ambos enmascarados.

### 2026-05-18 — P2 cerrado (M-4 + M-5 + M-2)

**Hallazgos cubiertos:** los tres ítems P2 del §8. Implementados como tres commits secuenciales en `main` siguiendo el plan en `docs/superpowers/plans/2026-05-18-gmail-audit-p2.md` y la spec `docs/superpowers/specs/2026-05-18-gmail-audit-p2-design.md`.

**Commits:**

| Commit | Hallazgo | Resumen |
|---|---|---|
| `7575072` | M-4 | Tres columnas RFC 5322 (`rfc_message_id`, `in_reply_to`, `references_header`) en `tickets` y `ticket_comments` con índice por `rfc_message_id`. `EmailHeaderParser::extractMessageId()` normaliza angle-brackets. `GmailService::parseMessage` extrae los tres headers; `Ticket::fromEmailIngest` los persiste. `TicketIngestionService::findExistingTicketByThreading()` resuelve hilos por In-Reply-To → References → gmail_thread_id, gateado por `TicketConstants::THREAD_REATTACH_WINDOW_DAYS=90` para no resucitar tickets cerrados antiguos. |
| `c231dac` | M-5 | Nueva tabla `gmail_mark_read_pending` + `MarkReadQueueService` (enqueue + processPending con drop tras `MAX_ATTEMPTS=3` o categoría PERMANENT). `GmailService::markAsRead()` ahora lanza `GmailApiException` (antes: retornaba `false`) para alimentar la cola. `GmailImportService::run()` drena la cola al inicio y enqueue las fallas via `safeMarkAsRead()`. Tres contadores nuevos en `GmailImportResult`. |
| `e45a98b` | M-2 | State machine de checkpoint en `GmailImportService::run()`: bootstrap → delta → fallback (404) → manual_override. Nuevo `SettingKeys::GMAIL_LAST_HISTORY_ID`, nuevo `HistoryMode` enum-like, nuevos `GmailService::getProfileHistoryId()` y `getHistoryDelta()`. `parseMessage` retorna `gmail_history_id` para avanzar el checkpoint al máximo observado. `SettingsService::keyRequiresOAuthCachePurge()` (pure predicate) excluye `GMAIL_LAST_HISTORY_ID` para no purgar el OAuth cache cada minuto. CLI y webhook pasan `queryOverride=null` por defecto. |

**Desviaciones del plan original (documentadas en commit bodies):**

1. **`GmailApiException` ctor signature:** el plan mostraba `new GmailApiException('msg', GmailErrorCategory::PERMANENT)` (orden `(message, category)`), pero la firma real es `(category, code, message, previous)`. Se corrigió en todos los tests M-5/M-2 y en las nuevas llamadas dentro de `getProfileHistoryId`/`getHistoryDelta`.
2. **`markAsRead` change of contract:** el plan asumía que `markAsRead` ya lanzaba `GmailApiException`, pero el método retornaba `false` en error. Se cambió a lanzar (necesario para que tanto el wrapper `safeMarkAsRead` como `MarkReadQueueService::processPending` puedan distinguir categorías). El test existente `testMarkAsReadReturnsFalseOnAuthError` se reescribió como `testMarkAsReadThrowsGmailApiExceptionOnAuthError`.
3. **Anonymous Table double — LSP:** el seam del plan usaba `\stdClass` rows con override `(object $entity, array $options): object`, pero PHP 8.5 + LSP requieren la firma exacta del padre (`EntityInterface $entity, array $options): EntityInterface`). Se reemplazó `\stdClass` por `Cake\ORM\Entity` (implementa `EntityInterface`, soporta `$row->foo` magic) en `MarkReadQueueServiceTest` y en la rama test de `MarkReadQueueService::enqueue()`.
4. **Migration base class:** el plan extendía `Migrations\AbstractMigration`, pero esta versión del paquete (`cakephp/migrations` reciente) sólo acepta `Migrations\BaseMigration`. Ambas migraciones (`20260518120000_AddRfcThreadingToTickets`, `20260518120100_CreateGmailMarkReadPending`) se corrigieron.
5. **`references_header` rename:** el plan habla indistintamente de `references` y `references_header`; se usó `references_header` consistentemente (la palabra `references` es reservada en SQL).
6. **WebhooksController también pasa a checkpoint:** además del CLI, se actualizó `WebhooksController::gmailImport` para que el `query` POST data (cuando ausente o vacío) deje correr la state machine. El n8n cron no necesita pasar `query` para beneficiarse de M-2.
7. **`SettingsService::saveSetting` lookup key:** la fila de `system_settings` se busca por `setting_key`/`setting_value`, no `key`/`value`. `readHistoryCheckpoint()` en `GmailImportService` usa la columna correcta.

**Verificación ejecutada:**

- `composer cs-check` sobre archivos tocados: sin nuevos errores/warnings versus la línea base pre-existente. Un único error de `Missing doc comment` en el constructor privado de `HistoryMode` se corrigió en flight.
- `vendor/bin/phpstan analyse src`: 38 errores de línea base (los mismos archivos de P0/P1: `UserHelper.php`, `AppController.php`, `TicketActionsTrait.php`, `TicketBulkTrait.php`, `N8nService.php`, `TicketPipelineService.php`); sin nuevos errores en archivos tocados por P2.
- `composer test`: 261 tests (31 nuevos en P2: 5 EmailHeaderParser + 1 GmailService threading + 2 HistoryMode + 7 MarkReadQueueService + 2+2 GmailImportResult + 7 GmailService history + 5 SettingsService), 7 fallos idénticos a la línea base pre-trabajo (rendering de templates, sanitización Windows, shape de circuit breaker — todos no relacionados con Gmail). 14 PHPUnit Notices (3 nuevos del tipo "No expectations were configured" en `MarkReadQueueServiceTest` — benignos).
- `bin/cake migrations migrate`: ambas migraciones aplicadas localmente sin error; columnas y tabla creadas según schema esperado.

**Pendiente operativo post-deploy:**

1. Tras el primer `bin/cake import_gmail --max 1` post-deploy, verificar que aparezca log `Gmail import completed` con `history_mode=bootstrap` y que `system_settings.gmail_last_history_id` tenga valor. Runs posteriores deben reportar `history_mode=delta`.
2. Enviar un correo de prueba a la cuenta Gmail configurada, esperar el siguiente run del webhook. Esperado: un ticket creado, `history_mode=delta`, sin filas en `gmail_mark_read_pending`.
3. Responder al ticket desde una cuenta externa (no Gmail). Esperado: comentario añadido al ticket existente (no ticket duplicado), `rfc_message_id` / `in_reply_to` poblados en `ticket_comments`.
4. Smoke de la cola de retry: insertar manualmente `INSERT INTO gmail_mark_read_pending (gmail_message_id, attempts, created, modified) VALUES ('nonexistent-id', 0, NOW(), NOW());`. Tras el siguiente webhook, la fila debe desaparecer (Gmail responde 404 → categoría PERMANENT → drop inmediato).
5. Monitorear el tamaño de `gmail_mark_read_pending` durante 24h. Filas con `attempts >= 2` indican un patrón de fallas recurrentes — investigar.

### 2026-05-19 — P3 cerrado (B-2 + B-3 + I-3) y B-1 WONT_FIX

**Hallazgos cubiertos:** los tres ítems de código del bloque P3 (B-2 multipart/alternative, B-3 List-Unsubscribe/Feedback-ID, I-3 enmascarado de PII en logs) y el cierre documental de B-1 como WONT_FIX. Implementados como tres commits secuenciales siguiendo el plan en `docs/superpowers/plans/2026-05-19-gmail-audit-p3.md` y la spec `docs/superpowers/specs/2026-05-19-gmail-audit-p3-design.md`.

**Commits:**

| Commit | Hallazgo | Resumen |
|---|---|---|
| `7981461` | B-2 | `GmailService::extractMessageParts` ahora intercepta `multipart/alternative` y desciende solo en una rama (HTML > multipart con HTML > plain). Frena la duplicación de `body_html` en forwards anidados. Cinco tests en `GmailServiceTest`. |
| `6c3f7be` | B-3 | `isAutoReply` amplía `Auto-Submitted` a "cualquier valor distinto de `no`" (RFC 3834 §5) y suma `List-Unsubscribe` (RFC 2369/8058) y `Feedback-ID` (Google/Yahoo bulk-sender 2024+). Cinco tests. |
| `61873de` | I-3 | Nuevo `App\Service\Util\LogMasker::email`. Aplicado en cinco call sites de log (`EmailService` x2, `GmailService::sendEmail` x2, `TicketIngestionService` x1). Subject queda en claro porque carga el `#<ticketNumber>` operativo. Seis tests. |

**B-1 — WONT_FIX:** documentado en §5 (banner) y §8 P3 #11. Razonamiento: el sleep preventivo es proporcional al volumen pyme y al cap post-M-2; el `RetryHandler` ya cubre 429/5xx reales; el token-bucket añade superficie sin nuevo beneficio. Métricas a vigilar antes de reabrir: tasa de adjuntos/segundo sostenida.

**Verificación ejecutada:**

- `vendor/bin/phpcs` sobre archivos tocados: sin nuevos errores/warnings versus la línea base pre-existente. Errores remanentes en `GmailService.php` (unused `$parts` en `parseMessage` línea 317, cuatro long-line warnings) y `EmailService.php` (cuatro `Missing doc comment` en métodos pre-existentes) ya estaban en `HEAD` antes de P3 (verificado vía `git stash` + `phpcs`).
- `vendor/bin/phpstan analyse src`: 38 errores de línea base (los mismos archivos de P0/P1/P2); sin nuevos errores en archivos tocados por P3 (`GmailService.php`, `EmailService.php`, `TicketIngestionService.php`, `Util/LogMasker.php`).
- `vendor/bin/phpunit`: 11 tests nuevos en `GmailServiceTest` (5 B-2 + 5 B-3 + el helper de fixture) y 6 en `LogMaskerTest`. `GmailServiceTest` pasa 35/35; `LogMaskerTest` pasa 6/6. Línea base pre-trabajo seguía con 5 fallos de rendering de templates (no relacionados con Gmail) — no cambia.
- `bin/cake import_gmail --max 1`: omitido en el entorno de ejecución (sin DB/Gmail config).

**Pendiente operativo post-deploy:**

1. Tras el primer run del webhook post-deploy, confirmar en un log `info` que `Created ticket from email` reporta el `from` enmascarado (`a***@dominio.tld`).
2. Enviar un newsletter de prueba (uno con `List-Unsubscribe` real) a la cuenta de soporte y verificar que NO se crea un ticket (es decir, que `isAutoReply` lo intercepta).
3. Smoke de `multipart/alternative`: reenviar un email forwardeado de Gmail con cuerpo HTML y verificar que el comentario o ticket persistido no muestre el cuerpo duplicado.

---

## 12. Soluciones propuestas (detalle técnico)

Cada solución incluye archivos a tocar, esqueleto de código, migraciones si aplica, riesgos de regresión, y test sugerido. Las soluciones se ordenan por hallazgo, no por prioridad — la prioridad sigue siendo la de §8.

### 12.1 H-1 · Reducir scopes a solo `gmail.modify`

**Archivos:** `src/Service/GmailService.php`.

**Cambio:**

```php
// src/Service/GmailService.php (initializeClient)

// ANTES
$this->client->addScope(Gmail::GMAIL_READONLY);
$this->client->addScope(Gmail::GMAIL_SEND);
$this->client->addScope(Gmail::GMAIL_MODIFY);

// DESPUÉS
$this->client->addScope(Gmail::GMAIL_MODIFY);
```

**Pasos operativos:**

1. Verificar en `console.cloud.google.com` que el OAuth consent screen tenga **solo** `gmail.modify` como scope sensible declarado. Si tenía los tres, eliminar `gmail.readonly` y `gmail.send`.
2. Tras desplegar el cambio, abrir `/admin/settings/gmailAuth`. Google detectará el cambio de scope y forzará re-consentimiento (`prompt=consent` ya está activo en `initializeClient`).
3. Verificar que el `refresh_token` antiguo siga sirviendo: Google **acepta** un refresh_token que tenía más scopes y devuelve un access_token recortado al scope actual del cliente. Aún así, lo prudente es re-autorizar para que el refresh_token coincida.

**Riesgo de regresión:** Mínimo. Las llamadas `users.messages.list/get/modify/attachments.get/send` están todas cubiertas por `gmail.modify`. Validar con `bin/cake import_gmail --max 1` post-deploy.

**Test:**

```php
// tests/TestCase/Service/GmailServiceTest.php
public function testOnlyGmailModifyScopeIsRequested(): void
{
    $service = new GmailService([
        'client_secret' => $this->fakeClientSecret(),
    ]);
    $client = $this->getPrivateProperty($service, 'client');

    $this->assertSame(
        ['https://www.googleapis.com/auth/gmail.modify'],
        $client->getScopes(),
    );
}
```

---

### 12.2 H-2 · Retry con backoff exponencial

**Archivos:** `src/Service/GmailService.php`, nuevo `src/Service/Gmail/RetryHandler.php`.

**Approach:** envolver el `GoogleClient` con un `HandlerStack` de Guzzle que aplique retry sobre `429`, `500`, `502`, `503`, `504` y errores de red transitorios.

```php
// src/Service/Gmail/RetryHandler.php
<?php
declare(strict_types=1);

namespace App\Service\Gmail;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle retry decider for Gmail API. Implements exponential backoff with
 * full jitter per Google's recommendation:
 *   delay = min(2^n * base + rand(0, jitter), maxBackoff)
 */
final class RetryHandler
{
    public const MAX_RETRIES = 5;
    public const BASE_DELAY_MS = 250;
    public const MAX_BACKOFF_MS = 32_000;
    public const JITTER_MS = 1_000;

    /** @var array<int, true> */
    private const RETRIABLE_STATUS = [429 => true, 500 => true, 502 => true, 503 => true, 504 => true];

    public static function decider(): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $error = null,
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }
            if ($error instanceof ConnectException) {
                return true;
            }
            if ($response !== null && isset(self::RETRIABLE_STATUS[$response->getStatusCode()])) {
                return true;
            }
            return false;
        };
    }

    public static function delay(): callable
    {
        return static function (int $retries, ?ResponseInterface $response = null): int {
            // Respect Retry-After if present
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $retryAfter = (int)$response->getHeaderLine('Retry-After');
                if ($retryAfter > 0) {
                    return min($retryAfter * 1000, self::MAX_BACKOFF_MS);
                }
            }
            $expo = (2 ** $retries) * self::BASE_DELAY_MS;
            return min($expo + random_int(0, self::JITTER_MS), self::MAX_BACKOFF_MS);
        };
    }
}
```

```php
// src/Service/GmailService.php (initializeClient, después de new GoogleClient())
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use App\Service\Gmail\RetryHandler;

$stack = HandlerStack::create();
$stack->push(Middleware::retry(RetryHandler::decider(), RetryHandler::delay()));
$this->client->setHttpClient(new GuzzleClient([
    'handler' => $stack,
    'timeout' => 30,
    'connect_timeout' => 5,
]));
```

**Test:**

```php
public function testRetriesOn429UntilSuccess(): void
{
    $mock = new MockHandler([
        new Response(429, ['Retry-After' => '0']),
        new Response(429),
        new Response(200, [], json_encode(['messages' => []])),
    ]);
    // ... assert getMessages eventually returns []
}
```

**Riesgo de regresión:** Bajo. Si las APIs ya estaban funcionando, el retry no cambia el happy-path. Cuidado con el `set_time_limit(300)` del webhook: 5 retries × 32s = 160s peor caso por request — encaja en el límite pero ajustar `MAX_RETRIES = 3` si se quiere más conservador.

---

### 12.3 H-3 · HMAC en subject + validación de `Authentication-Results`

**Archivos:** `src/Service/EmailService.php`, `src/Service/GmailService.php`, nuevo `src/Service/Util/NotificationStamp.php`.

**Diseño:** En lugar del header trivialmente spoofeable, se añade un sello HMAC corto al subject saliente. Al recibir, se intenta reextraer y validar; si coincide, es un reply legítimo a una notificación nuestra y se descarta. Como fallback durante la ventana de gracia, se sigue aceptando el header viejo **solo si** `Authentication-Results` reporta `dkim=pass` para el dominio propio.

```php
// src/Service/Util/NotificationStamp.php
<?php
declare(strict_types=1);

namespace App\Service\Util;

use Cake\Core\Configure;

/**
 * Short HMAC stamp embedded in outgoing notification subjects.
 * Format: "... · #<ticketNumber>·s=<8-hex>".
 * Receiver re-derives s from ticketNumber + SECURITY_SALT and compares.
 */
final class NotificationStamp
{
    private const STAMP_PREFIX = '·s=';
    private const STAMP_LENGTH = 8; // 8 hex chars = 32 bits, enough vs spam

    public static function append(string $subject, string $ticketNumber): string
    {
        return rtrim($subject) . ' ' . self::STAMP_PREFIX . self::compute($ticketNumber);
    }

    public static function verify(string $subject, string $ticketNumber): bool
    {
        if (!preg_match('/' . preg_quote(self::STAMP_PREFIX, '/') . '([0-9a-f]{' . self::STAMP_LENGTH . '})/', $subject, $m)) {
            return false;
        }
        return hash_equals(self::compute($ticketNumber), $m[1]);
    }

    public static function extractTicketNumber(string $subject): ?string
    {
        if (preg_match('/\[Ticket #(\d+)\]/', $subject, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function compute(string $ticketNumber): string
    {
        $salt = (string)Configure::read('Security.salt');
        return substr(hash_hmac('sha256', 'ticket:' . $ticketNumber, $salt), 0, self::STAMP_LENGTH);
    }
}
```

```php
// src/Service/EmailService.php (sendEmail) — antes de armar $subject final
use App\Service\Util\NotificationStamp;

if (!empty($message->ticketNumber)) {
    $subject = NotificationStamp::append($subject, (string)$message->ticketNumber);
}
```

```php
// src/Service/GmailService.php (isSystemNotification)
public function isSystemNotification(array $headers): bool
{
    $subject = $this->getHeader($headers, 'Subject');

    // 1) Canonical check: HMAC stamp matches a known ticket number.
    $ticketNumber = NotificationStamp::extractTicketNumber($subject);
    if ($ticketNumber !== null && NotificationStamp::verify($subject, $ticketNumber)) {
        return true;
    }

    // 2) Legacy header — only trusted when DKIM passed for our own domain.
    $notificationHeader = $this->getHeader($headers, 'X-Mesa-Ayuda-Notification');
    if ($notificationHeader === 'true' && $this->dkimPassesForOwnDomain($headers)) {
        return true;
    }

    return false;
}

private function dkimPassesForOwnDomain(array $headers): bool
{
    $authResults = $this->getHeader($headers, 'Authentication-Results');
    if ($authResults === '') {
        return false;
    }
    $ownDomain = $this->getOwnDomain(); // derived from gmail_user_email
    if ($ownDomain === '') {
        return false;
    }
    // dkim=pass header.d=example.com  (Gmail's canonical format)
    $pattern = '/dkim=pass[^;]*?header\.d=' . preg_quote($ownDomain, '/') . '\b/i';
    return (bool)preg_match($pattern, $authResults);
}
```

**Migración / despliegue:**

1. Deploy primero `EmailService` (stamping en salida). Durante una semana, las notificaciones nuevas llevan stamp; las viejas en circulación todavía no.
2. Deploy `isSystemNotification` con la lógica dual (HMAC + DKIM-validated legacy).
3. Tras ~30 días (vida típica de un hilo activo), retirar el branch del header legacy.

**Test:**

```php
public function testIsSystemNotificationAcceptsStampedSubject(): void
{
    $stamped = NotificationStamp::append('Re: [Ticket #42] Cierre', '42');
    $headers = [$this->header('Subject', $stamped)];
    $this->assertTrue($this->service->isSystemNotification($headers));
}

public function testIsSystemNotificationRejectsSpoofedHeader(): void
{
    $headers = [
        $this->header('Subject', 'Re: [Ticket #42] urgente'),
        $this->header('X-Mesa-Ayuda-Notification', 'true'),
        $this->header('Authentication-Results', 'mx.google.com; dkim=pass header.d=attacker.tld'),
    ];
    $this->assertFalse($this->service->isSystemNotification($headers));
}
```

---

### 12.4 M-1 · Cache PSR-6 del access_token

**Archivos:** `src/Service/GmailService.php`, posible nueva `src/Service/Gmail/CakeCachePsr6.php` si no existe ya un adapter.

CakePHP no expone nativamente un PSR-6 cache. Hay dos opciones:

1. Usar `cache/filesystem-adapter` (paquete PSR-6 estándar) apuntando a `TMP . 'gmail_oauth_cache'`. Es lo que el README de `google/apiclient` muestra. Sin coupling con Cake.
2. Adapter Cake → PSR-6. Pequeño wrapper sobre `Cake\Cache\Cache::read/write`.

**Opción más simple (recomendada):**

```bash
composer require cache/filesystem-adapter
```

```php
// src/Service/GmailService.php (initializeClient)
use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

$adapter = new LocalFilesystemAdapter(TMP . 'gmail_oauth_cache');
$pool = new FilesystemCachePool(new Filesystem($adapter));
$this->client->setCache($pool);
$this->client->setCacheConfig(['lifetime' => 3500]); // < 1h access_token TTL

$this->client->setTokenCallback(function (string $cacheKey, string $accessToken): void {
    \Cake\Log\Log::debug('Gmail access token refreshed', ['cache_key' => $cacheKey]);
});
```

**Riesgo de regresión:** El cache directory debe ser writable por el usuario PHP-FPM (igual que `webroot/uploads/attachments` — ya está documentado en CLAUDE.md). Validar en CI con `tests/bootstrap.php` apuntando a un tmp aislado.

**Test:**

```php
public function testAccessTokenIsReusedAcrossInstances(): void
{
    $mockHandler = new MockHandler([new Response(200, [], json_encode([
        'access_token' => 'tok-abc', 'expires_in' => 3600,
    ]))]);
    // ... create 3 GmailService instances; assert only 1 token request was made
}
```

---

### 12.5 M-2 · Migrar a `history.list` con checkpoint

**Archivos:** `src/Service/GmailImportService.php`, `src/Service/GmailService.php` (nuevos métodos), nueva migración + `SettingKeys::GMAIL_LAST_HISTORY_ID`.

**Migración:**

```php
// config/Migrations/2026MMDDhhmmss_AddGmailHistoryIdSetting.php
public function up(): void
{
    $this->table('system_settings')->insert([
        'setting_key' => 'gmail_last_history_id',
        'setting_value' => '',
        'setting_type' => 'string',
    ])->save();
}
```

```php
// src/Service/GmailService.php — nuevos métodos
public function listHistory(string $startHistoryId, int $maxResults = 500): array
{
    try {
        $resp = $this->getService()->users_history->listUsersHistory('me', [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => 'messageAdded',
            'maxResults' => $maxResults,
        ]);
        $messageIds = [];
        foreach ($resp->getHistory() ?? [] as $record) {
            foreach ($record->getMessagesAdded() ?? [] as $added) {
                $messageIds[] = $added->getMessage()->getId();
            }
        }
        return ['message_ids' => array_unique($messageIds), 'next_history_id' => $resp->getHistoryId()];
    } catch (\Google\Service\Exception $e) {
        if ($e->getCode() === 404) {
            throw new GmailHistoryExpiredException('historyId expired, need full sync');
        }
        throw $e;
    }
}

public function getCurrentHistoryId(): string
{
    $profile = $this->getService()->users->getProfile('me');
    return (string)$profile->getHistoryId();
}
```

```php
// src/Service/GmailImportService.php — pseudo-código del nuevo flow
$lastHistoryId = $this->settings->getSetting(SettingKeys::GMAIL_LAST_HISTORY_ID);

try {
    if ($lastHistoryId === '') {
        // Full sync (bootstrap): fall back to current 'is:unread' query.
        $messageIds = $this->gmail->getMessages($query, $max);
        $newHistoryId = $this->gmail->getCurrentHistoryId();
    } else {
        $result = $this->gmail->listHistory($lastHistoryId, $max);
        $messageIds = $result['message_ids'];
        $newHistoryId = $result['next_history_id'];
    }
    // ... process messages as today ...
    $this->settings->saveSetting(SettingKeys::GMAIL_LAST_HISTORY_ID, $newHistoryId);
} catch (GmailHistoryExpiredException) {
    // Reset and do full sync next run
    $this->settings->saveSetting(SettingKeys::GMAIL_LAST_HISTORY_ID, '');
    Log::warning('Gmail historyId expired, full sync scheduled');
}
```

**Riesgo de regresión:** Alto si no se hace cuidadosamente. Hacer rollout detrás de un feature flag (`SettingKeys::GMAIL_USE_HISTORY_API` boolean) y mantener el path de `is:unread` como fallback durante 2-3 semanas.

---

### 12.6 M-3 · Tipar `Google\Service\Exception` y enriquecer resultado

**Archivos:** `src/Service/GmailService.php`, `src/Service/Dto/GmailImportResult.php`, `src/Service/GmailImportService.php`.

```php
// src/Service/GmailService.php — patrón a aplicar en cada método
use Google\Service\Exception as GoogleApiException;

public function getMessages(string $query = 'is:unread', int $maxResults = 50): array
{
    try {
        // ... como hoy ...
    } catch (GoogleApiException $e) {
        Log::error('Gmail messages.list failed', [
            'code' => $e->getCode(),
            'query' => $query,
        ]);
        throw $e; // dejar que el orquestador categorice
    }
}
```

```php
// src/Service/Dto/GmailImportResult.php — añadir contadores
public function __construct(
    public readonly int $fetched,
    public readonly int $created,
    public readonly int $comments,
    public readonly int $skipped,
    public readonly int $errors,
    public readonly float $durationSeconds,
    public readonly array $errorMessages = [],
    // NUEVOS
    public readonly int $authErrors = 0,
    public readonly int $rateLimitErrors = 0,
    public readonly int $transientErrors = 0,
    public readonly int $permanentErrors = 0,
) {}
```

```php
// src/Service/GmailImportService.php — clasificación en el catch
} catch (GoogleApiException $e) {
    $code = $e->getCode();
    if ($code === 401 || $code === 403) {
        $authErrors++;
    } elseif ($code === 429) {
        $rateLimitErrors++;
    } elseif ($code >= 500 && $code < 600) {
        $transientErrors++;
    } else {
        $permanentErrors++;
    }
    // ... además del $errors++ general ...
}
```

**Test:**

```php
public function testAuthErrorIsClassifiedSeparately(): void
{
    $this->mockGmailService->method('parseMessage')
        ->willThrowException(new GoogleApiException('revoked', 401));
    $result = $this->importService->run(max: 1);
    $this->assertSame(1, $result->authErrors);
    $this->assertSame(0, $result->rateLimitErrors);
}
```

---

### 12.7 M-4 · Persistir `Message-ID` RFC 5322 + threading headers

**Archivos:** nueva migración, `TicketsTable`, `TicketCommentsTable`, `GmailService::parseMessage`, `TicketIngestionService`.

**Migración:**

```php
// config/Migrations/2026MMDDhhmmss_AddRfcMessageIdToTicketsAndComments.php
$this->table('tickets')
    ->addColumn('rfc_message_id', 'string', ['limit' => 998, 'null' => true])
    ->addColumn('in_reply_to',     'string', ['limit' => 998, 'null' => true])
    ->addColumn('references',      'text',                    ['null' => true])
    ->addIndex('rfc_message_id', ['name' => 'idx_rfc_message_id'])
    ->update();

$this->table('ticket_comments')
    ->addColumn('rfc_message_id', 'string', ['limit' => 998, 'null' => true])
    ->addColumn('in_reply_to',     'string', ['limit' => 998, 'null' => true])
    ->addIndex('rfc_message_id', ['name' => 'idx_comment_rfc_message_id'])
    ->update();
```

```php
// src/Service/GmailService.php — parseMessage añadir:
$data['rfc_message_id'] = trim($this->getHeader($headers, 'Message-ID'), '<>');
$data['in_reply_to']    = trim($this->getHeader($headers, 'In-Reply-To'), '<>');
$data['references']     = $this->getHeader($headers, 'References'); // se guarda completo
```

```php
// src/Service/TicketIngestionService.php — búsqueda extendida
private function findTicketForReply(array $emailData): ?Ticket
{
    // 1. By gmail_thread_id (lo de hoy)
    if (!empty($emailData['gmail_thread_id'])) {
        $t = $ticketsTable->find()->where(['gmail_thread_id' => $emailData['gmail_thread_id']])->first();
        if ($t) return $t;
    }
    // 2. By RFC In-Reply-To matching a stored rfc_message_id
    if (!empty($emailData['in_reply_to'])) {
        $comment = $this->fetchTable('TicketComments')->find()
            ->where(['rfc_message_id' => $emailData['in_reply_to']])
            ->first();
        if ($comment) {
            return $ticketsTable->get($comment->ticket_id);
        }
        $t = $ticketsTable->find()->where(['rfc_message_id' => $emailData['in_reply_to']])->first();
        if ($t) return $t;
    }
    // 3. By any References entry
    if (!empty($emailData['references'])) {
        $ids = array_map(fn($s) => trim($s, '<> '), preg_split('/\s+/', $emailData['references']));
        $t = $ticketsTable->find()->where(['rfc_message_id IN' => $ids])->first();
        if ($t) return $t;
    }
    return null;
}
```

Y al **enviar** notificaciones via `EmailService::sendEmail`, añadir el `Message-ID` propio en headers para que `In-Reply-To` del cliente luego coincida:

```php
$options['headers']['Message-ID'] = sprintf(
    '<ticket-%s-%s@%s>',
    $ticketNumber,
    bin2hex(random_bytes(6)),
    $ownDomain,
);
```

Persistir ese `Message-ID` en el comment recién creado (`ticket_comments.rfc_message_id`).

---

### 12.8 M-5 · Cola de retry para `markAsRead`

**Archivos:** nueva tabla `gmail_mark_read_pending`, `GmailImportService::run` (drenar cola al inicio).

**Migración:**

```php
$this->table('gmail_mark_read_pending')
    ->addColumn('gmail_message_id', 'string', ['limit' => 255])
    ->addColumn('attempts', 'integer', ['default' => 0])
    ->addColumn('last_error', 'string', ['limit' => 500, 'null' => true])
    ->addColumn('created', 'datetime')
    ->addColumn('modified', 'datetime')
    ->addIndex('gmail_message_id', ['unique' => true, 'name' => 'idx_pending_msg_id'])
    ->create();
```

```php
// src/Service/GmailImportService.php
private function drainPendingMarkAsRead(): void
{
    $pending = $this->fetchTable('GmailMarkReadPending')->find()
        ->where(['attempts <' => 3])
        ->limit(50)
        ->all();

    foreach ($pending as $row) {
        if ($this->gmail->markAsRead($row->gmail_message_id)) {
            $this->fetchTable('GmailMarkReadPending')->delete($row);
        } else {
            $row->attempts++;
            $this->fetchTable('GmailMarkReadPending')->save($row);
        }
    }
}

// y en run(), reemplazar cada $this->gmail->markAsRead($messageId):
private function safeMarkAsRead(string $messageId): void
{
    if (!$this->gmail->markAsRead($messageId)) {
        $this->fetchTable('GmailMarkReadPending')->save(
            $this->fetchTable('GmailMarkReadPending')->newEntity([
                'gmail_message_id' => $messageId,
            ])
        );
    }
}
```

---

### 12.9 B-1 · Token bucket compartido para attachments

**Archivos:** `src/Service/GmailService.php`.

Reemplazar el `usleep(200_000)` ciego por un token bucket en cache (compartido entre requests):

```php
// src/Service/Gmail/AttachmentRateLimiter.php
final class AttachmentRateLimiter
{
    private const KEY = 'gmail_attachment_bucket';
    private const CAPACITY = 5;          // burst
    private const REFILL_PER_SEC = 5;    // sostenido

    public static function acquire(): void
    {
        $now = microtime(true);
        $state = Cache::read(self::KEY, 'default') ?: ['tokens' => self::CAPACITY, 'updated' => $now];

        $elapsed = $now - $state['updated'];
        $state['tokens'] = min(self::CAPACITY, $state['tokens'] + $elapsed * self::REFILL_PER_SEC);
        $state['updated'] = $now;

        if ($state['tokens'] < 1) {
            $waitSec = (1 - $state['tokens']) / self::REFILL_PER_SEC;
            usleep((int)($waitSec * 1_000_000));
            $state['tokens'] = 0;
            $state['updated'] = microtime(true);
        } else {
            $state['tokens']--;
        }
        Cache::write(self::KEY, $state, 'default');
    }
}
```

Y en `GmailService::downloadAttachment`:

```php
AttachmentRateLimiter::acquire();
// (eliminar el usleep ciego)
```

**Riesgo:** la lectura/escritura de cache no es atómica entre procesos PHP-FPM. Para multi-worker estricto usar Redis con `INCR + EXPIRE` o un lock corto. En entorno single-host con APCu es aceptable.

---

### 12.10 B-2 · Selección correcta en `multipart/alternative`

**Archivo:** `src/Service/GmailService.php` (`extractMessageParts`).

```php
private function extractMessageParts(MessagePart $payload, array &$data): void
{
    $mimeType = $payload->getMimeType();
    $parts = $payload->getParts();

    if ($mimeType === 'multipart/alternative' && !empty($parts)) {
        // Prefer HTML branch; fall back to plain text.
        $htmlPart = null;
        $textPart = null;
        foreach ($parts as $p) {
            if ($p->getMimeType() === 'text/html')  { $htmlPart = $p; }
            if ($p->getMimeType() === 'text/plain') { $textPart = $p; }
        }
        $chosen = $htmlPart ?? $textPart;
        if ($chosen !== null) {
            $this->extractMessageParts($chosen, $data);
        }
        // Note: still descend into other (e.g. nested multipart/related) parts:
        foreach ($parts as $p) {
            if ($p !== $htmlPart && $p !== $textPart) {
                $this->extractMessageParts($p, $data);
            }
        }
        return;
    }

    // ... resto igual ...
}
```

---

### 12.11 B-3 · Detección de bulk/newsletter ampliada

**Archivo:** `src/Service/GmailService.php` (`isAutoReply`).

```php
if ($this->getHeader($headers, 'List-Unsubscribe') !== '')   { return true; }
if ($this->getHeader($headers, 'Feedback-ID') !== '')        { return true; }
if ($this->getHeader($headers, 'List-Id') !== '')            { return true; }
```

Añadir al final de la cadena existente. Cero impacto en falsos positivos para correspondencia humana normal.

---

### 12.12 B-4 · `gmail_user_email` auto-poblado

**Archivos:** `src/Controller/Admin/SettingsController.php` (`gmailAuth`), `src/Service/GmailService.php` (nuevo helper).

```php
// src/Service/GmailService.php
public function getAuthenticatedEmail(): string
{
    $profile = $this->getService()->users->getProfile('me');
    return (string)$profile->getEmailAddress();
}
```

```php
// src/Controller/Admin/SettingsController.php (gmailAuth, tras saveSetting de refresh_token)
try {
    $email = $gmailService->getAuthenticatedEmail();
    if ($email !== '') {
        $this->settingsService->saveSetting(SettingKeys::GMAIL_USER_EMAIL, $email);
        Log::info('Gmail user email auto-populated', ['email' => $email]);
    }
} catch (\Throwable $e) {
    Log::warning('Could not auto-populate gmail_user_email: ' . $e->getMessage());
    // no bloquear el flow — el operador puede setearlo manualmente
}
```

---

### 12.13 I-1 · Plan de salida de `google/apiclient`

No es acción inmediata. Vigilar el repo (`googleapis/google-api-php-client`). Cuando los SDKs per-service alcancen GA estable, evaluar migración aislada del cliente Gmail (capa `GmailService` aísla el resto del sistema, ese aislamiento facilita el cambio).

---

### 12.14 I-2 · Documentar requisito de proyecto OAuth Published

**Archivo:** `docs/operations/n8n-gmail-webhook.md` (o nuevo `docs/operations/gmail-oauth-setup.md`).

Añadir sección:

```markdown
## Pre-requisitos en Google Cloud Console

1. El proyecto OAuth debe estar en **Publishing status: In production**. Si está en
   **Testing** con External users, **todos los refresh_tokens caducan a 7 días**
   y el sistema dejará de importar emails ese plazo.
2. Solo se solicita el scope `https://www.googleapis.com/auth/gmail.modify`.
   Si solo aparece este scope (no readonly ni send), Google no requiere
   verificación adicional para apps de uso interno (workspace de la organización).
3. Configurar los redirect URIs autorizados: `https://<dominio>/oauth/gmail/callback`.
```

---

### 12.15 I-3 · Enmascarar PII en logs

**Archivo:** nuevo `src/Service/Util/LogMasker.php`.

```php
final class LogMasker
{
    public static function email(string $email): string
    {
        $atPos = strrpos($email, '@');
        if ($atPos === false || $atPos < 2) {
            return '***';
        }
        $local = substr($email, 0, $atPos);
        $domain = substr($email, $atPos);
        $head = substr($local, 0, 1);
        return $head . str_repeat('*', max(1, strlen($local) - 1)) . $domain;
    }
}
```

Usar en `EmailService` y `GmailImportService` para los logs `info`. Mantener email completo solo en `debug` (configurable por entorno).

---

### 12.16 Resumen de cambios por archivo

| Archivo | Hallazgos que toca |
|---------|-------------------|
| `src/Service/GmailService.php`             | H-1, H-2, H-3, M-1, M-2, M-3, M-4, B-2, B-3, B-4 |
| `src/Service/GmailImportService.php`       | M-2, M-3, M-5 |
| `src/Service/EmailService.php`             | H-3, M-4, I-3 |
| `src/Service/TicketIngestionService.php`   | M-4 |
| `src/Controller/Admin/SettingsController.php` | B-4 |
| Nuevos `src/Service/Gmail/*.php`           | H-2, B-1 |
| Nuevo `src/Service/Util/NotificationStamp.php` | H-3 |
| Nuevo `src/Service/Util/LogMasker.php`     | I-3 |
| Migraciones                                | M-2, M-4, M-5 |
| `src/Constants/SettingKeys.php`            | M-2 (`GMAIL_LAST_HISTORY_ID`) |
| `composer.json`                            | M-1 (`cache/filesystem-adapter`) |
| `docs/operations/`                         | I-2 |

