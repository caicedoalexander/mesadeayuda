# Code Review — `src/` (HIGH)

**Alcance:** `src/` (93 archivos PHP, ~11.4k LOC)
**Branch:** `main` (sin diff — revisión de estado actual)
**Nivel:** HIGH (PSR + Tests + Bugs + Readability + SOLID + Security + Performance + Testability + DDD + Architecture)
**Fecha:** 2026-05-09

---

## Resumen ejecutivo

| Severidad | Total |
|---|---|
| 🔴 Critical | 1 |
| 🟠 Major | 10 |
| 🟡 Minor | 15 |
| 🟢 Suggestions | 12 |
| **Total** | **38** |

**Veredicto:** ⚠️ **APPROVE WITH COMMENTS**
Ninguno de los hallazgos es regresión de refactors recientes (eventos de dominio, `SystemConfig` DTO, DI con `?Service = null`). Son superficies preexistentes que la nueva arquitectura facilita corregir.

PSR: ✅ todos los archivos declaran `strict_types`, namespaces PSR-4 correctos, sin issues PSR-12 detectados.

---

## 🔴 Critical

### CR-001 — Cifrado silencioso de settings

**Categoría:** Seguridad / Integridad de datos
**Ubicación:** `src/Service/Traits/SettingsEncryptionTrait.php:55-65`, `src/Service/SettingsService.php:50-65`

**Problema:**
- `decryptSetting()` retorna `''` tanto para input vacío como para fallo de descifrado (sin distinguir).
- Si rota `Security.salt`, todas las settings cifradas (`webhook_gmail_import_token`, `gmail_refresh_token`, `gmail_client_secret_json`) decodifican a `''` silenciosamente.
- `saveSetting()` siempre re-cifra, así que re-guardar un valor ya cifrado lo cifra dos veces.

**Cómo resolver:**
1. En `decryptSetting()` lanzar `RuntimeException` (o retornar `null`) cuando `openssl_decrypt` falle; nunca coercionar a `''`.
2. En `encryptSetting()` rechazar strings ya prefijados con `{encrypted}` (idempotencia).
3. Añadir un tag HMAC al payload (`base64(iv + ciphertext + hmac)`) y verificarlo antes de devolver el plaintext.
4. Auditar los callers: hoy `WebhooksController::verifyToken()` ya guarda `$expected !== ''` (correcto). Replicar ese guardado o hacer que el descifrado fallido sea fail-loud.

---

## 🟠 Major

### MA-001 — God class `GmailService` (766 LOC)

**Categoría:** SRP
**Ubicación:** `src/Service/GmailService.php`

**Problema:** Una sola clase mezcla OAuth client init, message listing, MIME parse, attachment download, MIME building outbound, header parsing y system-email lookup.

**Cómo resolver:**
- Dividir en: `GmailClientFactory` (auth), `GmailReader` (list/parse/download), `GmailSender` (`createMimeMessage` + `sendEmail`), `EmailHeaderParser` (helper stateless).
- Mantener facade público para no romper callers.

---

### MA-002 — Round trips innecesarios en `assign`

**Categoría:** Performance / N+1
**Ubicación:** `src/Service/TicketPipelineService.php:259-266`

**Problema:** Tras guardar, hace `$usersTable->get($oldAssigneeId)` y `$usersTable->get($normalizedAssigneeId)` aunque `$targetUser` ya estaba cargado en el guard previo.

**Cómo resolver:** Reusar `$targetUser` para el nombre nuevo; cargar el viejo una sola vez con `contain` o saltar si solo cambia el ID. Extraer `formatAssigneeName(?User $u): string`.

---

### MA-003 — `GmailService` instanciado por adjunto

**Categoría:** Performance / N+1
**Ubicación:** `src/Service/TicketAttachmentService.php:34-69`

**Problema:** `processEmailAttachments()` hace `new GmailService(GmailService::loadConfigFromDatabase())` por llamada (con OAuth refresh roundtrip), y aplica `usleep(200_000)` incluso para adjuntos cacheados.

**Cómo resolver:**
- Inyectar `GmailService` (ya usado por ingestion) en lugar de instanciarlo.
- Mover el rate-limit `usleep` dentro de `GmailService::downloadAttachment` (responsabilidad del cliente, no del consumer).

---

### MA-004 — I/O en constructor de `N8nService`

**Categoría:** Performance / Constructor side effects
**Ubicación:** `src/Service/N8nService.php:33-43` (mismo patrón en `WhatsappService`)

**Problema:** Resuelve todas las settings (DB + cache) en `__construct()` aunque n8n esté deshabilitado. Llamado desde `TicketIngestionService::createFromEmail()` paga el costo siempre.

**Cómo resolver:** Lazy-load en primer uso (`getConfig()` ya existe en Whatsapp; replicar en N8n). Regla general: nunca I/O en constructores.

---

### MA-005 — Truncado HTML rompe markup y UTF-8

**Categoría:** Bug / Pérdida de datos
**Ubicación:** `src/Service/TicketIngestionService.php:204-212`

**Problema:** `substr($body, 0, 65000)` después de `sanitizeHtml()` corta tags HTML a la mitad (markup malformado) y puede partir secuencias UTF-8 multibyte.

**Cómo resolver:**
- Usar `mb_substr($body, 0, 65000)` para evitar corte UTF-8.
- Re-purificar con `HTMLPurifier` el chunk truncado para cerrar tags abiertos. Alternativa: truncar la representación en plain text antes de sanitizar.

---

### MA-006 — Comparación `'0'` contra `?int`

**Categoría:** Bug / Type mismatch
**Ubicación:** `src/Service/TicketPipelineService.php:233`

**Problema:** `$assigneeId === '0'` comparado contra parámetro tipado `?int`. La rama es inalcanzable y oscurece intent.

**Cómo resolver:** Eliminar la comparación con `'0'`. El cast a int ya lo hace `normalizeAssigneeId()` en el boundary del controller.

---

### MA-007 — Race condition en numeración de tickets

**Categoría:** Concurrencia / Data integrity
**Ubicación:** `src/Service/NumberGenerationService.php:22-42`

**Problema:** Read-then-format **no atómico**. Bursts concurrentes (Gmail import + webhook + creación manual) pueden producir el mismo `TKT-YYYY-NNNNN`. El validador `unique` lo captura como save error, pero la ingestion lo loguea y descarta silenciosamente.

**Cómo resolver:** Tres opciones (elegir una y documentar):
1. **Recomendado:** secuencia DB / auto-increment + formato en read (`TKT-{year}-{padded(id)}`).
2. `SELECT … FOR UPDATE` dentro de transacción.
3. Retry con backoff sobre violación de `unique`.

---

### MA-008 — Email del bot WhatsApp hardcoded como trust boundary

**Categoría:** Bug / Auth surface
**Ubicación:** `src/Service/TicketIngestionService.php:101-105`

**Problema:** `mesadeayuda.whatsapp@gmail.com` hardcoded decide el `channel`. Es configuración (no lógica de dominio) y además es trust boundary: quien controle ese email entra como canal "WhatsApp".

**Cómo resolver:** Mover a `system_settings` (`whatsapp_bot_email`). Tratar el channel como label de presentación: nunca debe gatear autorización.

---

### MA-009 — `new AuthorizationService()` por acción

**Categoría:** Encapsulation
**Ubicación:** `src/Controller/Trait/TicketActionsTrait.php:125-130, 172-176, 211-215`

**Problema:** Cada acción re-instancia `AuthorizationService` (stateless) y duplica el check `$entity->isLocked()` que ya hace `TicketPipelineService`.

**Cómo resolver:**
- Inyectar `AuthorizationService` como propiedad en `TicketServiceInitializerTrait`.
- Centralizar el lock-check en el servicio, capturar `InvalidStatusTransitionException` en el trait y traducir a flash.

---

### MA-010 — Bootstrap acoplado a `EventManager::instance()` y cache

**Categoría:** Testability
**Ubicación:** `src/Application.php:76-83`, `src/Controller/Trait/TicketServiceInitializerTrait.php:25-33`

**Problema:** `EventManager::instance()` y `Cache::read(...)` se tocan en bootstrap; el listener se registra siempre (incluso en CLI/test) y arrastra `EmailService` + `WhatsappService`.

**Cómo resolver:** Implementar `Application::services(ContainerInterface $container)` (hoy vacío). Registrar `TicketNotificationService` y dependencias ahí; los traits piden por DI en lugar de `new`.

---

## 🟡 Minor

### MI-001 — `EmailService` instancia helpers de View

**Ubicación:** `src/Service/EmailService.php:13, 273-286`

**Problema:** Servicio (capa Application) instancia `App\View\Helper\UserHelper` y `Cake\View\View` para URL de profile image — leak de capa.

**Cómo resolver:** Usar `ProfileImageService::getProfileImageUrl()` (ya existe) o crear `ProfileImageUrlResolver` con dependencia mínima al `Router`.

---

### MI-002 — `resolved_at` se setea en el servicio

**Ubicación:** `src/Service/TicketPipelineService.php:170-172`

**Problema:** `if ($newStatus === 'resuelto' && !$entity->resolved_at)` vive en el servicio; la entidad ya tiene reglas de transición.

**Cómo resolver:** Añadir `Ticket::markResolved(FrozenTime $now)` (o expandir `applyStatusChange(string $new, FrozenTime $now)`). El servicio delega y guarda.

---

### MI-003 — Magic strings pese a `TicketConstants`

**Ubicación:** `TicketPipelineService.php:170`, `TicketIngestionService.php:114-115`, varios traits

**Problema:** Literales `'resuelto'`, `'nuevo'`, `'media'`, `'public'`, `'internal'`, `'email'`, `'whatsapp'` esparcidos.

**Cómo resolver:** Reemplazar por `TicketConstants::STATUS_RESUELTO`, `STATUS_NUEVO`, `PRIORITY_MEDIA`, `COMMENT_PUBLIC`, `COMMENT_INTERNAL`. Añadir `CHANNELS` si hace falta. Fix mecánico, bajo riesgo.

---

### MI-004 — `_accessible` drift

**Ubicación:** `src/Model/Entity/Ticket.php:50-73`

**Problema:** `_accessible` permite mass-assign de `description` pero la ingestion sobrescribe con `accessibleFields` ad-hoc — fácil que se salgan de sincronía.

**Cómo resolver:** Centralizar en defaults de la entidad o documentar la convención. Considerar factory `Ticket::createFromEmail()` que aserte invariantes.

---

### MI-005 — Método largo `createMimeMessage` (120 LOC)

**Ubicación:** `src/Service/GmailService.php:563-682`

**Problema:** Maneja From/To/Cc/Bcc/headers/subject/body/attachments; repite el bloque `is_array → numeric → encodeEmailHeader` 4 veces.

**Cómo resolver:** Extraer `buildAddressList(array|string $addresses): string` y `buildHeader(string $name, mixed $value): string`. Ideal: migrar a `cakephp/mailer` o `Symfony\Mime` y eliminar MIME hand-built.

---

### MI-006 — Switch grande en `findWithFilters`

**Ubicación:** `src/Model/Table/TicketsTable.php:208-281`

**Problema:** 9 `case`, varios duplicados (`pendientes`, `nuevos`, `abiertos` solo difieren en el status).

**Cómo resolver:** Map `[$view => fn]` o finders dedicados (`findNewByAgent`). Usar `FrozenTime::now()->subDays(7)` en lugar de `strtotime` para `'recientes'`.

---

### MI-007 — Wrappers public→protected residuales

**Ubicación:** `src/Controller/Trait/TicketActionsTrait.php`

**Problema:** Acciones públicas (`addComment`, `assign`) son wrappers de 1-2 líneas sobre `addTicketComment`, `assignTicket` — residuo del módulo Compras eliminado. También `getEntityComponents()` devuelve array indirecto innecesario.

**Cómo resolver:** Inline las acciones públicas y eliminar las protected, o viceversa. Drop del array indirecto: `$this->Tickets` y literales `'Ticket'` son más claros con un solo consumer.

---

### MI-008 — `->order()` deprecado en Cake 5

**Ubicación:** `src/Controller/Trait/TicketHistoryTrait.php:62`

**Problema:** Cake 5 deprecó `order()`; el resto del código usa `orderBy()`.

**Cómo resolver:** Cambiar a `orderBy()`.

---

### MI-009 — Triple decode de recipients

**Ubicación:** `EmailService.php:307-329`, `TicketPipelineService.php:466-484`, `EmailRecipientsTrait.php`

**Problema:** Tres implementaciones de "decode JSON-or-array recipients → `[{name,email}]`".

**Cómo resolver:** Consolidar en VO `RecipientsList` (immutable). Cada call site se vuelve `RecipientsList::from($raw)`. Resuelve también SU-004.

---

### MI-010 — Hardening de open redirect

**Ubicación:** `src/Controller/UsersController.php:46`

**Problema:** `preg_match('#^/[a-zA-Z0-9]#', $target) && !str_contains($target, '//')` no captura `/\evil` (legacy browsers) ni `/%2f%2fevil` post-decode.

**Cómo resolver:** Whitelist explícita de controllers conocidos, o usar `Router::parse()` y validar el controller resultante. Rechazar `\\` y `%2f%2f` post-decode.

---

### MI-011 — `view()` con eager contain

**Ubicación:** `src/Controller/Trait/TicketViewTrait.php:115-130`

**Problema:** Siempre contiene `TicketComments=>['Users']`, `Attachments`, `Tags`, `TicketFollowers=>['Users']`. Tickets con cientos de comentarios disparan fan-out.

**Cómo resolver:** Paginar comentarios (lazy load como `history`) o usar `containConditions` con límite. Verificar índice en `ticket_comments.ticket_id`.

---

### MI-012 — Magic constant `200000` con comentario "PERFORMANCE FIX"

**Ubicación:** `src/Service/TicketAttachmentService.php:41-44`

**Cómo resolver:** Promover a constante de clase `GMAIL_RATE_LIMIT_DELAY_US = 200_000` con rationale en PHPDoc.

---

### MI-013 — `new GmailService()` solo para parsear headers

**Ubicación:** `src/Service/TicketIngestionService.php:75-77, 176-178`

**Problema:** Instancia `GmailService` solo para usar `extractEmailAddress`/`extractName` (que no necesitan OAuth).

**Cómo resolver:** Extraer a `EmailHeaderParser` stateless (parte de MA-001).

---

### MI-014 — `TicketAssigned` se despacha pero el listener es no-op

**Ubicación:** `src/Listener/TicketNotificationListener.php:67-78`

**Problema:** Se emite el evento pero el listener solo loguea. Las notificaciones de asignación quedan a medias.

**Cómo resolver:** Decidir: (a) implementar la notificación de asignación (delegar a `TicketNotificationService::dispatchAssignmentNotification` o similar), o (b) eliminar el dispatch hasta que se necesite. No dejar stub permanente.

---

### MI-015 — Health check siempre 200

**Ubicación:** `src/Controller/HealthController.php:73-75`

**Problema:** Endpoint retorna 200 incluso con DB caída — Docker healthcheck no detecta hangs.

**Cómo resolver:** Retornar 503 cuando el check de DB falla, salvo en modo "primer arranque / migrations pendientes" detectable por sentinel table (ej. `system_settings` existe).

---

## 🟢 Suggestions

| ID | Categoría | Sugerencia |
|---|---|---|
| **SU-001** | Test Coverage | Tests unitarios para `User::isStaff()`, `AuthorizationService::isAssignmentDisabled()`, `NumberGenerationService` (race), `Ticket::canBeAssignedTo()` (locked + non-staff + inactive), `EmailRecipientsTrait` JSON edge cases. Lógica pura, fácil de cubrir. |
| **SU-002** | Test / Security | **Prioritario:** tests table-driven para `GenericAttachmentTrait::validateFile`/`sanitizeFilename`: doble extensión (`payload.php.jpg`), null byte (`a\0.exe.jpg`), path traversal (`../etc/passwd`), MIME mismatch. Es la superficie más sensible. |
| **SU-003** | Architecture | Migrar `?Foo $foo = null` defaults de `TicketPipelineService` a `Application::services()` (DI explícita). |
| **SU-004** | DDD | Promover `email_to`/`email_cc` (JSON+virtual array) a VO `RecipientsList` immutable. Resuelve también MI-009. |
| **SU-005** | Caching | Cachear `SidebarCountsService` ~30 s, invalidar en `afterSave`/`afterDelete` de Tickets. |
| **SU-006** | OWASP / CSP | CSP en `Application.php:131-147` permite `'unsafe-inline'` en `script-src`. Bootstrap 5 no lo necesita → migrar a nonce-based. |
| **SU-007** | Security / SSRF | `SecureHttpTrait.php:43-47`: `gethostbyname` falla → retorna `null` (allow). Considerar fail-closed en producción y verificar CNAMEs (DNS rebinding). |
| **SU-008** | Logging | `TicketPipelineService.php:248-252` usa `print_r(..., true)` y concatenación. Migrar a `Log::error($msg, $context)` estructurado. |
| **SU-009** | DI | `SettingsController::initialize()` hace `new SettingsService()`. Mover a constructor DI cuando MA-010 esté wirado. |
| **SU-010** | Naming / API | `getEntityComponents()` ofrece `[0,1,2]` numérico **y** keys nombrados. Elegir uno; el dual API es footgun. |
| **SU-011** | DDD / Naming | `TicketNotificationService::sendResponseNotifications` toma 9 params posicionales. Wrap en `ResponseDispatchContext` DTO (mismo patrón que `SystemConfig`). |
| **SU-012** | Performance | `HtmlSanitizerTrait.php:23-30` instancia `HTMLPurifier` por llamada (heavy). Reusar singleton + cache de definición. |

---

## Roadmap sugerido (orden por riesgo / impacto)

1. **CR-001** — Robustez de cifrado (HMAC + rechazo de doble cifrado + fail-loud)
2. **MA-007** — Race en numeración de tickets (transacción + retry o secuencia DB)
3. **MA-005** — Truncado HTML seguro (`mb_substr` + re-purify)
4. **MA-001** — Dividir `GmailService` (Reader / Sender / ClientFactory / HeaderParser)
5. **MA-003 + MA-004** — Lazy-load + DI en services con I/O
6. **SU-002** — Tests para `GenericAttachmentTrait` (seguridad de uploads)
7. **MA-010** — Wirear `Application::services()` para DI explícita
8. **MI-003** — Sustituir literales por `TicketConstants` (mecánico, bajo riesgo)
9. **MI-008** — `order()` → `orderBy()`
10. **MI-014** — Cerrar el ciclo de `TicketAssigned` (implementar o eliminar dispatch)

---

## Notas

- **PSR-12:** sin issues detectados. CakePHP CodeSniffer ruleset configurado en `phpcs.xml`.
- **Estado del refactor reciente (mayo 2026):** los moves a domain events, `SystemConfig` DTO y constructor DI con `?Service = null` son mejoras claras. Ninguno de los Critical/Major es regresión de ese trabajo.
- **WIP reconocido en `CLAUDE.md`:** `TicketCommentAdded` / `TicketPriorityChanged` aún no existen como eventos; `TicketPipelineService::handleResponse` sigue llamando notificaciones directamente.
