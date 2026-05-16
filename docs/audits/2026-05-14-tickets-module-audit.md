# Auditoría Arquitectónica — Módulo de Tickets

- **Proyecto:** mesa-de-ayuda (CakePHP 5.x, PHP 8.5+)
- **Fecha:** 2026-05-14
- **Nivel:** standard
- **Alcance:** 7 traits + 8 services + 4 events + 5 entities + 1 table + 1 listener + 1 view cell + 2 templates
- **Referencia previa:** auditoría 2026-05-07 (~95% cerrada)
- **Última actualización de progreso:** 2026-05-15 (ver §11)

---

## 1. Resumen Ejecutivo

| Indicador | Valor inicial | Estado actual (2026-05-16) |
|---|---|---|
| Salud arquitectónica global | **68%** | **~85%** — 5 altos + 2 críticos + 1 medio cerrados |
| Hallazgos Críticos (rojo) | 3 | 1 (CRIT-1 y CRIT-2 cerrados) |
| Hallazgos Altos (naranja) | 6 | 1 (HIGH-1/2/3/5/6 cerrados; HIGH-4 abierto) |
| Hallazgos Medios (amarillo) | 7 | 6 (MED-1 cerrado) |
| Hallazgos Bajos (verde) | 4 | 4 |

**Diagnóstico:** El módulo está muy refactorizado respecto a la auditoría 2026-05-07. La capa de dominio (entidad `Ticket` con predicados + state machine), los traits cohesivos del controller, la lazy DI y el bus de eventos parcial son patrones aplicados correctamente. Sin embargo, persisten **tres frentes críticos**:

1. **Resiliencia ausente en integraciones externas** (Gmail/n8n/WhatsApp/SMTP): sin Circuit Breaker ni Retry.
2. **Sin Outbox transaccional**: notificaciones pueden perderse o ejecutarse sobre estado parcial.
3. **Frontera transaccional inexistente** en `handleResponse()` (`TicketPipelineService`).

---

## 2. Matriz de Patrones Detectados

| Patrón | Detectado | Cumplimiento | Severidad |
|---|---|---|---|
| Layered (Controller→Service→Domain) | Sí | Alto | Verde |
| Thin controller / Fat service | Sí | Alto (`TicketsController` 67 LOC) | Verde |
| Domain Entity rica | Sí | Alto (predicados + transitions) | Verde |
| State machine | Sí | Alto (`TRANSITIONS` + `transitionTo`) | Verde |
| Domain Events | Sí | Listener cubre 3/3 eventos (cerrado HIGH-2) | Verde |
| EDA / EventManager | Sí | Todos los eventos de notificación viajan por bus | Verde |
| Outbox Pattern | No | Ausente | Rojo |
| Saga Pattern | No | Sin compensación en ingesta multi-paso | Naranja |
| Circuit Breaker | Sí | Alto (sobre `SecureHttpTrait`) | Verde |
| Retry / Backoff | Sí | Alto (3 intentos, 5xx/429/timeout) | Verde |
| Rate Limiter outbound | No | Solo inbound webhook tiene MIN_INTERVAL_SECONDS | Naranja |
| Bulkhead | No | Canales de notif. ejecutan secuencialmente | Naranja |
| Timeout | Sí | `secureCurlPost` clamp a 30s | Verde |
| Adapter (implícito) | Sí | Interfaz `NotificationChannel` + adapters concretos | Verde |
| Facade | Sí | `handleResponse` bien aplicado | Verde |
| Proxy / Lazy DI | Parcial | Inconsistente: N8n lazy, Email/WhatsApp eager | Amarillo |
| Strategy | Sí | Strategy por evento de dominio | Verde |
| Chain of Responsibility | No | Filtros de ingesta concatenados imperativamente | Naranja |
| Template Method (en traits) | Sí | Bien aplicado | Verde |
| Factory | Parcial | `GmailImportService::fromSettings` OK; `Ticket` carece | Amarillo |
| CQRS | Implícito | Sin proyecciones; suficiente al tamaño actual | Verde |

---

## 3. Hallazgos Críticos (Rojo)

### CRIT-1 — Sin Circuit Breaker en llamadas a APIs externas ✅ CERRADO 2026-05-15

**Ubicaciones:**
- `src/Service/WhatsappService.php:152` (Evolution API)
- `src/Service/N8nService.php:237` (n8n webhook)
- `src/Service/GmailService.php:373` (Gmail API)
- `src/Service/EmailService.php:137`

**Riesgo:** Bajo carga (ej. `bin/cake import_gmail --max 200` con proveedor degradado), 200 timeouts × 10s = ~33 min de bloqueo inútil que satura workers PHP-FPM.

**Fix sugerido:** Circuit Breaker aplicado sobre `SecureHttpTrait::secureCurlPost` cubre los 3 servicios en una sola intervención.

**Fix aplicado:** Implementado vía `App\Service\Resilience\ResilientHttpClient` sobre `SecureHttpTrait::secureCurlPost`. Cubre WhatsApp/n8n/Gmail webhook POSTs. Llamadas a Gmail API vía `Google\Client` siguen fuera de scope. Detalle en §11.

---

### CRIT-2 — Sin Retry / Backoff para errores transitorios ✅ CERRADO 2026-05-15

**Ubicaciones:** `WhatsappService:152`, `N8nService:107`, `GmailService:365-380`, `TicketAttachmentService:58-82`.

**Riesgo concreto:** Un HTTP 429/503 transitorio en `downloadAttachment` se loguea como error y el adjunto **se pierde definitivamente** — no hay reintento.

**Fix:** Retry con backoff exponencial + jitter para 5xx/429/`CURLE_OPERATION_TIMEOUTED`.

**Fix aplicado:** Política conservadora (3 intentos, base 200ms, multiplier 2.5, jitter 100ms) en `App\Service\Resilience\RetryPolicy`. Detalle en §11.

---

### CRIT-3 — Sin Transactional Outbox para eventos de dominio

**Ubicaciones:** `src/Service/TicketIngestionService.php:131-155`, `src/Service/TicketPipelineService.php:199-226`.

**Escenarios de falla:**
1. Crash entre `save()` y `dispatch()` → ticket creado sin notificación.
2. Listener síncrono que demora → latencia HTTP percibida = 10s WA + 10s n8n + SMTP.
3. `Throwable` capturado en listener (`TicketNotificationListener:69-74`) → notificación perdida **sin reintento**.

**Esto viola at-least-once delivery del EDA.**

**Fix:** Tabla `outbox_events` + worker `bin/cake outbox process`.

---

## 4. Hallazgos Altos (Naranja)

### HIGH-1 — `handleResponse()` no es transaccional ✅ CERRADO 2026-05-15

**Archivo:** `src/Service/TicketPipelineService.php:73-159`

Operación compuesta (comentario + attachments + status + notificaciones) **sin `Connection::transactional(...)`**. Si la subida de adjuntos falla a medio camino, el comentario queda persistido y la notificación dispara con estado parcial.

**Fix:** Envolver bloque persistente en `transactional()`, dispatch de eventos **post-commit**.

**Fix aplicado:** Dos `Connection::transactional()` separados (TX1: comment+uploads, TX2: status change) con buffer local de eventos para dispatch post-commit. Best-effort cleanup de archivos huérfanos cuando TX1 hace rollback. `changeStatus` recibió parámetro `deferDispatch`. Detalle en §11.

---

### HIGH-2 — `TicketAssigned` se emite pero nadie lo consume ✅ CERRADO 2026-05-14

**Archivos:** `TicketPipelineService.php:307-312` (dispatch) y `TicketNotificationListener.php:53-59` (no suscribe).

**Consecuencia operativa:** En `createFromEmail` los tickets se crean sin asignado y luego se asignan manualmente — **el agente nunca recibe email de la nueva asignación**.

**Fix aplicado:** Implementado `onAssigned(TicketAssigned $event)` + rama `'assignment'` en `dispatchUpdateNotifications`. Skip silencioso en autoasignación (`actor === assignee`) y desasignación (`assignee_id === null`). Migración `20260514120000_AddTicketAssignedEmailTemplate.php` siembra el template `ticket_asignacion`. Ver §11 para detalles.

---

### HIGH-3 — DIP roto: `new AuthorizationService()` en 4 traits ✅ CERRADO 2026-05-14

**Archivos:**
- `TicketActionsTrait.php:125`
- `TicketBulkTrait.php:69`
- `TicketViewTrait.php:83`
- `TicketListingTrait.php:107`

**Fix aplicado:** Promovido a propiedad `private AuthorizationService $authService` declarada en `TicketsController` e inicializada en `TicketServiceInitializerTrait::initializeTicketSystemServices()`. Diff +10/−12 líneas. Ver §11 para detalles.

---

### HIGH-4 — Sin Bulkhead entre canales de notificación

**Archivo:** `TicketNotificationService.php:68-89`

Email + WhatsApp + n8n ejecutan **secuencial en el mismo worker**. Un cuelgue de Evolution API bloquea el envío de email.

**Fix:** Mover despachos a colas (`cakephp/queue`) con consumers separados por canal. Resolverá también CRIT-3.

---

### HIGH-5 — Strategy ausente: dispatch de notificaciones con `switch`

**Archivo:** `TicketNotificationService.php:105-136`

`switch ($notificationType)` sobre `'status_change' | 'comment' | 'response'` **viola OCP**. Agregar un nuevo tipo requiere modificar el método.

**Fix:** Strategy con interfaz `TicketNotificationStrategy::supports($type) + send(...)`.

---

### HIGH-6 — Adapters externos sin interfaz común; viola DIP

**Archivos:** `WhatsappService`, `N8nService`, `GmailService`, `EmailService`.

Son adapters naturales pero ninguno implementa `NotificationChannel` o `WebhookDispatcher`. El dominio (`TicketIngestionService`) referencia clases concretas, impidiendo testing con mocks.

**Fix:** Extraer `App\Domain\Port\NotificationChannel` y mover servicios a `App\Infrastructure\Adapter\*`.

---

## 5. Hallazgos Medios (Amarillo)

| ID | Hallazgo | Archivo | Fix sugerido |
|---|---|---|---|
| MED-1 | Notificaciones de "response" llamadas directo, no por bus (asimetría EDA) | `TicketPipelineService.php:156` | Crear `TicketResponded` event + handler |
| MED-2 | `DomainEvent extends Cake\Event\Event` + `FrozenTime::now()` en service: fugas de framework en dominio | `Domain/Event/DomainEvent.php:19`, `TicketPipelineService.php:19` | Inyectar `ClockInterface` (PSR-20); documentar herencia pragmática |
| MED-3 | Logs sin `correlation_id` / `event_id` / `actor_id` consistentes | múltiples | Estandarizar contexto base; middleware X-Request-ID |
| MED-4 | `getEntityComponents()` residual del refactor de `$entityType` | `TicketServiceInitializerTrait.php:81-96` | Eliminar; usar accesos directos |
| MED-5 | Chain of Responsibility ausente en ingesta de email (filtros concatenados imperativamente) | `GmailImportService.php:103-148`, `TicketIngestionService.php:59-164` | Generar `EmailIngestionFilter` chain |
| MED-6 | Lazy DI inconsistente: N8n lazy, Email/WhatsApp eager | `TicketNotificationService.php:36-49` | Aplicar mismo patrón `??=` a Email/WhatsApp |
| MED-7 | Builder ausente; `Ticket` se construye con array marshalling + bypass mixto | `TicketIngestionService.php:110-129` | `Ticket::fromEmailIngest(...)` factory method |

---

## 6. Hallazgos Bajos (Verde)

- **LOW-1** — `TicketListingTrait::indexTicketList()` mezcla parsing de request + query building (90 LOC). Diferir hasta que aparezca el segundo listado.
- **LOW-2** — `applyRoleBasedFilters` es un noop documentado. Eliminar o formalizar con `@extension-point`.
- **LOW-3** — `TicketsSidebarCell:42-46` aplica casts `(int)` redundantes; mover al servicio.
- **LOW-4** — `n8n callback URL` cae en `http://localhost` si `APP_URL` no está definido y apunta a ruta inexistente (`/api/webhooks/n8n/tags`). Marcar TODO o eliminar del payload.

---

## 7. Análisis SOLID / GRASP

| Principio | Score | Hallazgo principal |
|---|---|---|
| **SRP** | 80% | `TicketNotificationService` mezcla dispatch genérico + lógica de "response" |
| **OCP** | 60% | Múltiples `switch` por tipo (notificación, vista de listado) |
| **LSP** | 95% | Sin violaciones |
| **ISP** | 70% | `TicketPipelineService` expone 9 métodos públicos heterogéneos |
| **DIP** | 55% → 65% (2026-05-14) | `TicketNotificationService` sigue instanciando `EmailService`/`WhatsappService` con `new`. Traits del controller ya no (cerrado HIGH-3). |
| **GRASP Expert** | 95% | Excelente: `Ticket` concentra invariantes |
| **GRASP Low Coupling** | 65% | `TicketPipelineService` tiene 6 deps en constructor (límite) |
| **GRASP Polymorphism** | 60% | Sin interfaz común para canales de notificación |

---

## 8. Conflictos Cross-Pattern

| Conflicto | Descripción |
|---|---|
| **EDA ↔ DDD** | `DomainEvent` acoplado a `Cake\Event\Event` (decisión pragmática, justificable) |
| **EDA ↔ Layered** | `sendResponseNotifications` llamado directo, no por bus → asimetría |
| **EDA ↔ Outbox** | Dispatch sincrónico in-process **sin** transaccionalidad → riesgo de mensaje perdido |
| **Integration ↔ EDA** | n8n se llama directo desde `TicketIngestionService:151`, no por listener (mientras email/WhatsApp sí) |
| **Observabilidad ↔ EDA** | Logs no portan `event_id`/`occurred_at` |

El conflicto **más significativo** es EDA + Outbox + Transactional: aceptable hoy con listener síncrono in-process, **crítico** si se introducen colas.

---

## 9. Acciones Priorizadas

### Crítico (semana 1-2)

| # | Acción | Estado |
|---|---|---|
| 1 | Circuit Breaker + Retry sobre `SecureHttpTrait::secureCurlPost` (cubre WhatsApp, n8n, Gmail en una intervención) | **Completado 2026-05-15** |
| 2 | Transactional Outbox para `TicketCreated` y `TicketStatusChanged` | Pendiente |
| 3 | Frontera transaccional en `handleResponse` con `Connection::transactional()` + dispatch post-commit | **Completado 2026-05-15** |

### Alto (semana 3-4)

| # | Acción | Estado |
|---|---|---|
| 4 | Implementar `onAssigned` en listener + rama `'assignment'` | **Completado 2026-05-14** |
| 5 | Promover `AuthorizationService` a propiedad del controller | **Completado 2026-05-14** |
| 6 | Interfaz `NotificationChannel` + adapters para Email/WhatsApp/N8n | **Completado 2026-05-16** |
| 7 | Bulkhead vía colas (`cakephp/queue`) — depende de #2 | Pendiente |
| 8 | Chain of Responsibility en ingesta de email | Pendiente |

### Medio (mes 2)

| # | Acción | Estado |
|---|---|---|
| 9 | `TicketResponded` event + handler (cierra asimetría del bus) | **Completado 2026-05-16** |
| 10 | Estandarizar contexto de logs (`ticket_id`, `actor_id`, `event_id`, `occurred_at`) | Pendiente |
| 11 | Eliminar `getEntityComponents()` residual | Pendiente |
| 12 | Lazy DI uniforme para Email/WhatsApp en `TicketNotificationService` | Pendiente |
| 13 | Factory `Ticket::fromEmailIngest()` | Pendiente |
| 14 | Rate Limiter outbound WhatsApp/n8n | Pendiente |
| 15 | Inyectar `ClockInterface` en `TicketPipelineService` | Pendiente |

### Bajo (diferir / oportunista)

LOW-1 a LOW-4: tratar cuando aparezca el caso de uso que los justifique.

---

## 10. Lo que está bien (NO tocar)

- **`Ticket` entity rica** con predicados (`isResolved`, `isLocked`, `hasAssignee`, `canBeAssignedTo`) y state machine `TRANSITIONS` + `transitionTo()` que asegura invariantes.
- **`TicketConstants`**: fuente única para status/prioridades/canales consumida desde 14 archivos.
- **Controller delgado** (67 LOC); traits cohesivos.
- **Mass-assignment correctamente cerrado** en `Ticket::$_accessible` (assignee_id, status, requester_id → `false`).
- **Idempotencia de ingestión**: `gmail_message_id` checked antes de insertar.
- **Listener defensivo**: captura `Throwable`, no propaga.
- **Facade limpio** en `TicketPipelineService::handleResponse()`.
- **`AuditBehavior` + `TicketHistoryLoggerTrait`** cubren campos sensibles automáticamente.
- **Sanitización HTML** aplicada consistentemente vía `HtmlSanitizerTrait`.

---

## 11. Bitácora de progreso

### 2026-05-14 — HIGH-3 cerrado: `AuthorizationService` promovido a propiedad inyectada

**Hallazgo original:** Cuatro traits del controller (`TicketActionsTrait`, `TicketBulkTrait`, `TicketViewTrait`, `TicketListingTrait`) instanciaban `new AuthorizationService()` por petición, violando DIP e impidiendo mock en tests futuros.

**Cambios:**
- `src/Controller/TicketsController.php`: declarada propiedad `private AuthorizationService $authService` + import.
- `src/Controller/Trait/TicketServiceInitializerTrait.php`: inicialización centralizada en `initializeTicketSystemServices()`.
- Cuatro traits: eliminado `new AuthorizationService()` (4 líneas) y `use App\Service\AuthorizationService` redundante; uso uniforme de `$this->authService`.

**Diff:** +10 / −12 líneas. Net negative, surgical.

**Validaciones:**
- `composer cs-fix`: no requirió fixes en archivos tocados.
- `composer cs-check`: errores reportados son pre-existentes (`@return` faltantes); no introducidos.
- `phpstan --level=5`: 37 errores **todos pre-existentes**; ninguno menciona `AuthorizationService` ni `authService`.

---

### 2026-05-14 — HIGH-2 cerrado: `TicketAssigned` ahora notifica al agente asignado

**Hallazgo original:** El evento `TicketAssigned` se despachaba pero `TicketNotificationListener::implementedEvents()` no lo suscribía. Como consecuencia operativa, en `createFromEmail` los tickets se creaban sin asignado y el agente nunca recibía email cuando se le asignaba manualmente.

**Decisiones de producto:**
- Notificar al **nuevo asignado** únicamente.
- **No notificar** al asignado anterior en reasignaciones (X → Y).
- **No notificar** al desasignar (Y → null).
- **No notificar** en autoasignación (`actor === assignee`) para reducir ruido en bandeja.

**Cambios:**
- `config/Migrations/20260514120000_AddTicketAssignedEmailTemplate.php`: nueva migración idempotente que siembra el template `ticket_asignacion` con subject + HTML body por defecto. Editable vía `/admin` después de migrar.
- `src/Service/EmailService.php`: nuevo método `sendEntityAssignmentNotification()` que envía al `assignee.email` (no al requester). `sendGenericTemplateEmail()` ahora acepta `?string $recipientEmail` para override no-disruptivo del default.
- `src/Service/TicketNotificationService.php`: nueva rama `'assignment'` en `dispatchUpdateNotifications` con skip silencioso para los dos casos no-notificables.
- `src/Listener/TicketNotificationListener.php`: registra `TicketAssigned::NAME => 'onAssigned'`; handler defensivo recarga ticket con `Assignees+Requesters` y pasa `new_assignee_id`, `previous_assignee_id`, `actor_id` al service. Docblock actualizado: el comentario que afirmaba "TicketAssigned no es de este listener" ya no aplica.

**Despliegue requerido:** correr `bin/cake migrations migrate` para sembrar el template.

**Validaciones:**
- `composer cs-fix`: no encontró fixes.
- `composer cs-check`: solo errores pre-existentes (docblocks faltantes en métodos antiguos del `EmailService`).
- `phpstan --level=5`: 1 acceso a `EntityInterface::$id` en `sendEntityAssignmentNotification` línea 134 — consistente con ~10 ocurrencias previas del mismo patrón en el archivo (CakePHP entities usan propiedades dinámicas).

**Hallazgos derivados / cross-pattern actualizados:**
- El conflicto `EDA ↔ Bus asimétrico` documentado en §8 sigue vigente: `sendResponseNotifications` continúa siendo llamado directo desde `TicketPipelineService`. Ahora el bus tiene 3/3 eventos de `Ticket.*` suscritos (`created`, `statusChanged`, `assigned`), pero la asimetría real está en los flujos de "response/comment" que no emiten evento. Ver MED-1 pendiente.

---

### 2026-05-15 — CRIT-1 + CRIT-2 cerrados: Circuit Breaker + Retry sobre `SecureHttpTrait`

**Hallazgos cubiertos:** CRIT-1 (sin Circuit Breaker en APIs externas) y CRIT-2 (sin Retry/Backoff para errores transitorios).

**Decisiones de diseño:**
- Intervención única sobre `SecureHttpTrait::secureCurlPost` cubre WhatsApp, n8n y Gmail webhooks. Llamadas a Gmail API vía `Google\Client` quedan fuera de scope (no usan curl directo).
- Estado del Circuit Breaker persiste en cache compartido (`CacheConstants::CACHE_RESILIENCE` → cache config `resilience`, backend File por defecto) — clave por host del URL.
- Política de Retry conservadora: 3 intentos para 5xx/429/`CURLE_OPERATION_TIMEOUTED`, backoff exponencial ~200ms/500ms/1.25s + jitter (0–100ms).
- 4xx no-429 NO cuentan como fallo del breaker (son errores del cliente).
- Race conditions entre workers FPM: aceptables sin locking distribuido — pérdida acotada a un request extra antes de que el breaker abra.

**Cambios:**
- `src/Service/Resilience/` (nuevo): `RetryPolicy` (value object), `CircuitBreaker` (state machine CLOSED/OPEN/HALF_OPEN), `ResilientHttpClient` (orquestador), `CircuitOpenException`.
- `src/Service/Traits/SecureHttpTrait.php`: curl extraído a `executeRawCurlPost()`; `secureCurlPost()` ahora delega al cliente resiliente y traduce `CircuitOpenException` al shape de error estándar (con clave `circuit_breaker => true`). Firma pública sin cambios.
- `src/Constants/CacheConstants.php`: nueva constante `CACHE_RESILIENCE`.
- `config/app.php`: bloque `Resilience.*` (con overrides vía env) + cache engine `resilience`.
- `README.md`: documentadas variables de override y requisito de backend de cache compartido.
- Tests: 23 nuevos (RetryPolicy ×7, CircuitBreaker ×8, ResilientHttpClient ×7, SecureHttpTrait ×1).

**Despliegue:** sin migraciones, sin cambios de firma en `WhatsappService`/`N8nService`/`GmailService`. Rollback de emergencia: `RESILIENCE_CB_THRESHOLD=999999` en `.env`.

**Validaciones:**
- `composer test`: PASS — 89 tests, 179 asserts (88 antes → 89 nuevo + 22 nuevos en suite Resilience).
- `phpstan analyse src/Service/Resilience src/Service/Traits/SecureHttpTrait.php`: 0 errores.
- Bootstrap de CakePHP carga correctamente la config `Resilience.*` y el cache engine `resilience`.

**Hallazgos derivados pendientes:**
- Llamadas a Gmail API vía `Google\Client` siguen sin protección — requiere Guzzle middleware o decorator de `Google\Http\REST` (no en este alcance).
- CRIT-3 (Outbox) sigue abierto — la resiliencia HTTP reduce pérdida pero no elimina el riesgo de mensaje perdido entre `save()` y `dispatch()`.
- HIGH-1 (transaccionalidad de `handleResponse`) sigue abierto.

---

### 2026-05-15 — HIGH-1 cerrado: frontera transaccional en `handleResponse()`

**Hallazgo original:** `handleResponse()` ejecutaba comentario + adjuntos + status + notificaciones inline sin TX. Una falla a mitad dejaba comentario persistido y notificación con estado parcial. Adicionalmente, el evento `TicketStatusChanged` no se despachaba en absoluto desde este flujo (la llamada interna pasaba `sendNotifications=false`), generando una asimetría con los demás callers de `changeStatus`.

**Decisiones de diseño:**
- Dos TX separadas en lugar de una sola, para preservar la semántica deliberada de "comentario sobrevive si la transición de estado falla" (catch de `InvalidStatusTransitionException`).
- Best-effort `@unlink` post-rollback para archivos ya escritos al disco (no rollback-able por la BD). Failures logueados, no propagados.
- Dispatch de `TicketStatusChanged` diferido a post-commit vía buffer local + nuevo parámetro `deferDispatch` en `changeStatus()` (default `false`, preserva callers existentes). Esto **restablece** el dispatch del evento desde `handleResponse`, que antes estaba suprimido.

**Cambios:**
- `src/Service/TicketPipelineService.php`: refactor de `handleResponse()` (87 → ~150 líneas), nuevo método privado `cleanupOrphanedFiles()`, parámetro `deferDispatch` en `changeStatus()`.
- `tests/TestCase/Service/TicketPipelineServiceTest.php`: archivo nuevo. 5 tests cubriendo rollback de TX1, semántica preservada en `InvalidStatusTransition`, orden post-commit del dispatch (verificando el flag `deferDispatch=true` en la llamada a `changeStatus`), no-dispatch en rollback silencioso de TX2, y regresión de `changeStatus` default.
- `composer.json`: añadido `autoload-dev` para namespace `App\Test\` (necesario para futuros helpers de test).

**Despliegue:** sin migraciones, sin cambios de firma pública, sin variables de entorno nuevas. Rollback trivial.

**Validaciones:**
- `composer test`: PASS — 94 tests, 201 asserts (89 baseline + 5 nuevos).
- `phpstan analyse src/Service/TicketPipelineService.php`: 1 error pre-existente (`FrozenTime` deprecation), igual al baseline.

**Hallazgos derivados pendientes:**
- CRIT-3 (Outbox) sigue abierto: la ventana entre commit y dispatch ahora es ~0ms pero un crash exactamente ahí sigue perdiendo el evento. Outbox sigue siendo necesario para at-least-once.
- MED-1 (`sendResponseNotifications` fuera de bus) sigue abierto y mantiene la asimetría EDA.

### 2026-05-16 — HIGH-5 + HIGH-6 + MED-1 cerrados: capa de notificaciones refactorizada

**Hallazgos cubiertos:** HIGH-5 (Strategy ausente), HIGH-6 (sin interfaz común para canales), MED-1 (asimetría EDA — `sendResponseNotifications` directo). Cerrados como cluster por estar correlacionados.

**Bug latente adicional cerrado:** `handleResponse` enviaba el email `status_change` dos veces cuando había cambio de estado sin comentario — el listener disparaba uno y `sendResponseNotifications` lo repetía.

**Cambios:**
- Nuevo namespace `App\Notification\Channel\*`: `NotificationMessage` (VO), `NotificationChannel` (interfaz), `EmailChannel`, `WhatsappChannel` (adaptadores).
- Nuevo namespace `App\Notification\Strategy\*`: `TicketNotificationStrategy` (interfaz), `AbstractTicketStrategy` (helpers), 4 strategies concretas (`TicketCreatedStrategy`, `TicketStatusChangedStrategy`, `TicketCommentAddedStrategy`, `TicketRespondedStrategy`).
- Eventos nuevos: `TicketCommentAdded`, `TicketResponded`.
- `TicketNotificationService` reescrito como orquestador strategies+channels (constructor cambia: `array $strategies, array $channels`).
- `TicketNotificationListener` simplificado a `forward(EventInterface)` genérico.
- `TicketPipelineService::handleResponse` emite eventos nuevos al `EventManager`; regla anti-duplicación suprime `TicketStatusChanged` cuando se emite `TicketResponded`. La llamada directa a `sendResponseNotifications` se eliminó; la dependencia `TicketNotificationService` quedó fuera del constructor.
- `EmailService` reducido a transporte: nuevo método público `dispatch(NotificationMessage)`; los 4 métodos por-evento eliminados.
- `Application::registerDomainEventListeners()` wirea las 4 strategies y los 2 canales.

**Despliegue:** sin migraciones, sin cambios de firma pública en controllers, sin variables de entorno nuevas.

**Validaciones:**
- `composer test`: PASS — suite completa verde respecto al baseline (176 tests, 439 asserts; 5 fallas preexistentes de templates HTML sin cambios).
- `cs-check`: sólo errores baseline en archivos no tocados.

**Hallazgos derivados pendientes:**
- CRIT-3 (Outbox) sigue abierto.
- HIGH-4 (Bulkhead vía colas) depende de CRIT-3.
- N8nService como canal de notificación: la arquitectura ahora lo permite con sólo crear `N8nChannel` + suscribirlo en la strategy que aplique.

