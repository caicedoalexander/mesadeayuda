# Code Review Report — `src/`

**Fecha:** 2026-05-09
**Modo:** PATH (full project audit)
**Path:** `src/`
**Archivos revisados:** 91 PHP files (CakePHP 5.x helpdesk, ~10k LOC)
**Nivel:** HIGH (PSR + Tests + Encapsulation + SOLID + Bugs + Readability + Security + Performance + Testability + DDD + Architecture)
**Verdict:** ❌ **REQUEST CHANGES**

---

## Resumen ejecutivo

| Severidad      | Total |
| -------------- | ----- |
| 🔴 Critical    | 3     |
| 🟠 Major       | 8     |
| 🟡 Minor       | 11    |
| 🟢 Suggestion  | 5     |
| **Total**      | 27    |

Arquitectura sólida (predicados DDD adoptados, `INSERT…ON DUPLICATE KEY` atómico, defensas SSRF, listeners de eventos catch-all-Throwable, 100% `declare(strict_types=1)`). Los hallazgos críticos no son del estilo "esto se rompe ya" sino "huecos explotables bajo condiciones adversariales o que rompen invariantes en el flujo bulk".

### Lo que está bien hecho

- Thin controllers con composición de traits cohesiva (`TicketsController` = 67 líneas, descompuesto en 6 traits de región).
- Eventos de dominio (`TicketCreated`, `TicketAssigned`, `TicketStatusChanged`) despachados via global EventManager; listener puentea a notificaciones.
- Resolución de configuración en 3 niveles (`ConfigResolutionTrait`) evita hits redundantes a DB.
- `INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID(last_seq + 1)` atómico en `NumberGenerationService` (sin race read-modify-write).
- 100% de los archivos PHP con `declare(strict_types=1);`.
- Auth de webhook con `hash_equals` (timing-safe).
- Protección SSRF (`SecureHttpTrait`): allowlist de schemes, blocklist de hosts, filtro de IPs privadas, sin FOLLOWLOCATION, protocolos restringidos.
- `GenericAttachmentTrait` valida extensión + MIME declarado + MIME por content-sniff (finfo), bloquea ejecutables, sanitiza nombres contra traversal/null-byte/double-extension.

---

## 🔴 Critical (deben bloquear merge)

### CR-001 — Cache key drift en settings

**Ubicación:** `src/Controller/AppController.php:76-89` ↔ `src/Service/SettingsService.php:93`

`AppController` lee con `Cache::remember(CacheConstants::CACHE_SETTINGS, …)` pero `SettingsService::loadAll()` escribe con la string literal `'system_settings'`. `clearAllCaches()` (`SettingsService.php:28-33`) invalida la constante → tras `saveSetting`, queda un blob descifrado (refresh tokens, webhook secret, client_secret JSON) en la otra slot hasta que expire el TTL.

**Recomendación:** usar la constante canónica en lectura *y* escritura. Test unitario que confirme que un `saveSetting` round-trip invalida la ruta de lectura.

---

### CR-002 — Rotación atómica del webhook token

**Ubicación:** `src/Controller/Admin/SettingsController.php:50-60`

`regenerateWebhookToken` rota el secret de producción sin ventana de gracia → cualquier petición de n8n en vuelo se rompe en seco. Adicionalmente, las acciones `gmailClientSecret`, `regenerateWebhookToken` y `gmailAuth` están unlocked de FormProtection — vale la pena verificar con un integration-test que el `beforeFilter` admin realmente short-circuita POSTs no-admin.

**Recomendación:**
1. Soportar ventana de overlap breve (almacenar `previous_token` con TTL) para que n8n pueda rotar sin perder tráfico inflight.
2. Escribir test de integración que POSTea como usuario autenticado no-admin y verifica el short-circuit.

---

### CR-003 — `bulkChangeTicketPriority` bypassa el service

**Ubicación:** `src/Controller/Trait/TicketBulkTrait.php:108-150`

El bulk escribe directamente con `$table->save($entity)` y sólo loguea a `TicketHistory` — bypassa `TicketPipelineService::changePriority()`, que añade el comentario interno de auditoría que el flujo single sí emite. Single-flow y bulk-flow ahora producen **trails de auditoría divergentes** para la misma mutación. Peor: el bulk no chequea `$entity->isLocked()`, así que tickets resueltos *pueden* re-priorizarse en bulk — contradice el invariante respetado en todo lo demás.

**Recomendación:** rutear las operaciones bulk a través de `TicketPipelineService::changePriority()` (loop sobre IDs). No reimplementar la mutación en el trait del controller. Esto también elimina la llamada duplicada a `logChange()`.

---

## 🟠 Major

### CR-004 — `application/octet-stream` en allowlist MIME

**Ubicación:** `src/Service/Traits/GenericAttachmentTrait.php:36-58, 444-454`

`application/octet-stream` está en el allowlist para `pdf, doc, docx, xls, xlsx, ppt, pptx, rar` porque algunos navegadores lo envían. Combinado con la rama lenient de `verifyMimeTypeFromContent` ("si claimedMIME está en allowlist → aceptar"), un atacante puede subir un `.exe` renombrado a `evil.pdf` con CT octet-stream: finfo lo identifica correctamente como `application/x-dosexec`, pero la función cae en la rama `in_array($claimedMime, $allowedMimes)` y lo acepta. La extensión está bloqueada por `FORBIDDEN_EXTENSIONS`, pero el content-sniffing queda derrotado.

**Recomendación:** quitar los fallbacks de `application/octet-stream`. Si finfo dice X y X ≠ MIME esperado para esa extensión, rechazar — nunca confiar en `claimedMime` como veredicto final.

---

### CR-005 — Sanitización de HTML inbound a posteriori

**Ubicación:** `src/Service/GmailService.php:241-279` (`parseMessage`)

`body_html` inbound se base64-decodea y se entrega al pipeline **antes** de sanitizar. La sanitización ocurre después en `TicketIngestionService.php:90, 202`. Hoy es correcto, pero cualquier consumidor futuro que use `parseMessage()` sin pasar por `TicketIngestionService` (indexador de búsqueda, debug dump) filtra HTML sucio.

**Recomendación:** sanitizar en el boundary. `GmailService::parseMessage()` debería llamar `HtmlSanitizerTrait::sanitizeHtml` sobre `body_html` antes de retornar, o envolver el array en un VO tipado que sólo exponga HTML sanitizado. Defense in depth.

---

### CR-006 — XSS en plantillas de email

**Ubicación:** `src/Service/EmailTemplateRenderer.php:75-82` + `src/Service/EmailService.php:124-186`

`EmailTemplateRenderer::render()` hace `str_replace('{{key}}', $value)` contra strings de plantilla almacenadas como **HTML** en `email_templates.body_html`. Variables como `comment_body` (HTML ya sanitizado) pasan sin tocar — intencional. Pero `requester_name`, `comment_author`, `agent_name`, `subject` vienen de campos controlados por usuario y se insertan en contexto HTML sin escapar. Un requester con nombre `<script>alert(1)</script>` inyecta script en emails salientes (y en el preview de `EmailTemplatesController::preview`).

**Recomendación:** distinguir variables "raw HTML" (comment body sanitizado, attachments list de `NotificationRenderer`) de variables "texto" (nombres, subjects). Escapar las de texto con `htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')` antes de la sustitución. Mantener HTML bajo un marker separado (e.g., `{{!html_var}}`).

---

### CR-007 — `handleResponse` no captura `InvalidStatusTransitionException`

**Ubicación:** `src/Service/TicketPipelineService.php:130-135`

`changeStatus()` lanza `InvalidStatusTransitionException` para transiciones ilegales; el `handleResponse()` que la rodea no la captura — el response completo (comentario + uploads) ya se commiteó antes del throw, pero el usuario ve 500 y puede reintentar, duplicando el comentario. Adicionalmente, hay un `$entity->status = $newStatus;` redundante en la línea 132 después de que `changeStatus()` ya mutó y guardó la entidad.

**Recomendación:** envolver el `changeStatus()` con `try/catch` sobre `InvalidStatusTransitionException` y devolver un resultado estructurado de error. Eliminar la asignación redundante.

---

### CR-008 — Rate-limit sólo en éxito

**Ubicación:** `src/Controller/WebhooksController.php:42-65`

El `Cache::write(self::RATE_LIMIT_KEY, time(), …)` (línea 63) sólo se ejecuta en éxito. En error/excepción el rate-limit no se actualiza → un caller con errores hace bypass total del throttle de 60s. Inversamente, una corrida exitosa de 4 minutos termina con timestamp al final, así que el siguiente caller falla `ranRecently()` aunque acaba de pasar mucho tiempo.

**Recomendación:** mover el `Cache::write` a `finally` (o ejecutarlo *antes* de despachar la importación, después de pasar token + lock + recency). Considerar circuit-breaker key separada para escenarios de alto error rate.

---

### CR-009 — Doble fetch del User en `assign()`

**Ubicación:** `src/Service/TicketPipelineService.php:259-268, 236`

`assign()` hace `$usersTable->get($oldAssigneeId)` y `$usersTable->get($normalizedAssigneeId)` en cada asignación, sólo para formatear el string de history. Además, `$entity->canBeAssignedTo($targetUser)` ya cargó el target user en línea 236 — esa entidad se está fetcheando dos veces.

**Recomendación:** cachear el target user del guard de validación y reusarlo en el formateo. Single bulk query para old assignee.

---

### CR-010 — `new GmailService()` por mensaje

**Ubicación:** `src/Service/TicketIngestionService.php:75, 177`

`createFromEmail` y `createCommentFromEmail` instancian `new GmailService()` solamente para usar `extractEmailAddress`/`extractName` — pero el constructor del `GoogleClient` corre igual. Para un batch de 50 emails se construyen 50 instancias del cliente sólo para hacer regex. Construirlo también loggea `Log::error('Gmail client_secret not configured')` (línea 126) cada vez con config vacía, contaminando logs.

**Recomendación:** promover `extractEmailAddress` / `extractName` / `parseRecipients` a un helper estático (`EmailHeaderParser`) — no tienen estado de instancia. Dejar de construir `GoogleClient` para parseo.

---

### CR-011 — DNS-rebinding en `SecureHttpTrait`

**Ubicación:** `src/Service/Traits/SecureHttpTrait.php:43-48`

`gethostbyname` resuelve una vez para validar el filtro de IPs privadas, pero curl resuelve otra vez al conectar. Un hostname que resuelve a IP pública ahora y a `127.0.0.1` en `curl_exec` no se bloquea.

**Recomendación:** lockear la resolución con `CURLOPT_RESOLVE`, o usar allowlist de hostnames (configurable por admin) para `whatsapp_api_url` y `n8n_webhook_url` en vez de URLs arbitrarias.

---

## 🟡 Minor

### CR-012 — `GmailService` god-class

**Ubicación:** `src/Service/GmailService.php` (791 LOC)

Mezcla 5 responsabilidades: lifecycle del OAuth client, fetching de mensajes, parseo MIME, descarga de attachments, creación MIME outbound. `createMimeMessage` (113 LOC) construye MIME a mano — frágil y duplica lo que una librería MIME hace.

**Recomendación:** dividir en `GmailClientFactory` (loadConfig + initializeClient), `GmailMessageReader` (getMessages, parseMessage, downloadAttachment), `GmailMessageSender` (createMimeMessage, sendEmail), `EmailHeaderParser` (helpers estáticos). Considerar `Symfony\Mime` para construcción MIME raw.

---

### CR-013 — Magic email para detectar canal WhatsApp

**Ubicación:** `src/Service/TicketIngestionService.php:103-105`

Hardcoded `mesadeayuda.whatsapp@gmail.com` para determinar el canal.

**Recomendación:** extraer a `SettingKeys::WHATSAPP_BOT_EMAIL` y leer via `SystemConfig`.

---

### CR-014 — Mutación directa de `Ticket::status` desde el service

**Ubicación:** `src/Service/TicketPipelineService.php:132`

El `Ticket` tiene `canTransitionTo` pero no un mutador `applyStatus(string)`/`transitionTo(string)` que asserta el invariante en la asignación.

**Recomendación:** añadir `Ticket::transitionTo(string $newStatus): void` que asserte `canTransitionTo` y asigne; eliminar `$entity->status = …` directos desde servicios. Refuerza el refactor de predicados.

---

### CR-015 — `array_map('intval', explode(',', …))` repetido

**Ubicación:** `src/Controller/Trait/TicketBulkTrait.php:61, 111, 158, 205`

Repetido 4×. Vulnerable a input malformado: `''` → `[0]` → `$table->get(0)` lanza `RecordNotFoundException` silenciosamente swalloweada por el contador de errores.

**Recomendación:** extraer `private function parseEntityIds(): array` que filtre ints no-positivos. Fail loudly cuando el input está malformado.

---

### CR-016 — Fallback al user_id `1` en bulk

**Ubicación:** `src/Controller/Trait/TicketBulkTrait.php:65, 114`

`$userId = $actor ? … : 1;` — atribuir history a user 1 (probablemente admin) cuando no hay identidad autenticada es bizarro y peligroso.

**Recomendación:** `throw new LogicException('No authenticated user')` como hace `TicketActionsTrait::getCurrentUserId()`.

---

### CR-017 — Lista de agentes sin caché en cada view

**Ubicación:** `src/Controller/Trait/TicketViewTrait.php:79-83`

Cada view de ticket fetcha la lista de agentes (filtrada por rol + activo) — query separada por page load.

**Recomendación:** cachear la lista de agentes con TTL corto, invalidar en user create/deactivate.

---

### CR-018 — `$encryptedSettings` como property en trait

**Ubicación:** `src/Service/Traits/SettingsEncryptionTrait.php:35-41`

Property (no const) inicializada inline en un trait — duplica una instancia por clase usuaria.

**Recomendación:** convertir a `private const ENCRYPTED_SETTING_KEYS = [...]`.

---

### CR-019 — `EmailService` colapsa `SystemConfig` a array

**Ubicación:** `src/Service/EmailService.php:45-56`

Constructor recibe `SystemConfig` VO e inmediatamente lo colapsa a array via `toSettingsArray()`. Información tipada se pierde.

**Recomendación:** pasar `SystemConfig` (o sub-VO `GmailConfig`) end-to-end, eliminar el round-trip a array.

---

### CR-020 — `AuthorizationService::isAssignmentDisabled(mixed $user)`

**Ubicación:** `src/Service/AuthorizationService.php:22-31`

Probing array vs objeto (`get('role')` vs `['role']`) — smell SOLID/LSP. El caller debería normalizar a `User` o `IdentityInterface` antes de llamar.

**Recomendación:** type-hint `?IdentityInterface` (o `User|IdentityInterface|null`) y eliminar la rama de array.

---

### CR-021 — Duplicación de admin-role check en `beforeFilter`

**Ubicación:** `src/Controller/Admin/SettingsController.php:45-60`, `src/Controller/Admin/EmailTemplatesController.php:19-29`, `src/Controller/Admin/TagsController.php`

Tres controllers admin reimplementan el mismo check.

**Recomendación:** llamar `redirectByRole([RoleConstants::ROLE_ADMIN], 'admin')` (ya existe en `AppController`) en cada `beforeFilter`.

---

## 🟢 Suggestions

| ID     | Categoría    | Ubicación                                                            | Sugerencia                                                                                                                       |
| ------ | ------------ | -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| CR-022 | Testability  | `src/Service/NumberGenerationService.php:33`                         | Inyectar `ClockInterface` en vez de `(int)date('Y')` para test de cambio de año determinístico.                                 |
| CR-023 | Performance  | `src/Service/Renderer/NotificationRenderer.php:81-91`                | `renderAttachmentsHtml` abre con `<td>` sin contexto de tabla/row — funciona sólo dentro de una plantilla fija. Documentar o renderizar tabla completa. |
| CR-024 | Architecture | `src/Listener/TicketNotificationListener.php`                        | Listener registrado eagerly en `Application::bootstrap` incluso para CLI commands que nunca despachan eventos de ticket.        |
| CR-025 | Encapsulation| `src/Model/Entity/Trait/EmailRecipientsTrait.php:61, 81`             | Trait alcanza `$this->_fields` directamente (interno de Cake). Usar `$this->get('email_to')`.                                   |
| CR-026 | DX           | `src/Controller/Admin/SettingsController.php:84-90`                  | Allowlist de settings editables como array mágico local — duplica la verdad en `SettingKeys`. Considerar `SettingKeys::USER_EDITABLE` o `SettingDefinition` registry. |

---

## Category Summary

| Categoría             | 🔴 | 🟠 | 🟡 | 🟢 | Total |
| --------------------- | -- | -- | -- | -- | ----- |
| Security              | 2  | 3  | 0  | 0  | 5     |
| Bug                   | 1  | 3  | 1  | 0  | 5     |
| Performance           | 0  | 2  | 1  | 1  | 4     |
| Architecture          | 0  | 0  | 1  | 1  | 2     |
| DDD / Encapsulation   | 0  | 0  | 3  | 1  | 4     |
| Code Smell            | 0  | 0  | 3  | 0  | 3     |
| Readability           | 0  | 0  | 1  | 0  | 1     |
| Testability           | 0  | 0  | 0  | 1  | 1     |
| DX                    | 0  | 0  | 1  | 1  | 2     |
| **Total**             | 3  | 8  | 11 | 5  | 27    |

---

## Focus-Area Verdicts

| Concern                                          | Status            | Notas                                                                                                       |
| ------------------------------------------------ | ----------------- | ----------------------------------------------------------------------------------------------------------- |
| Webhook security (CSRF skip on `/webhooks/*`)    | Mostly OK         | Token con `hash_equals`; rate limit + file lock presentes. CR-008 es el gap.                                |
| HTML sanitization on inbound email               | OK funcionalmente | Centralizado en `HtmlSanitizerTrait`; truncation UTF-8/markup-safe correcta. CR-005/CR-006 son gaps de defense-in-depth. |
| Settings encryption / secret handling            | Mostly OK         | Encrypt idempotente, decrypt fail-loud, key-absence fail. CR-001 (cache key drift) es el defecto real.      |
| Domain event listener exception handling         | OK                | Ambos handlers envuelven en `try/Throwable`, loggean, nunca propagan (`TicketNotificationListener.php:61, 80`). |
| Atomic ticket-number allocation                  | OK                | `INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID(last_seq + 1)` correcto.                                 |
| AuditBehavior bypass risks                       | Gap               | CR-003: bulk priority bypassa el flujo de service-level system-comment + isLocked guard.                    |
| Lazy DI in `TicketServiceInitializerTrait`       | OK                | Settings cacheados → `SystemConfig` VO → instancias de service. Cadena lazy preservada.                     |
| DDD predicate adoption on `Ticket`               | Strong            | Predicates (`isLocked`, `canTransitionTo`, `canBeAssignedTo`, `belongsTo`, `wasCreatedFromEmail`) usados consistentemente. CR-014 empuja más allá. |

---

## Acciones requeridas para merge

1. Unificar la cache key de settings (`AppController.php:76` ↔ `SettingsService.php:93`); garantizar que `clearAllCaches()` invalida la key real (CR-001).
2. Rutear `bulkChangeTicketPriority` (`TicketBulkTrait.php:108`) por `TicketPipelineService::changePriority()` para restaurar paridad de auditoría + guard `isLocked` (CR-003).
3. Verificar (test de integración) que `SettingsController::beforeFilter()` short-circuita POSTs no-admin a `regenerateWebhookToken` / `gmailClientSecret`; añadir ventana de overlap para rotación del webhook token (CR-002).
4. Endurecer `GenericAttachmentTrait::verifyMimeTypeFromContent` para que finfo gane sobre `claimedMime`; quitar `application/octet-stream` del allowlist (CR-004).
5. Escapar variables de texto en `EmailTemplateRenderer::render` (`requester_name`, `subject`, `comment_author`, `agent_name`); sólo variables tipo HTML pasan sin escapar (CR-006).
6. Mover el `Cache::write` del rate-limit (`WebhooksController.php:63`) a `finally` para que paths de error también throttleen (CR-008).
7. Capturar `InvalidStatusTransitionException` en `TicketPipelineService::handleResponse` (línea 131) para que una transición mala no devuelva 500 después de que el comentario ya se commiteó (CR-007).

## Acciones recomendadas (no bloquean)

1. Descomponer `GmailService` (CR-012); promover `extractEmailAddress` a helper estático (CR-010).
2. Añadir `Ticket::transitionTo(string)` que asserte `canTransitionTo` (CR-014).
3. Reemplazar patrones `array_map('intval', explode(',', …))` y `userId = … : 1` en `TicketBulkTrait` (CR-015, CR-016).
4. Pasar `SystemConfig` end-to-end en `EmailService` (CR-019).
5. Type-hint `AuthorizationService::isAssignmentDisabled` contra `IdentityInterface` (CR-020).
