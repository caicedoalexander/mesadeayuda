# Auditoría Fase 4 — cierre de medios pendientes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los 5 medios pendientes (5.1, 5.2, 5.3, 5.5, 5.6) de `docs/audits/2026-05-07-architecture-audit.md` en una sola fase, dejando la auditoría 100% cerrada.

**Architecture:** Cinco sub-fases en orden de dependencia: sweep de excepciones tipadas → Value Object `SystemConfig` con sub-configs readonly → 3 domain events de Cake + 1 listener de notificaciones → bootstrap de PHPUnit + tests unitarios puros sobre entidad/VO/eventos → cierre documental. Sin cambios en comportamiento runtime fuera de la sub-fase de events (validada con smoke manual).

**Tech Stack:** PHP 8.1+, CakePHP 5.x, `Cake\Event\EventManager`, PHPUnit 10, composer scripts.

**Spec:** `docs/superpowers/specs/2026-05-08-audit-fase4-medios-design.md`

**Ajustes de scope respecto al spec (descubiertos al inspeccionar el código):**
- `GmailService` queda en `?array $config` (su shape es `['client_secret' => array, 'refresh_token' => string]`, distinto del system-wide array). Solo se modifican sus throws en 5.3.
- `EmailService`/`WhatsappService`/`N8nService` adoptan firma `?SystemConfig` pero internamente convierten a array (`$config?->toSettingsArray()`) para no tocar `ConfigResolutionTrait`.

---

## File Structure

### Archivos nuevos

- `src/Service/Exception/GmailAuthenticationException.php` — excepción tipada para fallos OAuth
- `src/Service/Exception/SettingsEncryptionException.php` — excepción tipada para fallos de cifrado
- `src/Service/Dto/SystemConfig.php` — VO raíz (composición de sub-configs)
- `src/Service/Dto/GmailConfig.php` — sub-config de Gmail
- `src/Service/Dto/SmtpConfig.php` — sub-config de SMTP (placeholder; SMTP no se usa hoy en helpdesk pero se incluye por completeness)
- `src/Service/Dto/N8nConfig.php` — sub-config de n8n
- `src/Service/Dto/WhatsappConfig.php` — sub-config de WhatsApp
- `src/Service/Dto/AppConfig.php` — sub-config de app (system_title)
- `src/Domain/Event/DomainEvent.php` — base abstract
- `src/Domain/Event/TicketCreated.php`
- `src/Domain/Event/TicketAssigned.php`
- `src/Domain/Event/TicketStatusChanged.php`
- `src/Listener/TicketNotificationListener.php`
- `phpunit.xml.dist` — config raíz
- `tests/bootstrap.php` — bootstrap PHPUnit
- `tests/TestCase/Model/Entity/TicketTest.php`
- `tests/TestCase/Service/Dto/SystemConfigTest.php`
- `tests/TestCase/Domain/Event/TicketCreatedTest.php`
- `tests/TestCase/Domain/Event/TicketAssignedTest.php`
- `tests/TestCase/Domain/Event/TicketStatusChangedTest.php`
- `tests/TestCase/Listener/TicketNotificationListenerTest.php`

### Archivos modificados

- `src/Service/GmailService.php` — 3 throws (líneas ~127, 131, 172)
- `src/Service/Traits/SettingsEncryptionTrait.php` — 1 throw (línea ~121)
- `src/Service/TicketIngestionService.php` — firma constructor + dispatcher inject + dispatch TicketCreated
- `src/Service/TicketPipelineService.php` — firma constructor + dispatcher inject + dispatch TicketAssigned/StatusChanged
- `src/Service/TicketCommentService.php` — firma constructor (solo VO)
- `src/Service/TicketAttachmentService.php` — firma constructor (si recibe config)
- `src/Service/TicketNotificationService.php` — firma constructor (solo VO)
- `src/Service/EmailService.php` — firma constructor (VO + toSettingsArray interno)
- `src/Service/WhatsappService.php` — firma constructor (VO + toSettingsArray interno)
- `src/Service/N8nService.php` — firma constructor (VO + toSettingsArray interno)
- `src/Controller/Trait/TicketServiceInitializerTrait.php` — construir VO desde cache
- `src/Application.php` — registrar listener en `bootstrap()`
- `composer.json` — `phpunit/phpunit ^10.5` en `require-dev` + scripts `test`
- `.gitignore` — `.phpunit.cache/`, `coverage/`
- `CLAUDE.md` — sección testing + mención de SystemConfig + domain events
- `docs/audits/2026-05-07-architecture-audit.md` — Anexo 6 con cierre

---

## Task 1: GmailAuthenticationException + reemplazo de throws

**Files:**
- Create: `src/Service/Exception/GmailAuthenticationException.php`
- Modify: `src/Service/GmailService.php` (líneas 18, 127, 131, 172)

- [ ] **Step 1: Create the exception class**

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when Gmail OAuth authentication or token refresh fails.
 *
 * Extends RuntimeException to remain compatible with existing
 * `catch (RuntimeException $e)` and `catch (Exception $e)` handlers.
 */
class GmailAuthenticationException extends RuntimeException
{
}
```

Save to `src/Service/Exception/GmailAuthenticationException.php`.

- [ ] **Step 2: Update GmailService imports**

Open `src/Service/GmailService.php`. Replace line 18:

```php
use RuntimeException;
```

with:

```php
use App\Service\Exception\GmailAuthenticationException;
```

- [ ] **Step 3: Replace throw at line 127**

Old:
```php
throw new RuntimeException('Gmail authentication failed: ' . ($token['error_description'] ?? $token['error']));
```

New:
```php
throw new GmailAuthenticationException('Gmail authentication failed: ' . ($token['error_description'] ?? $token['error']));
```

- [ ] **Step 4: Replace throw at line 131**

Old:
```php
throw new RuntimeException('Gmail authentication failed. Please re-authenticate in Admin Settings.');
```

New:
```php
throw new GmailAuthenticationException('Gmail authentication failed. Please re-authenticate in Admin Settings.');
```

- [ ] **Step 5: Replace throw at line 172**

Old:
```php
throw new RuntimeException('Failed to authenticate with Gmail: ' . $token['error']);
```

New:
```php
throw new GmailAuthenticationException('Failed to authenticate with Gmail: ' . $token['error']);
```

- [ ] **Step 6: Verify no leftover RuntimeException usage**

Run: `grep -rn "RuntimeException" src/Service/GmailService.php`
Expected: 0 matches (the `use` was removed and all 3 throws replaced).

- [ ] **Step 7: Run cs-check**

Run: `composer cs-check src/Service/GmailService.php src/Service/Exception/GmailAuthenticationException.php`
Expected: 0 errors.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Exception/GmailAuthenticationException.php src/Service/GmailService.php
git commit -m "refactor(services): replace RuntimeException with typed GmailAuthenticationException"
```

---

## Task 2: SettingsEncryptionException + reemplazo de throw

**Files:**
- Create: `src/Service/Exception/SettingsEncryptionException.php`
- Modify: `src/Service/Traits/SettingsEncryptionTrait.php` (líneas 10, 121)

- [ ] **Step 1: Create the exception class**

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when settings encryption/decryption fails (e.g., missing salt).
 *
 * Extends RuntimeException to remain compatible with existing
 * `catch (RuntimeException $e)` and `catch (Exception $e)` handlers.
 */
class SettingsEncryptionException extends RuntimeException
{
}
```

Save to `src/Service/Exception/SettingsEncryptionException.php`.

- [ ] **Step 2: Update trait imports**

Open `src/Service/Traits/SettingsEncryptionTrait.php`. Replace line 10:

```php
use RuntimeException;
```

with:

```php
use App\Service\Exception\SettingsEncryptionException;
```

- [ ] **Step 3: Replace throw at line 121**

Old:
```php
throw new RuntimeException(
    'Security.salt is not configured. Please set SECURITY_SALT environment variable.',
);
```

New:
```php
throw new SettingsEncryptionException(
    'Security.salt is not configured. Please set SECURITY_SALT environment variable.',
);
```

Also update the docblock at lines 113-115 `@throws \RuntimeException` → `@throws \App\Service\Exception\SettingsEncryptionException`.

- [ ] **Step 4: Verify**

Run: `grep -rn "RuntimeException" src/Service/Traits/SettingsEncryptionTrait.php`
Expected: 0 matches.

Run global sweep: `grep -rn "throw new RuntimeException\|throw new \\\\RuntimeException" src/`
Expected: 0 matches across all of `src/`.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check src/Service/Traits/SettingsEncryptionTrait.php src/Service/Exception/SettingsEncryptionException.php`
Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Exception/SettingsEncryptionException.php src/Service/Traits/SettingsEncryptionTrait.php
git commit -m "refactor(services): replace RuntimeException with typed SettingsEncryptionException"
```

---

## Task 3: Sub-config DTOs (5 archivos readonly)

**Files:**
- Create: `src/Service/Dto/GmailConfig.php`
- Create: `src/Service/Dto/SmtpConfig.php`
- Create: `src/Service/Dto/N8nConfig.php`
- Create: `src/Service/Dto/WhatsappConfig.php`
- Create: `src/Service/Dto/AppConfig.php`

- [ ] **Step 1: Create GmailConfig**

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Constants\SettingKeys;

/**
 * Gmail OAuth configuration extracted from system_settings.
 *
 * Note: this is the SETTINGS shape (refresh token + client secret JSON string),
 * not the runtime Gmail config (decoded JSON + redirect URI) used by GmailService
 * directly — those are loaded via GmailService::loadConfigFromDatabase().
 */
final readonly class GmailConfig
{
    public function __construct(
        public string $refreshToken,
        public string $clientSecretJson,
        public string $userEmail,
        public string $checkInterval,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        return new self(
            refreshToken: (string)($raw[SettingKeys::GMAIL_REFRESH_TOKEN] ?? ''),
            clientSecretJson: (string)($raw[SettingKeys::GMAIL_CLIENT_SECRET_JSON] ?? ''),
            userEmail: (string)($raw[SettingKeys::GMAIL_USER_EMAIL] ?? ''),
            checkInterval: (string)($raw[SettingKeys::GMAIL_CHECK_INTERVAL] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            SettingKeys::GMAIL_REFRESH_TOKEN => $this->refreshToken,
            SettingKeys::GMAIL_CLIENT_SECRET_JSON => $this->clientSecretJson,
            SettingKeys::GMAIL_USER_EMAIL => $this->userEmail,
            SettingKeys::GMAIL_CHECK_INTERVAL => $this->checkInterval,
        ];
    }
}
```

Save to `src/Service/Dto/GmailConfig.php`.

- [ ] **Step 2: Create SmtpConfig** (placeholder — SMTP not currently used; included for completeness)

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * SMTP configuration placeholder.
 *
 * Helpdesk uses Gmail API for outbound mail today. Kept here for future
 * SMTP fallback / alternate provider scenarios. Always empty in current
 * codebase.
 */
final readonly class SmtpConfig
{
    public function __construct(
        public string $host = '',
        public string $port = '',
        public string $username = '',
        public string $password = '',
        public string $fromAddress = '',
        public string $fromName = '',
        public bool $tls = false,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        return new self();
    }

    public function toArray(): array
    {
        return [];
    }
}
```

Save to `src/Service/Dto/SmtpConfig.php`.

- [ ] **Step 3: Create N8nConfig**

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Constants\SettingKeys;

final readonly class N8nConfig
{
    public function __construct(
        public bool $enabled,
        public string $webhookUrl,
        public string $apiKey,
        public string $sendTagsList,
        public string $timeout,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: ($raw[SettingKeys::N8N_ENABLED] ?? '') === '1',
            webhookUrl: (string)($raw[SettingKeys::N8N_WEBHOOK_URL] ?? ''),
            apiKey: (string)($raw[SettingKeys::N8N_API_KEY] ?? ''),
            sendTagsList: (string)($raw[SettingKeys::N8N_SEND_TAGS_LIST] ?? ''),
            timeout: (string)($raw[SettingKeys::N8N_TIMEOUT] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            SettingKeys::N8N_ENABLED => $this->enabled ? '1' : '0',
            SettingKeys::N8N_WEBHOOK_URL => $this->webhookUrl,
            SettingKeys::N8N_API_KEY => $this->apiKey,
            SettingKeys::N8N_SEND_TAGS_LIST => $this->sendTagsList,
            SettingKeys::N8N_TIMEOUT => $this->timeout,
        ];
    }
}
```

Save to `src/Service/Dto/N8nConfig.php`.

- [ ] **Step 4: Create WhatsappConfig**

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Constants\SettingKeys;

final readonly class WhatsappConfig
{
    public function __construct(
        public bool $enabled,
        public string $apiUrl,
        public string $apiKey,
        public string $instanceName,
        public string $ticketsNumber,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: ($raw[SettingKeys::WHATSAPP_ENABLED] ?? '') === '1',
            apiUrl: (string)($raw[SettingKeys::WHATSAPP_API_URL] ?? ''),
            apiKey: (string)($raw[SettingKeys::WHATSAPP_API_KEY] ?? ''),
            instanceName: (string)($raw[SettingKeys::WHATSAPP_INSTANCE_NAME] ?? ''),
            ticketsNumber: (string)($raw[SettingKeys::WHATSAPP_TICKETS_NUMBER] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            SettingKeys::WHATSAPP_ENABLED => $this->enabled ? '1' : '0',
            SettingKeys::WHATSAPP_API_URL => $this->apiUrl,
            SettingKeys::WHATSAPP_API_KEY => $this->apiKey,
            SettingKeys::WHATSAPP_INSTANCE_NAME => $this->instanceName,
            SettingKeys::WHATSAPP_TICKETS_NUMBER => $this->ticketsNumber,
        ];
    }
}
```

Save to `src/Service/Dto/WhatsappConfig.php`.

- [ ] **Step 5: Create AppConfig**

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Constants\SettingKeys;

final readonly class AppConfig
{
    public function __construct(
        public string $systemTitle,
        public string $webhookGmailImportToken,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        return new self(
            systemTitle: (string)($raw[SettingKeys::SYSTEM_TITLE] ?? ''),
            webhookGmailImportToken: (string)($raw[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            SettingKeys::SYSTEM_TITLE => $this->systemTitle,
            SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN => $this->webhookGmailImportToken,
        ];
    }
}
```

Save to `src/Service/Dto/AppConfig.php`.

- [ ] **Step 6: Run cs-check on the 5 new files**

Run: `composer cs-check src/Service/Dto/`
Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Dto/
git commit -m "feat(dto): add SystemConfig sub-config DTOs (Gmail, Smtp, N8n, Whatsapp, App)"
```

---

## Task 4: SystemConfig root VO

**Files:**
- Create: `src/Service/Dto/SystemConfig.php`

- [ ] **Step 1: Create SystemConfig**

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * System-wide configuration value object.
 *
 * Composes per-domain sub-configs built from the `system_settings` snapshot.
 * Constructed once per request in TicketServiceInitializerTrait::initializeServices
 * and passed by-reference to all ticket services.
 *
 * Use SystemConfig::empty() in tests or when no settings cache is available.
 */
final readonly class SystemConfig
{
    public function __construct(
        public GmailConfig $gmail,
        public SmtpConfig $smtp,
        public N8nConfig $n8n,
        public WhatsappConfig $whatsapp,
        public AppConfig $app,
    ) {
    }

    /**
     * Build a SystemConfig from a system_settings snapshot array.
     *
     * Tolerates missing keys: each sub-config sets safe defaults when
     * a key is absent.
     *
     * @param array<string, mixed>|null $raw Raw settings (key => value)
     */
    public static function fromSettingsArray(?array $raw): self
    {
        $raw ??= [];

        return new self(
            gmail: GmailConfig::fromArray($raw),
            smtp: SmtpConfig::fromArray($raw),
            n8n: N8nConfig::fromArray($raw),
            whatsapp: WhatsappConfig::fromArray($raw),
            app: AppConfig::fromArray($raw),
        );
    }

    /**
     * Empty instance with safe defaults — useful for tests and CLI bootstrap.
     */
    public static function empty(): self
    {
        return self::fromSettingsArray([]);
    }

    /**
     * Flat array of all setting key => value pairs.
     *
     * Used to bridge the VO into legacy code paths that still consume
     * the raw settings array (ConfigResolutionTrait, EmailTemplateRenderer).
     */
    public function toSettingsArray(): array
    {
        return array_merge(
            $this->gmail->toArray(),
            $this->smtp->toArray(),
            $this->n8n->toArray(),
            $this->whatsapp->toArray(),
            $this->app->toArray(),
        );
    }
}
```

Save to `src/Service/Dto/SystemConfig.php`.

- [ ] **Step 2: Run cs-check**

Run: `composer cs-check src/Service/Dto/SystemConfig.php`
Expected: 0 errors.

- [ ] **Step 3: Commit**

```bash
git add src/Service/Dto/SystemConfig.php
git commit -m "feat(dto): add SystemConfig root value object with fromSettingsArray/toSettingsArray"
```

---

## Task 5: Migrate ticket services to SystemConfig

**Goal:** Change constructor first parameter from `?array $systemConfig` to `?SystemConfig $config` in the 5 ticket services. Default to `SystemConfig::empty()` when null. Pass-through to peer services.

**Files:**
- Modify: `src/Service/TicketIngestionService.php`
- Modify: `src/Service/TicketPipelineService.php`
- Modify: `src/Service/TicketCommentService.php`
- Modify: `src/Service/TicketAttachmentService.php`
- Modify: `src/Service/TicketNotificationService.php`

- [ ] **Step 1: Update TicketIngestionService constructor**

Open `src/Service/TicketIngestionService.php`.

Add `use` (after existing namespace imports, alphabetical):
```php
use App\Service\Dto\SystemConfig;
```

Replace the constructor block (lines 23-40) with:
```php
private TicketAttachmentService $attachments;
private TicketNotificationService $notifications;
private SystemConfig $config;

/**
 * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
 * @param \App\Service\TicketAttachmentService|null $attachments Optional injected attachment service
 * @param \App\Service\TicketNotificationService|null $notifications Optional injected notification service
 */
public function __construct(
    ?SystemConfig $config = null,
    ?TicketAttachmentService $attachments = null,
    ?TicketNotificationService $notifications = null,
) {
    $this->config = $config ?? SystemConfig::empty();
    $this->attachments = $attachments ?? new TicketAttachmentService();
    $this->notifications = $notifications ?? new TicketNotificationService($this->config);
}
```

If `$this->systemConfig` is referenced elsewhere in the file (grep `$this->systemConfig` inside this file), rename those references to `$this->config` or `$this->config->toSettingsArray()` if a flat array is needed.

- [ ] **Step 2: Update TicketPipelineService constructor**

Open `src/Service/TicketPipelineService.php`.

Add import:
```php
use App\Service\Dto\SystemConfig;
```

Replace the constructor block (lines 26-51) with:
```php
private TicketCommentService $comments;
private TicketAttachmentService $attachments;
private TicketNotificationService $notifications;
private AuthorizationService $authService;
private SystemConfig $config;

/**
 * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
 * @param \App\Service\TicketCommentService|null $comments Optional injected comment service
 * @param \App\Service\TicketAttachmentService|null $attachments Optional injected attachment service
 * @param \App\Service\TicketNotificationService|null $notifications Optional injected notification service
 * @param \App\Service\AuthorizationService|null $authService Optional injected authorization service
 */
public function __construct(
    ?SystemConfig $config = null,
    ?TicketCommentService $comments = null,
    ?TicketAttachmentService $attachments = null,
    ?TicketNotificationService $notifications = null,
    ?AuthorizationService $authService = null,
) {
    $this->config = $config ?? SystemConfig::empty();
    $this->comments = $comments ?? new TicketCommentService($this->config);
    $this->attachments = $attachments ?? new TicketAttachmentService();
    $this->notifications = $notifications ?? new TicketNotificationService($this->config);
    $this->authService = $authService ?? new AuthorizationService();
}
```

Rename `$this->systemConfig` references inside the file to `$this->config`.

- [ ] **Step 3: Update TicketCommentService constructor**

Open `src/Service/TicketCommentService.php`.

Add import:
```php
use App\Service\Dto\SystemConfig;
```

Find the constructor (likely around lines 20-40, signature `public function __construct(?array $systemConfig = null, ...)`) and rewrite to accept `?SystemConfig $config = null` first. Inside, set `$this->config = $config ?? SystemConfig::empty();`. Pass `$this->config` to any peer service constructed inside (e.g., `new TicketAttachmentService(...)`, `new TicketNotificationService($this->config)`).

If the property was `private ?array $systemConfig`, change to `private SystemConfig $config`.

Rename internal references.

- [ ] **Step 4: Update TicketAttachmentService constructor (if it accepts config)**

Open `src/Service/TicketAttachmentService.php`. Check the constructor signature.

If it accepts `?array $systemConfig = null`, apply the same pattern as Step 3.

If it does NOT accept any config (read-only file/upload helper), skip this service.

- [ ] **Step 5: Update TicketNotificationService constructor**

Open `src/Service/TicketNotificationService.php`.

Add import:
```php
use App\Service\Dto\SystemConfig;
```

Replace the constructor block (lines 16-37) with:
```php
private EmailService $emailService;
private WhatsappService $whatsappService;
private ?N8nService $n8nService;
private SystemConfig $config;

/**
 * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
 * @param \App\Service\EmailService|null $emailService Optional injected email service
 * @param \App\Service\WhatsappService|null $whatsappService Optional injected WhatsApp service
 * @param \App\Service\N8nService|null $n8nService Optional injected n8n service (lazy default)
 */
public function __construct(
    ?SystemConfig $config = null,
    ?EmailService $emailService = null,
    ?WhatsappService $whatsappService = null,
    ?N8nService $n8nService = null,
) {
    $this->config = $config ?? SystemConfig::empty();
    $this->emailService = $emailService ?? new EmailService($this->config);
    $this->whatsappService = $whatsappService ?? new WhatsappService($this->config);
    $this->n8nService = $n8nService;
}
```

Update the lazy `getN8nService()` method (line ~44):
```php
public function getN8nService(): N8nService
{
    if ($this->n8nService === null) {
        $this->n8nService = new N8nService($this->config);
    }

    return $this->n8nService;
}
```

Rename `$this->systemConfig` references.

- [ ] **Step 6: Run cs-check on all 5 files**

Run: `composer cs-check src/Service/TicketIngestionService.php src/Service/TicketPipelineService.php src/Service/TicketCommentService.php src/Service/TicketAttachmentService.php src/Service/TicketNotificationService.php`
Expected: 0 errors.

- [ ] **Step 7: Verify no callers broken**

Run: `grep -rn "new TicketIngestionService\|new TicketPipelineService\|new TicketCommentService\|new TicketAttachmentService\|new TicketNotificationService" src/`

For each result, verify the call passes either: nothing, an explicit `?SystemConfig`, or `null`. Calls that pass `array` will fail at runtime — they need updating. The most likely callers:
- `TicketServiceInitializerTrait` (handled in Task 7)
- Other ticket services constructing each other (already handled in this task)
- `AppController` (verify; if it constructs any of these, it must pass `SystemConfig` after Task 7)
- CLI commands (verify)

If any caller still passes an array, leave it — Task 7 (initializer) and the integration service migration in Task 6 are constructed to convert at the boundary.

Note: callers passing `null` or nothing keep working via the default.

- [ ] **Step 8: Commit**

```bash
git add src/Service/TicketIngestionService.php src/Service/TicketPipelineService.php src/Service/TicketCommentService.php src/Service/TicketAttachmentService.php src/Service/TicketNotificationService.php
git commit -m "refactor(services): adopt SystemConfig DTO in 5 ticket services"
```

---

## Task 6: Migrate integration services (Email/Whatsapp/N8n) to SystemConfig

**Goal:** Email/Whatsapp/N8n accept `?SystemConfig $config` publicly but internally convert via `$config?->toSettingsArray()` to keep `ConfigResolutionTrait` working unchanged. GmailService is **NOT** migrated (it has its own config shape).

**Files:**
- Modify: `src/Service/EmailService.php`
- Modify: `src/Service/WhatsappService.php`
- Modify: `src/Service/N8nService.php`

- [ ] **Step 1: Update EmailService constructor**

Open `src/Service/EmailService.php`.

Add import:
```php
use App\Service\Dto\SystemConfig;
```

Replace constructor (lines 46-51):
```php
public function __construct(?SystemConfig $config = null)
{
    $systemConfig = $config?->toSettingsArray();
    $this->renderer = new NotificationRenderer();
    $this->templateRenderer = new EmailTemplateRenderer($systemConfig);
    $this->systemConfig = $systemConfig;
}
```

The internal `private ?array $systemConfig` field stays as-is. The `ConfigResolutionTrait` continues to work unchanged.

- [ ] **Step 2: Update WhatsappService constructor**

Open `src/Service/WhatsappService.php`.

Add import:
```php
use App\Service\Dto\SystemConfig;
```

Replace constructor (lines 43-47):
```php
public function __construct(?SystemConfig $config = null)
{
    $this->renderer = new NotificationRenderer();
    $this->systemConfig = $config?->toSettingsArray();
}
```

- [ ] **Step 3: Update N8nService constructor**

Open `src/Service/N8nService.php`.

Add import:
```php
use App\Service\Dto\SystemConfig;
```

Replace constructor (lines 32-42):
```php
public function __construct(?SystemConfig $config = null)
{
    $this->systemConfig = $config?->toSettingsArray();
    $this->config = $this->resolveSettingsBatch(SettingKeys::N8N_ENABLED, 'n8n_settings', [
        SettingKeys::N8N_ENABLED,
        SettingKeys::N8N_WEBHOOK_URL,
        SettingKeys::N8N_API_KEY,
        SettingKeys::N8N_SEND_TAGS_LIST,
        SettingKeys::N8N_TIMEOUT,
    ]);
}
```

- [ ] **Step 4: Verify EmailTemplateRenderer compat**

The Task 5/6 changes pass `$systemConfig` (still as array, via `toSettingsArray()`) to `EmailTemplateRenderer`. Verify its constructor still accepts `?array`:

Run: `grep -n "function __construct" src/Service/EmailTemplateRenderer.php`

If it accepts `?array`, no change needed. If for some reason it has been migrated to VO, adjust the call site in EmailService.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check src/Service/EmailService.php src/Service/WhatsappService.php src/Service/N8nService.php`
Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/Service/EmailService.php src/Service/WhatsappService.php src/Service/N8nService.php
git commit -m "refactor(services): integration services accept SystemConfig (internal toSettingsArray bridge)"
```

---

## Task 7: Update TicketServiceInitializerTrait to build SystemConfig

**Files:**
- Modify: `src/Controller/Trait/TicketServiceInitializerTrait.php`

- [ ] **Step 1: Update imports**

Open `src/Controller/Trait/TicketServiceInitializerTrait.php`.

Add (after existing imports, alphabetical):
```php
use App\Service\Dto\SystemConfig;
```

- [ ] **Step 2: Replace `initializeServices`**

Old (lines 20-31):
```php
protected function initializeServices(array $serviceMap): void
{
    $systemConfig = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);

    foreach ($serviceMap as $propertyName => $serviceClass) {
        $this->{$propertyName} = new $serviceClass($systemConfig);
    }
}
```

New:
```php
protected function initializeServices(array $serviceMap): void
{
    $raw = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
    $config = SystemConfig::fromSettingsArray(is_array($raw) ? $raw : null);

    foreach ($serviceMap as $propertyName => $serviceClass) {
        $this->{$propertyName} = new $serviceClass($config);
    }
}
```

- [ ] **Step 3: Check AppController for direct service construction**

Run: `grep -n "new TicketIngestionService\|new TicketPipelineService\|new TicketCommentService\|new TicketAttachmentService\|new TicketNotificationService\|new EmailService\|new WhatsappService\|new N8nService" src/Controller/AppController.php`

If `AppController` constructs any of these services directly with the cache array, update those constructions to use `SystemConfig::fromSettingsArray($raw)` first. Show the same pattern as in `initializeServices`.

If none, skip.

- [ ] **Step 4: Run cs-check**

Run: `composer cs-check src/Controller/Trait/TicketServiceInitializerTrait.php`
Expected: 0 errors.

- [ ] **Step 5: Smoke check (manual)**

Browse to the home page (`/`) in a running dev environment.
Expected: ticket list loads, sidebar counts show, no PHP errors in `logs/error.log`.

If there's no running environment, skip this step and rely on the smoke at the end of the plan.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Trait/TicketServiceInitializerTrait.php
# also add AppController.php if modified in Step 3
git commit -m "refactor(controller): build SystemConfig in TicketServiceInitializerTrait"
```

---

## Task 8: DomainEvent base + 3 events

**Files:**
- Create: `src/Domain/Event/DomainEvent.php`
- Create: `src/Domain/Event/TicketCreated.php`
- Create: `src/Domain/Event/TicketAssigned.php`
- Create: `src/Domain/Event/TicketStatusChanged.php`

- [ ] **Step 1: Create DomainEvent base**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Event;

use Cake\Event\Event;
use DateTimeImmutable;

/**
 * Abstract base for domain events.
 *
 * Extends Cake\Event\Event so events can be dispatched through
 * EventManager::instance() and handled by EventListenerInterface
 * implementations registered in Application::bootstrap.
 *
 * @template TSubject of object|null
 * @extends \Cake\Event\Event<TSubject>
 */
abstract class DomainEvent extends Event
{
    public readonly DateTimeImmutable $occurredAt;

    /**
     * @param string $name Event name (e.g. 'Ticket.created')
     * @param object|null $subject Optional subject (entity)
     * @param array $data Optional payload
     */
    public function __construct(string $name, ?object $subject = null, array $data = [])
    {
        parent::__construct($name, $subject, $data);
        $this->occurredAt = new DateTimeImmutable();
    }
}
```

Save to `src/Domain/Event/DomainEvent.php`.

- [ ] **Step 2: Create TicketCreated**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a ticket is persisted from any source (email, manual).
 */
final class TicketCreated extends DomainEvent
{
    public const NAME = 'Ticket.created';

    public function __construct(
        public readonly int $ticketId,
        public readonly int $requesterId,
        public readonly string $source,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'requesterId' => $requesterId,
            'source' => $source,
        ]);
    }
}
```

Save to `src/Domain/Event/TicketCreated.php`.

- [ ] **Step 3: Create TicketAssigned**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a ticket's assignee_id is changed (including clearing).
 */
final class TicketAssigned extends DomainEvent
{
    public const NAME = 'Ticket.assigned';

    public function __construct(
        public readonly int $ticketId,
        public readonly ?int $assigneeId,
        public readonly ?int $previousAssigneeId,
        public readonly ?int $actorId,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'assigneeId' => $assigneeId,
            'previousAssigneeId' => $previousAssigneeId,
            'actorId' => $actorId,
        ]);
    }
}
```

Save to `src/Domain/Event/TicketAssigned.php`.

- [ ] **Step 4: Create TicketStatusChanged**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a ticket's status transition succeeds.
 */
final class TicketStatusChanged extends DomainEvent
{
    public const NAME = 'Ticket.statusChanged';

    public function __construct(
        public readonly int $ticketId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'actorId' => $actorId,
        ]);
    }
}
```

Save to `src/Domain/Event/TicketStatusChanged.php`.

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check src/Domain/`
Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/
git commit -m "feat(domain): add Ticket domain events (Created/Assigned/StatusChanged)"
```

---

## Task 9: TicketNotificationListener

**Files:**
- Create: `src/Listener/TicketNotificationListener.php`

- [ ] **Step 1: Create the listener**

```php
<?php
declare(strict_types=1);

namespace App\Listener;

use App\Domain\Event\TicketAssigned;
use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Service\TicketNotificationService;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Bridges domain events to TicketNotificationService.
 *
 * Each handler reloads the ticket fresh from the database (the event payload
 * carries only IDs) and delegates to the appropriate notification dispatch
 * method. Exceptions are caught and logged — they never propagate back to
 * the dispatch site, mirroring the defensive behavior the service had when
 * called directly.
 */
final class TicketNotificationListener implements EventListenerInterface
{
    use LocatorAwareTrait;

    public function __construct(
        private readonly TicketNotificationService $notifications,
    ) {
    }

    public function implementedEvents(): array
    {
        return [
            TicketCreated::NAME => 'onCreated',
            TicketAssigned::NAME => 'onAssigned',
            TicketStatusChanged::NAME => 'onStatusChanged',
        ];
    }

    public function onCreated(TicketCreated $event): void
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters']);
            $this->notifications->dispatchCreationNotifications($ticket);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::onCreated failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onAssigned(TicketAssigned $event): void
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees']);
            $this->notifications->dispatchUpdateNotifications($ticket, 'assignment', [
                'old_assignee_id' => $event->previousAssigneeId,
                'new_assignee_id' => $event->assigneeId,
            ]);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::onAssigned failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onStatusChanged(TicketStatusChanged $event): void
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees']);
            $this->notifications->dispatchUpdateNotifications($ticket, 'status_change', [
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ]);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::onStatusChanged failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

Save to `src/Listener/TicketNotificationListener.php`.

- [ ] **Step 2: Verify dispatch methods exist on TicketNotificationService**

Run: `grep -n "function dispatchCreationNotifications\|function dispatchUpdateNotifications" src/Service/TicketNotificationService.php`

Expected: both methods exist. If `dispatchUpdateNotifications` does NOT accept the `'assignment'` type, inspect its current signature and adjust the listener's call (or add a new dispatch method). The grep output of TicketNotificationService at lines 171/179/185 already shows it routes by string type — verify `'assignment'` is a valid branch or add it.

If `'assignment'` is not handled, skip the assignment dispatch in the listener for now (early return) and document it in the closing commit message. The audit's minimum scope (`TicketAssigned` event existence) is satisfied even if the notification side is a follow-up.

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check src/Listener/TicketNotificationListener.php`
Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/Listener/TicketNotificationListener.php
git commit -m "feat(listener): add TicketNotificationListener bridging domain events to notifications"
```

---

## Task 10: Register listener in Application::bootstrap

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 1: Add imports**

Open `src/Application.php`. After existing `use` statements (alphabetical), add:

```php
use App\Constants\CacheConstants;
use App\Listener\TicketNotificationListener;
use App\Service\Dto\SystemConfig;
use App\Service\TicketNotificationService;
use Cake\Cache\Cache;
use Cake\Event\EventManager;
```

- [ ] **Step 2: Update bootstrap method**

Replace the body of `bootstrap()` (lines 52-61):

```php
public function bootstrap(): void
{
    parent::bootstrap();

    if (PHP_SAPI !== 'cli') {
        FactoryLocator::add('Table', (new TableLocator())->allowFallbackClass(false));
    }

    $this->registerDomainEventListeners();
}

/**
 * Register listeners for domain events on the global EventManager.
 */
private function registerDomainEventListeners(): void
{
    $raw = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
    $config = SystemConfig::fromSettingsArray(is_array($raw) ? $raw : null);

    $notifications = new TicketNotificationService($config);
    EventManager::instance()->on(new TicketNotificationListener($notifications));
}
```

- [ ] **Step 3: Verify EventManager registration works**

Run: `composer cs-check src/Application.php`
Expected: 0 errors.

If a dev environment is available:
1. Start the server: `bin/cake server`
2. Browse to the home page.
3. Inspect logs: `tail -n 50 logs/error.log` — there should be NO new errors related to `EventManager`, `TicketNotificationListener`, or service construction.

- [ ] **Step 4: Commit**

```bash
git add src/Application.php
git commit -m "feat(app): register TicketNotificationListener on EventManager in bootstrap"
```

---

## Task 11: Wire dispatch in TicketIngestionService + TicketPipelineService

**Files:**
- Modify: `src/Service/TicketIngestionService.php`
- Modify: `src/Service/TicketPipelineService.php`

- [ ] **Step 1: Add EventDispatcher to TicketIngestionService**

Open `src/Service/TicketIngestionService.php`.

Add imports (alphabetical, with existing):
```php
use App\Domain\Event\TicketCreated;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
```

In the constructor, add a 4th optional parameter:
```php
public function __construct(
    ?SystemConfig $config = null,
    ?TicketAttachmentService $attachments = null,
    ?TicketNotificationService $notifications = null,
    ?EventManagerInterface $eventManager = null,
) {
    $this->config = $config ?? SystemConfig::empty();
    $this->attachments = $attachments ?? new TicketAttachmentService();
    $this->notifications = $notifications ?? new TicketNotificationService($this->config);
    $this->eventManager = $eventManager ?? EventManager::instance();
}
```

Add the property:
```php
private EventManagerInterface $eventManager;
```

- [ ] **Step 2: Replace direct notification call with event dispatch**

Find the line at ~line 133 in `TicketIngestionService::createFromEmail`:

Old:
```php
$this->notifications->dispatchCreationNotifications($ticket);
```

New:
```php
$this->eventManager->dispatch(new TicketCreated(
    ticketId: $ticket->id,
    requesterId: (int)$ticket->requester_id,
    source: 'email',
));
```

- [ ] **Step 3: Add EventDispatcher to TicketPipelineService**

Open `src/Service/TicketPipelineService.php`.

Add imports (alphabetical):
```php
use App\Domain\Event\TicketAssigned;
use App\Domain\Event\TicketStatusChanged;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
```

In the constructor, add 6th optional parameter:
```php
public function __construct(
    ?SystemConfig $config = null,
    ?TicketCommentService $comments = null,
    ?TicketAttachmentService $attachments = null,
    ?TicketNotificationService $notifications = null,
    ?AuthorizationService $authService = null,
    ?EventManagerInterface $eventManager = null,
) {
    $this->config = $config ?? SystemConfig::empty();
    $this->comments = $comments ?? new TicketCommentService($this->config);
    $this->attachments = $attachments ?? new TicketAttachmentService();
    $this->notifications = $notifications ?? new TicketNotificationService($this->config);
    $this->authService = $authService ?? new AuthorizationService();
    $this->eventManager = $eventManager ?? EventManager::instance();
}
```

Add the property:
```php
private EventManagerInterface $eventManager;
```

- [ ] **Step 4: Dispatch TicketAssigned in `assign()`**

In `TicketPipelineService::assign()`, after the `$this->logHistory(...)` call and before `return true;` (around line 268), add:

```php
$this->eventManager->dispatch(new TicketAssigned(
    ticketId: (int)$entity->id,
    assigneeId: $normalizedAssigneeId,
    previousAssigneeId: $oldAssigneeId,
    actorId: $userId,
));
```

- [ ] **Step 5: Dispatch TicketStatusChanged in `changeStatus()`**

In `TicketPipelineService::changeStatus()`, replace the existing notification call (around line 186):

Old:
```php
if ($sendNotifications) {
    $this->notifications->sendStatusChangeEmail($entity, $oldStatus, $newStatus);
}
```

New:
```php
if ($sendNotifications) {
    $this->eventManager->dispatch(new TicketStatusChanged(
        ticketId: (int)$entity->id,
        oldStatus: $oldStatus,
        newStatus: $newStatus,
        actorId: $userId,
    ));
}
```

Note: this REPLACES the direct `sendStatusChangeEmail` call. The listener now triggers the same notification path via `dispatchUpdateNotifications($ticket, 'status_change', ...)`. Verify `dispatchUpdateNotifications` covers the same surface (email + WhatsApp + n8n). If `sendStatusChangeEmail` did MORE than `dispatchUpdateNotifications` for `status_change`, keep the direct call AND dispatch the event (do not double-send email — pick one path).

If unclear from code reading, **keep both calls for now** and let the smoke manual at the end identify duplicates. Fix in a follow-up commit if needed.

- [ ] **Step 6: handleResponse — DO NOT add status event**

In `TicketPipelineService::handleResponse()`, the `changeStatus()` call (line 121) is invoked with `$sendNotifications = false`. The existing `sendResponseNotifications` call (line 125) is the canonical notification for the response flow — leave it alone. The TicketStatusChanged event is NOT dispatched from this path to avoid double notifications.

If `changeStatus` was modified in Step 5 to dispatch the event regardless of `$sendNotifications`, wrap the dispatch inside the `if ($sendNotifications)` block as shown above.

- [ ] **Step 7: Run cs-check**

Run: `composer cs-check src/Service/TicketIngestionService.php src/Service/TicketPipelineService.php`
Expected: 0 errors.

- [ ] **Step 8: Commit**

```bash
git add src/Service/TicketIngestionService.php src/Service/TicketPipelineService.php
git commit -m "refactor(services): dispatch domain events instead of direct notification calls"
```

---

## Task 12: PHPUnit bootstrap

**Files:**
- Modify: `composer.json`
- Modify: `.gitignore`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Add PHPUnit to composer.json**

Open `composer.json`. In `require-dev` add (or merge):
```json
"phpunit/phpunit": "^10.5"
```

In `scripts` add (or merge):
```json
"test": "phpunit",
"test-coverage": "phpunit --coverage-html coverage"
```

- [ ] **Step 2: Run composer install**

Run: `composer install`
Expected: PHPUnit installed under `vendor/bin/phpunit`.

Verify: `vendor/bin/phpunit --version`
Expected: PHPUnit 10.x.

- [ ] **Step 3: Update .gitignore**

Open `.gitignore`. Add at the end:
```
.phpunit.cache/
coverage/
```

- [ ] **Step 4: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/TestCase</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

Save to `phpunit.xml.dist` (project root).

- [ ] **Step 5: Create tests/bootstrap.php**

```php
<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads the autoloader and minimal Cake bootstrap (without DB connection).
 * Tests must remain pure-unit — no DB queries, no fixtures.
 */

use Cake\Core\Configure;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
if (!defined('APP')) {
    define('APP', ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG')) {
    define('CONFIG', ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
}
if (!defined('TESTS')) {
    define('TESTS', ROOT . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR);
}
if (!defined('TMP')) {
    define('TMP', ROOT . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR);
}
if (!defined('LOGS')) {
    define('LOGS', ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);
}
if (!defined('CACHE')) {
    define('CACHE', TMP . 'cache' . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'App',
    'paths' => [
        'templates' => [ROOT . DS . 'templates' . DS],
    ],
]);
```

Save to `tests/bootstrap.php`.

- [ ] **Step 6: Verify PHPUnit can run (with zero tests)**

Run: `vendor/bin/phpunit --testdox`
Expected: "No tests executed!" or similar (PHPUnit runs cleanly with 0 tests since no test files exist yet).

- [ ] **Step 7: Run composer cs-check**

Run: `composer cs-check tests/bootstrap.php`
Expected: 0 errors.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist tests/bootstrap.php .gitignore
git commit -m "chore(test): bootstrap PHPUnit 10 + composer test scripts"
```

---

## Task 13: Ticket entity tests

**Files:**
- Create: `tests/TestCase/Model/Entity/TicketTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\TicketConstants;
use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    private function makeTicket(array $props = []): Ticket
    {
        $defaults = [
            'id' => 1,
            'status' => 'nuevo',
            'priority' => 'media',
            'requester_id' => 10,
            'assignee_id' => null,
            'gmail_message_id' => null,
        ];

        return new Ticket(array_merge($defaults, $props), ['markNew' => false, 'markClean' => true]);
    }

    private function makeUser(array $props = []): User
    {
        $defaults = [
            'id' => 99,
            'role' => 'agent',
            'is_active' => true,
        ];

        return new User(array_merge($defaults, $props), ['markNew' => false, 'markClean' => true]);
    }

    // --- predicates -----------------------------------------------------

    public function testIsNew(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'nuevo'])->isNew());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isNew());
    }

    public function testIsOpen(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'abierto'])->isOpen());
        self::assertFalse($this->makeTicket(['status' => 'nuevo'])->isOpen());
    }

    public function testIsPending(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'pendiente'])->isPending());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isPending());
    }

    public function testIsResolved(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'resuelto'])->isResolved());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isResolved());
    }

    public function testIsLocked(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'resuelto'])->isLocked());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isLocked());
        self::assertFalse($this->makeTicket(['status' => 'nuevo'])->isLocked());
    }

    public function testHasAssignee(): void
    {
        self::assertFalse($this->makeTicket(['assignee_id' => null])->hasAssignee());
        self::assertTrue($this->makeTicket(['assignee_id' => 5])->hasAssignee());
    }

    public function testBelongsTo(): void
    {
        $ticket = $this->makeTicket(['requester_id' => 10]);
        self::assertTrue($ticket->belongsTo(10));
        self::assertFalse($ticket->belongsTo(11));
    }

    public function testIsAssignedTo(): void
    {
        $ticket = $this->makeTicket(['assignee_id' => 5]);
        self::assertTrue($ticket->isAssignedTo(5));
        self::assertFalse($ticket->isAssignedTo(6));

        $unassigned = $this->makeTicket(['assignee_id' => null]);
        self::assertFalse($unassigned->isAssignedTo(5));
    }

    public function testWasCreatedFromEmail(): void
    {
        self::assertTrue($this->makeTicket(['gmail_message_id' => 'abc123'])->wasCreatedFromEmail());
        self::assertFalse($this->makeTicket(['gmail_message_id' => null])->wasCreatedFromEmail());
        self::assertFalse($this->makeTicket(['gmail_message_id' => ''])->wasCreatedFromEmail());
    }

    // --- transitions ----------------------------------------------------

    public function testCanTransitionAllowed(): void
    {
        foreach (Ticket::TRANSITIONS as $from => $allowedTos) {
            $ticket = $this->makeTicket(['status' => $from]);
            foreach ($allowedTos as $to) {
                self::assertTrue(
                    $ticket->canTransitionTo($to),
                    "Expected {$from} -> {$to} to be allowed",
                );
            }
        }
    }

    public function testCanTransitionForbidden(): void
    {
        $allStatuses = TicketConstants::STATUSES;
        foreach (Ticket::TRANSITIONS as $from => $allowedTos) {
            $forbidden = array_diff($allStatuses, $allowedTos, [$from]);
            $ticket = $this->makeTicket(['status' => $from]);
            foreach ($forbidden as $to) {
                self::assertFalse(
                    $ticket->canTransitionTo($to),
                    "Expected {$from} -> {$to} to be forbidden",
                );
            }
        }
    }

    // --- assignability --------------------------------------------------

    public function testCanBeAssignedToActiveStaff(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'agent', 'is_active' => true]);
        self::assertTrue($ticket->canBeAssignedTo($user));
    }

    public function testCannotBeAssignedToInactiveUser(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'agent', 'is_active' => false]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testCannotBeAssignedToNonStaff(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'user', 'is_active' => true]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testCannotAssignWhenLocked(): void
    {
        $ticket = $this->makeTicket(['status' => 'resuelto']);
        $user = $this->makeUser(['role' => 'agent', 'is_active' => true]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }
}
```

Save to `tests/TestCase/Model/Entity/TicketTest.php`.

**Note:** if the actual `Ticket::canBeAssignedTo` or `User::isStaff` signature differs (e.g., requires more fields like `must_change_password`), inspect those methods and adjust the `makeUser` helper to pass the required fields. The expected behavior is the same.

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit tests/TestCase/Model/Entity/TicketTest.php --testdox`
Expected: all 14 tests pass.

If any test fails, inspect the entity's actual implementation and adjust the test (NOT the entity — the entity is the source of truth at this point).

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check tests/TestCase/Model/Entity/TicketTest.php`
Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add tests/TestCase/Model/Entity/TicketTest.php
git commit -m "test(unit): add Ticket entity tests (predicates, transitions, assignability)"
```

---

## Task 14: SystemConfig + sub-config tests

**Files:**
- Create: `tests/TestCase/Service/Dto/SystemConfigTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Dto;

use App\Constants\SettingKeys;
use App\Service\Dto\AppConfig;
use App\Service\Dto\GmailConfig;
use App\Service\Dto\N8nConfig;
use App\Service\Dto\SystemConfig;
use App\Service\Dto\WhatsappConfig;
use PHPUnit\Framework\TestCase;

final class SystemConfigTest extends TestCase
{
    private function fullSettingsArray(): array
    {
        return [
            SettingKeys::SYSTEM_TITLE => 'My Helpdesk',
            SettingKeys::GMAIL_REFRESH_TOKEN => 'rt-123',
            SettingKeys::GMAIL_CLIENT_SECRET_JSON => '{"installed":{}}',
            SettingKeys::GMAIL_USER_EMAIL => 'mail@example.com',
            SettingKeys::GMAIL_CHECK_INTERVAL => '60',
            SettingKeys::WHATSAPP_ENABLED => '1',
            SettingKeys::WHATSAPP_API_URL => 'https://wa.example.com',
            SettingKeys::WHATSAPP_API_KEY => 'wa-key',
            SettingKeys::WHATSAPP_INSTANCE_NAME => 'inst',
            SettingKeys::WHATSAPP_TICKETS_NUMBER => '+57111',
            SettingKeys::N8N_ENABLED => '1',
            SettingKeys::N8N_WEBHOOK_URL => 'https://n8n.example.com/hook',
            SettingKeys::N8N_API_KEY => 'n8n-key',
            SettingKeys::N8N_SEND_TAGS_LIST => '1',
            SettingKeys::N8N_TIMEOUT => '30',
            SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN => 'tok',
        ];
    }

    public function testFromSettingsArrayWithFullData(): void
    {
        $config = SystemConfig::fromSettingsArray($this->fullSettingsArray());

        self::assertSame('rt-123', $config->gmail->refreshToken);
        self::assertSame('mail@example.com', $config->gmail->userEmail);
        self::assertTrue($config->whatsapp->enabled);
        self::assertSame('https://wa.example.com', $config->whatsapp->apiUrl);
        self::assertTrue($config->n8n->enabled);
        self::assertSame('https://n8n.example.com/hook', $config->n8n->webhookUrl);
        self::assertSame('My Helpdesk', $config->app->systemTitle);
    }

    public function testFromSettingsArrayWithEmptyArray(): void
    {
        $config = SystemConfig::fromSettingsArray([]);

        self::assertSame('', $config->gmail->refreshToken);
        self::assertFalse($config->whatsapp->enabled);
        self::assertFalse($config->n8n->enabled);
        self::assertSame('', $config->app->systemTitle);
    }

    public function testFromSettingsArrayWithNull(): void
    {
        $config = SystemConfig::fromSettingsArray(null);
        self::assertSame('', $config->gmail->refreshToken);
    }

    public function testEmptyReturnsValidInstance(): void
    {
        $config = SystemConfig::empty();
        self::assertInstanceOf(GmailConfig::class, $config->gmail);
        self::assertInstanceOf(N8nConfig::class, $config->n8n);
        self::assertInstanceOf(WhatsappConfig::class, $config->whatsapp);
        self::assertInstanceOf(AppConfig::class, $config->app);
    }

    public function testToSettingsArrayRoundTrip(): void
    {
        $original = $this->fullSettingsArray();
        $config = SystemConfig::fromSettingsArray($original);
        $roundTripped = $config->toSettingsArray();

        // Every key in the original should be present and equal after round-trip
        // (boolean coercion: WHATSAPP_ENABLED '1' stays '1' via the bool->'1'/'0' mapping)
        foreach ($original as $key => $value) {
            self::assertArrayHasKey($key, $roundTripped, "Missing key {$key} after round-trip");
            self::assertSame($value, $roundTripped[$key], "Value mismatch for {$key}");
        }
    }

    public function testGmailConfigFromArrayHasDefaults(): void
    {
        $gmail = GmailConfig::fromArray([]);
        self::assertSame('', $gmail->refreshToken);
        self::assertSame('', $gmail->clientSecretJson);
    }

    public function testN8nConfigEnabledParsing(): void
    {
        self::assertTrue(N8nConfig::fromArray([SettingKeys::N8N_ENABLED => '1'])->enabled);
        self::assertFalse(N8nConfig::fromArray([SettingKeys::N8N_ENABLED => '0'])->enabled);
        self::assertFalse(N8nConfig::fromArray([SettingKeys::N8N_ENABLED => ''])->enabled);
        self::assertFalse(N8nConfig::fromArray([])->enabled);
    }

    public function testWhatsappConfigEnabledParsing(): void
    {
        self::assertTrue(WhatsappConfig::fromArray([SettingKeys::WHATSAPP_ENABLED => '1'])->enabled);
        self::assertFalse(WhatsappConfig::fromArray([])->enabled);
    }
}
```

Save to `tests/TestCase/Service/Dto/SystemConfigTest.php`.

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/SystemConfigTest.php --testdox`
Expected: all 8 tests pass.

- [ ] **Step 3: Run cs-check**

Run: `composer cs-check tests/TestCase/Service/Dto/SystemConfigTest.php`
Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add tests/TestCase/Service/Dto/SystemConfigTest.php
git commit -m "test(unit): add SystemConfig + sub-config DTO tests"
```

---

## Task 15: Domain event tests

**Files:**
- Create: `tests/TestCase/Domain/Event/TicketCreatedTest.php`
- Create: `tests/TestCase/Domain/Event/TicketAssignedTest.php`
- Create: `tests/TestCase/Domain/Event/TicketStatusChangedTest.php`

- [ ] **Step 1: Create TicketCreatedTest**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Event;

use App\Domain\Event\TicketCreated;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TicketCreatedTest extends TestCase
{
    public function testConstructorSetsPayload(): void
    {
        $event = new TicketCreated(ticketId: 42, requesterId: 7, source: 'email');

        self::assertSame(42, $event->ticketId);
        self::assertSame(7, $event->requesterId);
        self::assertSame('email', $event->source);
    }

    public function testGetNameReturnsConstant(): void
    {
        $event = new TicketCreated(ticketId: 1, requesterId: 1, source: 'email');
        self::assertSame('Ticket.created', TicketCreated::NAME);
        self::assertSame('Ticket.created', $event->getName());
    }

    public function testOccurredAtIsSet(): void
    {
        $before = new DateTimeImmutable();
        $event = new TicketCreated(ticketId: 1, requesterId: 1, source: 'email');
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }

    public function testDataPayload(): void
    {
        $event = new TicketCreated(ticketId: 42, requesterId: 7, source: 'manual');
        self::assertSame(['ticketId' => 42, 'requesterId' => 7, 'source' => 'manual'], $event->getData());
    }
}
```

Save to `tests/TestCase/Domain/Event/TicketCreatedTest.php`.

- [ ] **Step 2: Create TicketAssignedTest**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Event;

use App\Domain\Event\TicketAssigned;
use PHPUnit\Framework\TestCase;

final class TicketAssignedTest extends TestCase
{
    public function testConstructorSetsPayload(): void
    {
        $event = new TicketAssigned(
            ticketId: 42,
            assigneeId: 5,
            previousAssigneeId: 3,
            actorId: 99,
        );

        self::assertSame(42, $event->ticketId);
        self::assertSame(5, $event->assigneeId);
        self::assertSame(3, $event->previousAssigneeId);
        self::assertSame(99, $event->actorId);
    }

    public function testNullAssigneeAllowed(): void
    {
        $event = new TicketAssigned(
            ticketId: 1,
            assigneeId: null,
            previousAssigneeId: 5,
            actorId: null,
        );
        self::assertNull($event->assigneeId);
        self::assertNull($event->actorId);
    }

    public function testGetNameReturnsConstant(): void
    {
        self::assertSame('Ticket.assigned', TicketAssigned::NAME);
        $event = new TicketAssigned(ticketId: 1, assigneeId: 1, previousAssigneeId: null, actorId: null);
        self::assertSame('Ticket.assigned', $event->getName());
    }
}
```

Save to `tests/TestCase/Domain/Event/TicketAssignedTest.php`.

- [ ] **Step 3: Create TicketStatusChangedTest**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Event;

use App\Domain\Event\TicketStatusChanged;
use PHPUnit\Framework\TestCase;

final class TicketStatusChangedTest extends TestCase
{
    public function testConstructorSetsPayload(): void
    {
        $event = new TicketStatusChanged(
            ticketId: 42,
            oldStatus: 'abierto',
            newStatus: 'resuelto',
            actorId: 99,
        );

        self::assertSame(42, $event->ticketId);
        self::assertSame('abierto', $event->oldStatus);
        self::assertSame('resuelto', $event->newStatus);
        self::assertSame(99, $event->actorId);
    }

    public function testNullActorAllowed(): void
    {
        $event = new TicketStatusChanged(
            ticketId: 1,
            oldStatus: 'nuevo',
            newStatus: 'abierto',
            actorId: null,
        );
        self::assertNull($event->actorId);
    }

    public function testGetNameReturnsConstant(): void
    {
        self::assertSame('Ticket.statusChanged', TicketStatusChanged::NAME);
        $event = new TicketStatusChanged(ticketId: 1, oldStatus: 'a', newStatus: 'b', actorId: null);
        self::assertSame('Ticket.statusChanged', $event->getName());
    }
}
```

Save to `tests/TestCase/Domain/Event/TicketStatusChangedTest.php`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/TestCase/Domain --testdox`
Expected: all 10 tests pass (4 + 3 + 3).

- [ ] **Step 5: Run cs-check**

Run: `composer cs-check tests/TestCase/Domain/`
Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add tests/TestCase/Domain/
git commit -m "test(unit): add domain event tests (Created/Assigned/StatusChanged)"
```

---

## Task 16: TicketNotificationListener tests

**Files:**
- Create: `tests/TestCase/Listener/TicketNotificationListenerTest.php`

**Note:** this test mocks `TicketNotificationService` but cannot stub `fetchTable('Tickets')` without DB. The test focuses on the event-routing surface (`implementedEvents`) and exception swallowing. The dispatch-method-was-called assertions require a live DB and are deferred to integration tests (out of scope).

- [ ] **Step 1: Write the test file**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Listener;

use App\Domain\Event\TicketAssigned;
use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Listener\TicketNotificationListener;
use App\Service\TicketNotificationService;
use PHPUnit\Framework\TestCase;

final class TicketNotificationListenerTest extends TestCase
{
    public function testImplementedEventsRoutesAllThree(): void
    {
        $service = $this->createMock(TicketNotificationService::class);
        $listener = new TicketNotificationListener($service);

        $events = $listener->implementedEvents();

        self::assertArrayHasKey(TicketCreated::NAME, $events);
        self::assertArrayHasKey(TicketAssigned::NAME, $events);
        self::assertArrayHasKey(TicketStatusChanged::NAME, $events);
        self::assertSame('onCreated', $events[TicketCreated::NAME]);
        self::assertSame('onAssigned', $events[TicketAssigned::NAME]);
        self::assertSame('onStatusChanged', $events[TicketStatusChanged::NAME]);
    }

    public function testOnCreatedSwallowsExceptions(): void
    {
        $service = $this->createMock(TicketNotificationService::class);
        $listener = new TicketNotificationListener($service);

        // The fetchTable call will fail without a DB connection, but the listener
        // catches Throwable and logs — so this should NOT throw.
        $event = new TicketCreated(ticketId: 999999, requesterId: 1, source: 'email');

        $listener->onCreated($event);

        // If we reach here, the exception was swallowed correctly.
        self::assertTrue(true);
    }

    public function testOnAssignedSwallowsExceptions(): void
    {
        $service = $this->createMock(TicketNotificationService::class);
        $listener = new TicketNotificationListener($service);

        $event = new TicketAssigned(
            ticketId: 999999,
            assigneeId: 1,
            previousAssigneeId: null,
            actorId: 1,
        );

        $listener->onAssigned($event);
        self::assertTrue(true);
    }

    public function testOnStatusChangedSwallowsExceptions(): void
    {
        $service = $this->createMock(TicketNotificationService::class);
        $listener = new TicketNotificationListener($service);

        $event = new TicketStatusChanged(
            ticketId: 999999,
            oldStatus: 'abierto',
            newStatus: 'resuelto',
            actorId: 1,
        );

        $listener->onStatusChanged($event);
        self::assertTrue(true);
    }
}
```

Save to `tests/TestCase/Listener/TicketNotificationListenerTest.php`.

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit tests/TestCase/Listener --testdox`

Expected: 4 tests pass.

If `fetchTable` throws an *uncatchable* error (e.g., a fatal), the swallow tests will fail. In that case, simplify the swallow tests to use `expectNotToPerformAssertions()` and rely only on `testImplementedEventsRoutesAllThree`. Document this as a known limit of pure-unit testing without DB.

- [ ] **Step 3: Run full test suite**

Run: `composer test`

Expected: all tests across `Model`, `Service\Dto`, `Domain\Event`, and `Listener` pass. Total: ~32 tests.

- [ ] **Step 4: Run cs-check**

Run: `composer cs-check tests/`
Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add tests/TestCase/Listener/TicketNotificationListenerTest.php
git commit -m "test(unit): add TicketNotificationListener event-routing tests"
```

---

## Task 17: Smoke manual + close audit + update CLAUDE.md (5.6 + cierre)

**Files:**
- Modify: `docs/audits/2026-05-07-architecture-audit.md` (Anexo 6)
- Modify: `CLAUDE.md` (testing + SystemConfig + domain events)

- [ ] **Step 1: Run smoke manual checklist**

Execute these flows manually in a running environment (`docker compose up -d --build`):

1. **Login admin:** browse to `/users/login`, authenticate. Expected: home loads with ticket list.
2. **Run cs-check:** `composer cs-check`. Expected: 0 errors.
3. **Run tests:** `composer test`. Expected: all pass (~32 tests).
4. **Import 1 email (optional, requires Gmail OAuth configured):** `docker compose exec web bin/cake import_gmail --max 1`. Expected: log shows ticket created + email notification dispatched (verify in `logs/error.log` and `logs/cli-error.log`).
5. **Assign ticket:** open a ticket, change assignee. Expected: success flash, history entry, `EventManager` dispatched `Ticket.assigned` (visible in debug log if enabled).
6. **Change status to resuelto:** open ticket, mark resolved. Expected: success flash, status change recorded, notification email dispatched.
7. **Attempt illegal transition:** if possible via direct POST or test, attempt `nuevo → resuelto` directly. Expected: `InvalidStatusTransitionException` flash error.

Document any deviation in the Anexo 6 (Step 2 below).

- [ ] **Step 2: Append Anexo 6 to audit document**

Open `docs/audits/2026-05-07-architecture-audit.md`. Append at the end:

```markdown

### Anexo 6 — Cierre fase 4 medios (2026-05-08)

Cerrados los 5 medios pendientes:

- **5.1 ✅** Domain events introducidos: `App\Domain\Event\TicketCreated`, `TicketAssigned`, `TicketStatusChanged`. Base abstract `DomainEvent` extiende `Cake\Event\Event`. Listener `App\Listener\TicketNotificationListener` registrado en `Application::bootstrap` traduce eventos a `TicketNotificationService` (recarga la entidad fresca y delega). Sitios de dispatch: `TicketIngestionService::createFromEmail` (Created), `TicketPipelineService::assign` (Assigned), `TicketPipelineService::changeStatus` (StatusChanged). `dispatchUpdateNotifications('comment'|'response')` no migrado — sigue invocándose directo (fuera de alcance).

- **5.2 ✅** PHPUnit 10.5 bootstrapped. `phpunit.xml.dist`, `tests/bootstrap.php`, scripts `composer test` / `composer test-coverage`. Suite unit puro (sin DB) cubre: entidad `Ticket` (predicados, transitions, assignability — 14 tests), VO `SystemConfig` + sub-configs (8 tests), 3 eventos de dominio (10 tests), listener routing (4 tests). Total ≈36 tests. Integration/DB tests fuera de alcance.

- **5.3 ✅** Sweep de `RuntimeException` literal en `src/Service/`. 2 excepciones tipadas nuevas en `src/Service/Exception/`: `GmailAuthenticationException` (3 throws en `GmailService`), `SettingsEncryptionException` (1 throw en `SettingsEncryptionTrait`). Ambas heredan `\RuntimeException` para compatibilidad. `grep "throw new RuntimeException" src/` retorna 0.

- **5.5 ✅** Value Object `App\Service\Dto\SystemConfig` (readonly) compone 5 sub-configs (`GmailConfig`, `SmtpConfig`, `N8nConfig`, `WhatsappConfig`, `AppConfig`). Mapper `fromSettingsArray` tolerante a keys faltantes. `toSettingsArray` permite bridge a código legacy (`ConfigResolutionTrait`). Servicios migrados: 5 ticket services (firma + interno) + 3 integration services (firma + interno via `toSettingsArray`). `GmailService` queda en su forma de config propia (decisión documentada — su shape `client_secret JSON decoded + refresh_token` no es system-wide settings). `TicketServiceInitializerTrait::initializeServices` y `Application::bootstrap` construyen el VO desde cache.

- **5.6 ✅** `SidebarCountsService` se mantiene sin cambios. Justificación documentada: post-fase 2 ya consume `getAgentStatusCounts` desde el Cell; el Cell es hoy el único caller pero la abstracción está lista para ser reutilizada por futuras vistas. Coste de inlinear y eventualmente re-extraer > beneficio.

**Auditoría 100% cerrada.** Pendientes futuros explícitos (NO bugs):
- Integration tests con DB (fixtures, schema migrado en setUp).
- Tests de servicios con mocks extensivos.
- Eventos adicionales (`TicketCommentAdded`, `TicketPriorityChanged`, `TicketTagAdded`, `TicketFollowerAdded`).
- Asincronía de eventos (queue dispatch).
- CI pipeline.

Plan ejecutado: `docs/superpowers/plans/2026-05-08-audit-fase4-medios.md`.
Diseño: `docs/superpowers/specs/2026-05-08-audit-fase4-medios-design.md`.
```

- [ ] **Step 3: Update CLAUDE.md — testing section**

Open `CLAUDE.md`. In the "Common commands" section (around the existing `composer cs-fix` block), add:

```bash
composer test                                # run unit tests (PHPUnit 10)
composer test-coverage                       # run with HTML coverage report (./coverage)
```

Replace the existing line "This project does not run an automated test suite — there is no `tests/` directory and no PHPStan configuration." with:

```markdown
This project runs a minimal unit test suite (no DB, no fixtures) covering the `Ticket` entity, the `SystemConfig` value object, and domain events. Run with `composer test`. Integration/DB tests are not yet bootstrapped — verify those flows manually (browser, CLI command, app logs). No PHPStan configuration.
```

- [ ] **Step 4: Update CLAUDE.md — Configuration & environment section**

In the "Configuration & environment" section, after the line about `system_settings`/`email_templates`, add:

```markdown
- Service-side configuration is exposed as the `App\Service\Dto\SystemConfig` value object — composed of `GmailConfig`, `N8nConfig`, `WhatsappConfig`, `AppConfig`, `SmtpConfig` (readonly). Built from the cached settings snapshot in `TicketServiceInitializerTrait::initializeServices` and `Application::bootstrap`, then passed to all ticket services and integration services (`EmailService`, `WhatsappService`, `N8nService`). `GmailService` keeps its own `array $config` shape (decoded client_secret + refresh_token) because its data flow is OAuth-specific.
```

- [ ] **Step 5: Update CLAUDE.md — Cross-cutting conventions**

In the "Cross-cutting conventions" section, after the **Notifications** bullet, add:

```markdown
- **Domain events**: ticket lifecycle events live in `src/Domain/Event/` (`TicketCreated`, `TicketAssigned`, `TicketStatusChanged`). They extend `Cake\Event\Event` and are dispatched through `EventManager::instance()`. The listener `App\Listener\TicketNotificationListener` is registered in `Application::bootstrap` and translates events into `TicketNotificationService` calls. New ticket-state notifications should fire an event rather than calling the notification service directly. The events `TicketCommentAdded`, `TicketPriorityChanged`, etc. are NOT yet defined — extend the family rather than reaching into `TicketNotificationService` for new flows.
```

- [ ] **Step 6: Run final cs-check + tests**

Run: `composer cs-check`
Expected: 0 errors across the full project.

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add docs/audits/2026-05-07-architecture-audit.md CLAUDE.md
git commit -m "docs(audit): close fase 4 medios (5.1-5.6) — Anexo 6 + CLAUDE.md sync"
```

---

## Verification (final state)

After Task 17 commits, the repository must satisfy ALL of these:

- [ ] `grep -rn "throw new RuntimeException\|throw new \\\\RuntimeException" src/` → 0 matches.
- [ ] `grep -rn "private ?array \$systemConfig" src/Service/Ticket*.php` → 0 matches (replaced by `private SystemConfig $config`).
- [ ] `composer test` → all tests pass (~36 tests).
- [ ] `composer cs-check` → 0 errors.
- [ ] `php -r "require 'vendor/autoload.php'; var_dump(\\App\\Service\\Dto\\SystemConfig::empty());"` → no PHP errors, dumps a SystemConfig instance.
- [ ] Manual smoke (login, ticket view, assign, status change) — no regression in error logs.
- [ ] `docs/audits/2026-05-07-architecture-audit.md` ends with Anexo 6.
- [ ] `CLAUDE.md` mentions testing, SystemConfig, and domain events.

---

## Rollback strategy

If any sub-fase introduces a regression discovered after merge:

- **5.3 (exceptions):** revert single commit. Behavior identical, just type changes.
- **5.5 (SystemConfig):** revert commits for tasks 3–7 in reverse order. The constructors accept `null` as default, so reverting integration services first won't break ticket services.
- **5.1 (events):** revert commits for tasks 8–11 in reverse order. The bootstrap-listener registration must be removed before the dispatch-event commits to avoid orphaned event sites.
- **5.2 (tests):** independent — can be reverted without affecting runtime.

Each commit is small enough that a single `git revert` is safe.
