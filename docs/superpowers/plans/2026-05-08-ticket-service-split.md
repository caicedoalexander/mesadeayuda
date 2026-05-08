# TicketService Split + DI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el god-service `TicketService` (1046 LOC, 21 métodos) por 5 servicios cohesivos con inyección de dependencias explícita por constructor, sin cambio funcional.

**Architecture:** Decomposición por dominio: `TicketIngestionService` (Gmail), `TicketCommentService`, `TicketAttachmentService`, `TicketPipelineService` (transiciones/asignación/prioridad/tags/followers/handleResponse), `TicketNotificationService`. Helpers compartidos (`TicketHistoryLoggerTrait`, `HtmlSanitizerTrait`) en `src/Service/Traits/`. Constructores aceptan dependencias opcionales con default `new` interno (patrón SGI). Migración paso a paso con commits aislados y smoke manual.

**Tech Stack:** PHP 8.1+, CakePHP 5, HTMLPurifier, MySQL/MariaDB. Sin `tests/`; verificación por smoke manual (UI + `bin/cake import_gmail`).

**Spec:** `docs/superpowers/specs/2026-05-08-ticket-service-split-design.md`

---

## File Structure

**Crear:**
- `src/Service/Traits/TicketHistoryLoggerTrait.php` — helper `logHistory()` compartido.
- `src/Service/Traits/HtmlSanitizerTrait.php` — helper `sanitizeHtml()` compartido.
- `src/Service/TicketNotificationService.php` — despacho email + WhatsApp + n8n.
- `src/Service/TicketAttachmentService.php` — uploads y procesamiento de adjuntos.
- `src/Service/TicketCommentService.php` — comentarios manuales.
- `src/Service/TicketPipelineService.php` — transiciones, asignación, prioridad, tags, followers, `handleResponse`.
- `src/Service/TicketIngestionService.php` — creación desde Gmail.

**Modificar:**
- `src/Service/GmailImportService.php:48` — instanciar `TicketIngestionService` en lugar de `TicketService`.
- `src/Controller/Trait/TicketServiceInitializerTrait.php` — registrar `TicketPipelineService` (renombrar `ticketService` → `ticketPipeline`).
- `src/Controller/TicketsController.php:37` — actualizar property declaration.
- `src/Controller/Trait/TicketActionsTrait.php` — `$this->ticketService` → `$this->ticketPipeline`.
- `CLAUDE.md` — sección `src/Service/`.
- `docs/audits/2026-05-07-architecture-audit.md` — Anexo 4 (cierre 4.1 + 4.3).

**Eliminar (al final):**
- `src/Service/TicketService.php`.

---

## Convenciones de implementación

- Cada archivo PHP empieza con `<?php` + `declare(strict_types=1);` + namespace `App\Service` o `App\Service\Traits`.
- Cada commit corre `composer cs-fix && composer cs-check` antes (sin warnings).
- Mensajes de commit en formato convencional (`feat:`, `refactor:`, `docs:`).
- "Copiar método X desde `TicketService.php:N1-N2`" significa pegar literal el cuerpo del método; ajustar referencias a `$this->X` solo si está documentado en la tarea.

---

## Task 1: Crear `TicketHistoryLoggerTrait`

**Files:**
- Create: `src/Service/Traits/TicketHistoryLoggerTrait.php`

- [ ] **Step 1: Crear el trait con `logHistory()` extraído**

Crear `src/Service/Traits/TicketHistoryLoggerTrait.php` con el cuerpo de `logHistory` copiado verbatim desde `src/Service/TicketService.php:845-870`. El trait debe usar `LocatorAwareTrait` para tener acceso a `fetchTable()`.

```php
<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Provides logHistory() to write entries to history tables (e.g., ticket_history).
 * Consumed by ticket-domain services that mutate auditable fields.
 */
trait TicketHistoryLoggerTrait
{
    use LocatorAwareTrait;

    private function logHistory(
        string $tableName,
        string $foreignKey,
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?int $userId = null,
        ?string $description = null,
    ): void {
        $historyTable = $this->fetchTable($tableName);

        if (method_exists($historyTable, 'logChange')) {
            $historyTable->logChange($entityId, $fieldName, $oldValue, $newValue, $userId, $description);
        } else {
            $history = $historyTable->newEntity([
                $foreignKey => $entityId,
                'changed_by' => $userId,
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'description' => $description,
            ], ['accessibleFields' => ['changed_by' => true]]);
            $historyTable->save($history);
        }
    }
}
```

- [ ] **Step 2: Verificar estilo**

Run: `composer cs-fix src/Service/Traits/TicketHistoryLoggerTrait.php && composer cs-check`
Expected: sin warnings.

- [ ] **Step 3: Commit**

```bash
git add src/Service/Traits/TicketHistoryLoggerTrait.php
git commit -m "feat(service): add TicketHistoryLoggerTrait for shared history logging"
```

---

## Task 2: Crear `HtmlSanitizerTrait`

**Files:**
- Create: `src/Service/Traits/HtmlSanitizerTrait.php`

- [ ] **Step 1: Crear el trait con la lógica HTMLPurifier**

Cuerpo basado en `TicketService.php:613-631` (`sanitizeHtml`) y la configuración en `addComment` (líneas 706-713) — ambas usan la misma allowlist; consolidar.

```php
<?php
declare(strict_types=1);

namespace App\Service\Traits;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Provides sanitizeHtml() with a project-wide HTML allowlist for ticket bodies.
 * Used by services that persist user-submitted HTML (comments, ingested email).
 */
trait HtmlSanitizerTrait
{
    private function sanitizeHtml(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }
}
```

> Nota: el `sanitizeHtml` original (líneas 613-631) usa la misma config; verificar al copiar que las directivas coincidan. Si difieren, preservar TODAS (unión); documentar en el commit.

- [ ] **Step 2: Verificar estilo**

Run: `composer cs-fix src/Service/Traits/HtmlSanitizerTrait.php && composer cs-check`

- [ ] **Step 3: Commit**

```bash
git add src/Service/Traits/HtmlSanitizerTrait.php
git commit -m "feat(service): add HtmlSanitizerTrait with HTMLPurifier allowlist"
```

---

## Task 3: Crear `TicketNotificationService`

**Files:**
- Create: `src/Service/TicketNotificationService.php`

- [ ] **Step 1: Crear el servicio con DI explícita**

Encapsula las 3 funciones de despacho. Recibe `EmailService`, `WhatsappService`, `N8nService` por constructor (opcionales con default), preservando la inicialización lazy de `N8nService`.

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Ticket;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Exception;

/**
 * Dispatches outbound notifications (email, WhatsApp, n8n) for ticket events.
 * Centralizes notification logic previously embedded in TicketService.
 */
class TicketNotificationService
{
    private EmailService $emailService;
    private WhatsappService $whatsappService;
    private ?N8nService $n8nService;
    private ?array $systemConfig;

    public function __construct(
        ?array $systemConfig = null,
        ?EmailService $emailService = null,
        ?WhatsappService $whatsappService = null,
        ?N8nService $n8nService = null,
    ) {
        $this->systemConfig = $systemConfig;
        $this->emailService = $emailService ?? new EmailService($systemConfig);
        $this->whatsappService = $whatsappService ?? new WhatsappService($systemConfig);
        $this->n8nService = $n8nService;
    }

    private function getN8nService(): N8nService
    {
        if ($this->n8nService === null) {
            $this->n8nService = new N8nService($this->systemConfig);
        }

        return $this->n8nService;
    }

    // dispatchCreationNotifications: copy verbatim from TicketService.php:965-998
    // dispatchUpdateNotifications:   copy verbatim from TicketService.php:999-end
    // sendResponseNotifications:     copy verbatim from TicketService.php:875-908
    //
    // Inside copied methods, references to $this->emailService, $this->whatsappService,
    // and $this->getN8nService() resolve via the properties declared above — no rewriting needed.

    public function sendStatusChangeEmail(EntityInterface $entity, string $oldStatus, string $newStatus): void
    {
        try {
            $this->emailService->sendEntityStatusChangeNotification($entity, $oldStatus, $newStatus);
        } catch (Exception $e) {
            Log::error('Failed to send status change email notification: ' . $e->getMessage());
        }
    }
}
```

> El método `sendStatusChangeEmail` se introduce nuevo: encapsula el bloque try/catch que estaba inline en `TicketService::changeStatus` (líneas 678-682). `TicketPipelineService::changeStatus` lo llamará en lugar de tocar `EmailService` directo.

- [ ] **Step 2: Copiar `dispatchCreationNotifications`, `dispatchUpdateNotifications`, `sendResponseNotifications`**

Pegar literal desde `TicketService.php:965-998`, `:999-fin del archivo`, y `:875-908`. Mantener `protected`/`public` exactamente como están en el original (los 3 quedan `public` aquí porque los servicios externos los necesitan).

- [ ] **Step 3: cs-fix + cs-check**

Run: `composer cs-fix src/Service/TicketNotificationService.php && composer cs-check`

- [ ] **Step 4: Commit**

```bash
git add src/Service/TicketNotificationService.php
git commit -m "feat(service): add TicketNotificationService extracted from TicketService"
```

---

## Task 4: Crear `TicketAttachmentService`

**Files:**
- Create: `src/Service/TicketAttachmentService.php`

- [ ] **Step 1: Crear el servicio con `GenericAttachmentTrait`**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Attachment;
use App\Model\Entity\Ticket;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Handles attachment processing for tickets: uploaded files (forms) and
 * inline email attachments. Wraps GenericAttachmentTrait with ticket-specific
 * coercion (int id → Ticket entity).
 */
class TicketAttachmentService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;

    // processEmailAttachments: copy verbatim from TicketService.php:379-424
    public function processEmailAttachments(EntityInterface $ticket, array $attachments, int $userId, ?int $commentId = null): void
    {
        // ... copy body from TicketService.php:380-423 (open brace inclusive) ...
    }

    // saveUploadedFile: copy verbatim from TicketService.php:425-441
    public function saveUploadedFile(
        Ticket|int $ticket,
        UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null,
    ): ?Attachment {
        // ... copy body from TicketService.php:431-440 ...
    }
}
```

> `processEmailAttachments` era `private` en el original pero ahora debe ser `public` para ser llamable desde `TicketIngestionService`. Cambiar visibilidad al copiar.

- [ ] **Step 2: cs-fix + cs-check**

Run: `composer cs-fix src/Service/TicketAttachmentService.php && composer cs-check`

- [ ] **Step 3: Commit**

```bash
git add src/Service/TicketAttachmentService.php
git commit -m "feat(service): add TicketAttachmentService extracted from TicketService"
```

---

## Task 5: Crear `TicketCommentService`

**Files:**
- Create: `src/Service/TicketCommentService.php`

- [ ] **Step 1: Crear el servicio**

`addComment` consume `HtmlSanitizerTrait` (en lugar de configurar HTMLPurifier inline) y `TicketHistoryLoggerTrait` si fuera necesario logear (revisar `addComment` original — actualmente no logea historial directo, solo persiste el comment).

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Traits\HtmlSanitizerTrait;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Persists ticket comments. Sanitizes HTML body via HtmlSanitizerTrait.
 * Does NOT dispatch notifications — that responsibility belongs to
 * TicketPipelineService::handleResponse (response coordination) or callers
 * that need notification side-effects.
 */
class TicketCommentService
{
    use LocatorAwareTrait;
    use HtmlSanitizerTrait;

    public function __construct(?array $systemConfig = null)
    {
        // systemConfig accepted for symmetry with sibling services; not used today.
    }

    public function addComment(
        int $entityId,
        ?int $userId,
        string $body,
        string $type = 'public',
        bool $isSystem = false,
        ?array $emailTo = null,
        ?array $emailCc = null,
    ): ?EntityInterface {
        // Copy body from TicketService.php:704-end-of-method, BUT replace lines 706-713
        // (the inline HTMLPurifier_Config setup) with a single call:
        //     $sanitizedBody = $this->sanitizeHtml($body);
    }
}
```

- [ ] **Step 2: Copiar el cuerpo de `addComment` verbatim desde `TicketService.php:704-746` con la sustitución indicada**

Localizar el bloque:
```php
$config = HTMLPurifier_Config::createDefault();
$config->set('HTML.Allowed', '...');
// ... 6 líneas más
$sanitizedBody = $purifier->purify($body);
```
Reemplazar por:
```php
$sanitizedBody = $this->sanitizeHtml($body);
```

- [ ] **Step 3: cs-fix + cs-check**

- [ ] **Step 4: Commit**

```bash
git add src/Service/TicketCommentService.php
git commit -m "feat(service): add TicketCommentService extracted from TicketService"
```

---

## Task 6: Crear `TicketPipelineService`

**Files:**
- Create: `src/Service/TicketPipelineService.php`

Es el servicio más grande (~430 LOC). Consume `TicketCommentService`, `TicketAttachmentService`, `TicketNotificationService` por constructor.

- [ ] **Step 1: Crear el esqueleto con DI explícita**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Ticket;
use App\Service\Exception\InvalidStatusTransitionException;
use App\Service\Traits\TicketHistoryLoggerTrait;
use Cake\Datasource\EntityInterface;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Orchestrates ticket pipeline operations: status transitions, assignment,
 * priority changes, tags, followers, and the combined handleResponse flow
 * (comment + status + uploads + notifications).
 */
class TicketPipelineService
{
    use LocatorAwareTrait;
    use TicketHistoryLoggerTrait;

    private TicketCommentService $comments;
    private TicketAttachmentService $attachments;
    private TicketNotificationService $notifications;
    private ?array $systemConfig;

    public function __construct(
        ?array $systemConfig = null,
        ?TicketCommentService $comments = null,
        ?TicketAttachmentService $attachments = null,
        ?TicketNotificationService $notifications = null,
    ) {
        $this->systemConfig = $systemConfig;
        $this->comments = $comments ?? new TicketCommentService($systemConfig);
        $this->attachments = $attachments ?? new TicketAttachmentService();
        $this->notifications = $notifications ?? new TicketNotificationService($systemConfig);
    }

    // Methods follow in subsequent steps.
}
```

- [ ] **Step 2: Copiar `addTag` desde `TicketService.php:527-560`**

Pegar verbatim. No usa servicios delegados; solo tablas + `logHistory`.

- [ ] **Step 3: Copiar `removeTag` desde `TicketService.php:561-582`**

Pegar verbatim.

- [ ] **Step 4: Copiar `addFollower` desde `TicketService.php:583-612`**

Pegar verbatim.

- [ ] **Step 5: Copiar `assign` desde `TicketService.php:748-795` con sustitución**

Pegar verbatim, EXCEPTO la línea final del flujo exitoso:
```php
$this->addComment($entity->id, $userId, "Asignado a {$newAssigneeName}", 'internal', true);
```
Reemplazar por:
```php
$this->comments->addComment($entity->id, $userId, "Asignado a {$newAssigneeName}", 'internal', true);
```

- [ ] **Step 6: Copiar `changePriority` desde `TicketService.php:800-840` con sustitución**

Pegar verbatim, EXCEPTO el bloque final:
```php
$this->addComment(
    $entity->id,
    $userId,
    "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
    'internal',
    true,
);
```
Reemplazar por:
```php
$this->comments->addComment(
    $entity->id,
    $userId,
    "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
    'internal',
    true,
);
```

- [ ] **Step 7: Copiar `changeStatus` desde `TicketService.php:632-686` con dos sustituciones**

Pegar verbatim, EXCEPTO:

Sustitución A — la llamada a `addComment`:
```php
$this->addComment($entity->id, $userId, $systemComment, 'internal', true);
```
→
```php
$this->comments->addComment($entity->id, $userId, $systemComment, 'internal', true);
```

Sustitución B — el bloque try/catch de email (líneas 677-683):
```php
if ($sendNotifications) {
    try {
        $this->emailService->sendEntityStatusChangeNotification($entity, $oldStatus, $newStatus);
    } catch (Exception $e) {
        Log::error('Failed to send status change email notification: ' . $e->getMessage());
    }
}
```
→
```php
if ($sendNotifications) {
    $this->notifications->sendStatusChangeEmail($entity, $oldStatus, $newStatus);
}
```

- [ ] **Step 8: Copiar `handleResponse` desde `TicketService.php:452-end-of-method` con sustituciones**

Pegar verbatim. Identificar dentro del cuerpo todas las llamadas a:
- `$this->addComment(...)` → `$this->comments->addComment(...)`
- `$this->saveUploadedFile(...)` → `$this->attachments->saveUploadedFile(...)`
- `$this->sendResponseNotifications(...)` → `$this->notifications->sendResponseNotifications(...)`
- `$this->buildResponseResult(...)` → mantener (es helper privado de `TicketPipelineService`, ver Step 10)
- `$this->decodeEmailRecipients(...)` → mantener (idem, ver Step 11)
- `$this->changeStatus(...)` (si aparece) → mantener (es método de este mismo servicio)

- [ ] **Step 9: Copiar `buildResponseResult` desde `TicketService.php:913-936` (private)**

Pegar verbatim. Cambiar visibilidad de `protected` a `private`.

- [ ] **Step 10: Copiar `decodeEmailRecipients` desde `TicketService.php:938-963` (private)**

Pegar verbatim. Cambiar visibilidad de `protected` a `private`.

- [ ] **Step 11: cs-fix + cs-check**

Run: `composer cs-fix src/Service/TicketPipelineService.php && composer cs-check`
Expected: sin warnings. Si quedan unused imports (`Exception`, `Log`) por las sustituciones, eliminarlos manualmente.

- [ ] **Step 12: Commit**

```bash
git add src/Service/TicketPipelineService.php
git commit -m "feat(service): add TicketPipelineService extracted from TicketService"
```

---

## Task 7: Crear `TicketIngestionService`

**Files:**
- Create: `src/Service/TicketIngestionService.php`

- [ ] **Step 1: Crear el servicio con DI explícita**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Traits\TicketHistoryLoggerTrait;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Creates tickets and comments from external sources (Gmail today,
 * potentially WhatsApp/other channels in the future).
 */
class TicketIngestionService
{
    use LocatorAwareTrait;
    use HtmlSanitizerTrait;
    use TicketHistoryLoggerTrait;

    private TicketAttachmentService $attachments;
    private TicketNotificationService $notifications;
    private ?array $systemConfig;

    public function __construct(
        ?array $systemConfig = null,
        ?TicketAttachmentService $attachments = null,
        ?TicketNotificationService $notifications = null,
    ) {
        $this->systemConfig = $systemConfig;
        $this->attachments = $attachments ?? new TicketAttachmentService();
        $this->notifications = $notifications ?? new TicketNotificationService($systemConfig);
    }

    // Methods follow in subsequent steps.
}
```

- [ ] **Step 2: Copiar `createFromEmail` desde `TicketService.php:73-184` con sustituciones**

Pegar verbatim. Sustituciones:
- `$this->processEmailAttachments(...)` → `$this->attachments->processEmailAttachments(...)`
- `$this->dispatchCreationNotifications(...)` → `$this->notifications->dispatchCreationNotifications(...)`
- Si hay llamadas a `$this->sanitizeHtml(...)` mantenerlas (resuelven via trait).

- [ ] **Step 3: Copiar `createCommentFromEmail` desde `TicketService.php:185-278` con sustituciones**

Mismas sustituciones que Step 2.

- [ ] **Step 4: Copiar `findOrCreateUser` desde `TicketService.php:279-328` (private)**

Pegar verbatim.

- [ ] **Step 5: Copiar `isEmailInTicketRecipients` desde `TicketService.php:329-378` (private)**

Pegar verbatim.

- [ ] **Step 6: Copiar `decodeEmailRecipients` si `createCommentFromEmail` lo usa**

Verificar con `grep -n "decodeEmailRecipients" src/Service/TicketService.php`. Si las líneas 185-278 contienen llamadas a `$this->decodeEmailRecipients`, copiar el método (líneas 938-963) como `private` aquí también.

> Aceptable duplicar `decodeEmailRecipients` entre `TicketPipelineService` y `TicketIngestionService` (≈25 LOC × 2). Alternativa: mover a un trait. **Decisión:** duplicar por ahora; consolidar a trait solo si aparece un tercer consumidor.

- [ ] **Step 7: cs-fix + cs-check**

- [ ] **Step 8: Commit**

```bash
git add src/Service/TicketIngestionService.php
git commit -m "feat(service): add TicketIngestionService extracted from TicketService"
```

---

## Task 8: Migrar `GmailImportService` y smoke de ingesta

**Files:**
- Modify: `src/Service/GmailImportService.php:48`

- [ ] **Step 1: Reemplazar la instanciación**

Cambio puntual en `src/Service/GmailImportService.php:48`:
```php
new TicketService(self::loadSystemSettings()),
```
→
```php
new TicketIngestionService(self::loadSystemSettings()),
```

Verificar que el import al inicio del archivo (`use App\Service\TicketService;`) se actualice a `use App\Service\TicketIngestionService;` (o se añada y elimine el viejo). Confirmar con `grep "TicketService\|TicketIngestionService" src/Service/GmailImportService.php` que no queden referencias huérfanas.

Verificar que los métodos llamados sobre la instancia sean SOLO `createFromEmail` y `createCommentFromEmail`:
```bash
grep -n "ticketService\|->createFromEmail\|->createCommentFromEmail" src/Service/GmailImportService.php
```
Si aparece otro método (ej. `processEmailAttachments`), añadirlo a `TicketIngestionService` antes de continuar.

- [ ] **Step 2: cs-fix + cs-check**

Run: `composer cs-fix && composer cs-check`

- [ ] **Step 3: Smoke — ingesta Gmail**

Pre-requisito: configuración OAuth de Gmail válida en `system_settings` (la que ya está en uso).

Crear/usar un thread de Gmail con un mensaje sin replies anteriores. Disparar:
```bash
bin/cake import_gmail --max 1
```

Verificar:
- En `tickets`: nueva fila creada con `gmail_message_id`, `gmail_thread_id`, `subject`, `body` y `requester_id` poblados.
- En `ticket_history`: una entrada de creación.
- Notificaciones: revisar `logs/error.log` por errores de email/n8n. Si hay infraestructura de email/n8n disponible, verificar que llegue.
- En `attachments`: si el email tenía adjuntos, deben quedar en `webroot/uploads/attachments/{ticket_number}/`.

Luego responder al thread con un nuevo mensaje y disparar otra vez `bin/cake import_gmail --max 1`. Verificar que se cree una `ticket_comments` enlazada al ticket existente, no un ticket nuevo.

Si algún paso falla → no commitear. Diagnosticar (probablemente una sustitución incorrecta en Tasks 3-7).

- [ ] **Step 4: Commit**

```bash
git add src/Service/GmailImportService.php
git commit -m "refactor(gmail): use TicketIngestionService for inbound email"
```

---

## Task 9: Migrar controller traits y smoke de UI

**Files:**
- Modify: `src/Controller/TicketsController.php`
- Modify: `src/Controller/Trait/TicketServiceInitializerTrait.php`
- Modify: `src/Controller/Trait/TicketActionsTrait.php`

`TicketBulkTrait`, `TicketViewTrait`, `TicketHistoryTrait`, `TicketListingTrait` consumen el servicio via `getEntityComponents()['service']`, así que basta con que `getEntityComponents()` devuelva el `ticketPipeline` para que sigan funcionando sin tocarse.

- [ ] **Step 1: Renombrar property en `TicketsController.php:37`**

Cambio:
```php
private TicketService $ticketService;
```
→
```php
private TicketPipelineService $ticketPipeline;
```

Y el `use` en cabecera:
```php
use App\Service\TicketService;
```
→
```php
use App\Service\TicketPipelineService;
```

- [ ] **Step 2: Actualizar `TicketServiceInitializerTrait.php`**

En `src/Controller/Trait/TicketServiceInitializerTrait.php`:

Cambio en línea 8:
```php
use App\Service\TicketService;
```
→
```php
use App\Service\TicketPipelineService;
```

Cambio en líneas 38-40 (`initializeTicketSystemServices`):
```php
$this->initializeServices([
    'ticketService' => TicketService::class,
]);
```
→
```php
$this->initializeServices([
    'ticketPipeline' => TicketPipelineService::class,
]);
```

Cambio en `getEntityComponents()` línea 91:
```php
'service' => $this->ticketService ?? null,
```
→
```php
'service' => $this->ticketPipeline ?? null,
```

Y en el docblock líneas 84-85, actualizar el tipo:
```
@return array{table: \Cake\ORM\Table, service: ?\App\Service\TicketPipelineService, ...}
```

- [ ] **Step 3: Actualizar `TicketActionsTrait.php`**

Tres líneas (59, 74, 89) cambian:
```php
$result = $this->ticketService->addTag((int)$id, $tagId);
$result = $this->ticketService->removeTag((int)$id, (int)$tagId);
$result = $this->ticketService->addFollower((int)$id, $userId);
```
→
```php
$result = $this->ticketPipeline->addTag((int)$id, $tagId);
$result = $this->ticketPipeline->removeTag((int)$id, (int)$tagId);
$result = $this->ticketPipeline->addFollower((int)$id, $userId);
```

- [ ] **Step 4: Verificar que no queden referencias a `ticketService`**

Run:
```bash
grep -rn "ticketService\|TicketService::class" src/Controller/
```
Expected: cero matches.

- [ ] **Step 5: cs-fix + cs-check**

Run: `composer cs-fix && composer cs-check`

- [ ] **Step 6: Smoke completo de UI**

Levantar la app (Docker o `bin/cake server`). Con un usuario admin/agente:

1. Abrir `/tickets` — verificar que el listado carga, sidebar counts presentes.
2. Abrir un ticket existente — verificar que la vista detalle carga.
3. **Asignar** el ticket a otro agente — verificar flash success, `ticket_history` con la nueva entrada, comentario interno "Asignado a X".
4. **Cambiar prioridad** — flash success, history, comentario interno.
5. **Cambiar estado** a uno legal (ej. `nuevo` → `abierto`) — flash success, history, comentario interno, status badge actualizado.
6. **Intentar transición ilegal** (ej. `resuelto` → `nuevo` si la matriz lo prohíbe) — debe mostrar flash de error "Transición de estado no permitida".
7. **Agregar comentario** público con upload de archivo — verificar `ticket_comments`, attachment en disco bajo `webroot/uploads/attachments/{ticket_number}/`, flash success, notificaciones (si hay email/n8n configurados).
8. **Agregar tag** y **remover tag** — verificar `ticket_tags`.
9. **Agregar follower** — verificar `ticket_followers`.
10. **Bulk assign** sobre 2-3 tickets desde el listado — verificar todos asignados, flash, histories.
11. **Bulk change priority** — idem.
12. Endpoint de historial JSON: abrir devtools en la vista de ticket y disparar la lazy-load del historial — verificar respuesta 200 con array.
13. Listar tickets con filtros laterales — verificar que cada filtro funcione.

Si alguno falla → no commitear. Diagnosticar y arreglar antes de continuar.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/TicketsController.php src/Controller/Trait/TicketServiceInitializerTrait.php src/Controller/Trait/TicketActionsTrait.php
git commit -m "refactor(controller): use TicketPipelineService in tickets traits"
```

---

## Task 10: Eliminar `TicketService` legacy

**Files:**
- Delete: `src/Service/TicketService.php`

- [ ] **Step 1: Verificar que no queden callers**

Run:
```bash
grep -rn "TicketService" src/ config/ templates/ --include="*.php" | grep -v "TicketServiceInitializerTrait\|TicketIngestionService\|TicketCommentService\|TicketAttachmentService\|TicketPipelineService\|TicketNotificationService"
```
Expected: cero matches (excepto las exclusiones).

Si aparece algún match imprevisto → diagnosticar y migrar antes de borrar. NO borrar mientras existan callers.

- [ ] **Step 2: Eliminar el archivo**

```bash
git rm src/Service/TicketService.php
```

- [ ] **Step 3: cs-check final**

Run: `composer cs-check`
Expected: sin warnings.

- [ ] **Step 4: Smoke spot-check final**

Verificar que la app sigue arrancando: cargar `/tickets`, abrir un ticket, agregar un comentario rápido. Si algo falla → restaurar `TicketService.php` con `git checkout HEAD~1 -- src/Service/TicketService.php` y diagnosticar.

- [ ] **Step 5: Commit**

```bash
git commit -m "refactor(service): remove legacy TicketService god-class

Closes audit altos 4.1 (TicketService split into 5 services) and
4.3 (explicit DI via optional constructor parameters)."
```

---

## Task 11: Actualizar documentación

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/audits/2026-05-07-architecture-audit.md`

- [ ] **Step 1: Actualizar `CLAUDE.md` sección `src/Service/`**

Buscar la sección que enumera servicios (alrededor de "Domain service `TicketService`"). Reemplazar la enumeración para listar los 5 servicios nuevos:

```markdown
- **`src/Service/`** — Business logic. Domain services agrupados por responsabilidad:
  - `TicketIngestionService` — creación de tickets/comentarios desde fuentes externas (Gmail).
  - `TicketPipelineService` — transiciones de estado, asignación, prioridad, tags, followers, `handleResponse`.
  - `TicketCommentService` — comentarios manuales con sanitización HTML.
  - `TicketAttachmentService` — uploads y procesamiento de adjuntos.
  - `TicketNotificationService` — despacho email + WhatsApp + n8n.

  Integraciones (`GmailService`, `EmailService`, `WhatsappService`, `N8nService`), cross-cutting helpers (`SidebarCountsService`, `NumberGenerationService`, `EmailTemplateRenderer`, `Renderer/NotificationRenderer`, `SettingsService`, `AuthorizationService`, `ProfileImageService`). Reusable mixin logic en `src/Service/Traits/`: `ConfigResolutionTrait`, `GenericAttachmentTrait`, `SecureHttpTrait`, `SettingsEncryptionTrait`, `HtmlSanitizerTrait`, `TicketHistoryLoggerTrait`.

  **DI patrón:** los servicios de dominio aceptan dependencias opcionales por constructor (`?Service $svc = null`) con default a instanciación interna; esto habilita testing futuro sin romper callers actuales.
```

- [ ] **Step 2: Añadir Anexo 4 al audit doc**

Agregar al final de `docs/audits/2026-05-07-architecture-audit.md`:

```markdown
### Anexo 4 — Cierre altos 4.1 y 4.3 (2026-05-08)

Cerrados:

- **4.1 ✅** `TicketService` (1046 LOC) eliminado y reemplazado por 5 servicios cohesivos:
  - `TicketIngestionService` (Gmail → ticket/comment)
  - `TicketPipelineService` (assign, changeStatus, changePriority, tags, followers, handleResponse)
  - `TicketCommentService` (addComment + HTML sanitization)
  - `TicketAttachmentService` (uploads, email attachments)
  - `TicketNotificationService` (email + WhatsApp + n8n dispatch)

  Helpers compartidos: `Service/Traits/TicketHistoryLoggerTrait`, `Service/Traits/HtmlSanitizerTrait`.

- **4.3 ✅** Constructores con DI explícita (patrón SGI): `?array $systemConfig`, `?EmailService`, `?WhatsappService`, `?N8nService`, y servicios pares según composición. Defaults a `new` interno para no romper callers existentes; mockables cuando exista `tests/`.

Plan de implementación: `docs/superpowers/plans/2026-05-08-ticket-service-split.md`.
Diseño: `docs/superpowers/specs/2026-05-08-ticket-service-split-design.md`.

**Pendientes restantes:** medios 5.1–5.7.
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/audits/2026-05-07-architecture-audit.md
git commit -m "docs(audit): close altos 4.1 (TicketService split) and 4.3 (explicit DI)"
```

---

## Verificación final del plan

Después de Task 11, run:
```bash
git log --oneline -15
composer cs-check
grep -rn "class TicketService\b" src/  # debe ser cero
ls src/Service/Ticket*.php              # debe listar 5 archivos nuevos
```

Si todo OK, el bloque está cerrado.
