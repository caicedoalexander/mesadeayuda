# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Mesa de Ayuda** — CakePHP 5.x corporate helpdesk platform built around the **Tickets** module. Backend in PHP 8.1+, MySQL/MariaDB, server-rendered Bootstrap 5 templates. Integrates Gmail (email-to-ticket), n8n (webhooks), and WhatsApp via Evolution API.

> Note: the older Estadísticas (Statistics), Organizaciones (Organizations), PQRS, SLA management and **Compras** modules have been removed from the codebase. Don't reintroduce references to them.

## Common commands

Run from project root. Inside Docker prefix with `docker compose exec web …`.

```bash
composer install                            # install PHP deps
composer cs-check                           # PHPCS using CakePHP ruleset
composer cs-fix                             # PHPCBF auto-fix
composer test                                # run unit tests (PHPUnit 13)
composer test-coverage                       # run with HTML coverage report (./coverage)

bin/cake server                              # dev server on http://localhost:8765
bin/cake migrations migrate                  # apply pending migrations
bin/cake migrations status
bin/cake bake migration CreateFooTable
bin/cake bake model Foos
bin/cake import_gmail --max 5                # one-shot Gmail import (debug). Production
                                              # imports are triggered by n8n via
                                              # POST /webhooks/gmail/import.

docker compose up -d --build                 # web (Nginx + PHP-FPM)
```

This project runs a minimal unit test suite (no DB, no fixtures) covering the `Ticket` entity domain methods (predicates, transitions, assignability). Run with `composer test`. Integration/DB tests are not yet bootstrapped — verify those flows manually (browser, CLI command, app logs). PHPStan is installed but without project-level configuration.

## Architecture

### Layered structure
The codebase follows a fat-service / thin-controller pattern on top of CakePHP:

- **`src/Controller/`** — HTTP edge. `TicketsController` (~70 LOC) compone seis traits bajo `src/Controller/Trait/`:
  - `TicketServiceInitializerTrait` — initialize de servicios, normalizadores de view-data, helpers de tabla/historia.
  - `TicketListingTrait` — acción `index` y filtros laterales.
  - `TicketViewTrait` — acción `view` y configuración de pantalla de detalle.
  - `TicketActionsTrait` — `assign`, `addComment`, `changeStatus`, `changePriority`, `addTag`, `removeTag`, `addFollower`, `downloadAttachment`.
  - `TicketBulkTrait` — operaciones masivas (`bulkAssign`, `bulkChangePriority`, `bulkAddTag`, `bulkDelete`).
  - `TicketHistoryTrait` — endpoint JSON lazy-loaded de historial.

  Las reglas de dominio (estados válidos, transiciones, reasignación) viven en la entidad `Ticket` (`isLocked`, `canTransitionTo`, `canBeAssignedTo`) y son consumidas desde los traits. El parámetro `$entityType` heredado del módulo Compras fue eliminado en mayo 2026.
- **`src/Controller/Admin/`** — `Admin` route prefix (Settings, EmailTemplates, Tags). Las credenciales OAuth de Gmail (`client_secret.json`) se pegan como texto en `/admin/settings` y se guardan cifradas en `system_settings.gmail_client_secret_json` — no se sube ningún archivo.
- **`src/Service/`** — Business logic. Domain services agrupados por responsabilidad:
  - `TicketIngestionService` — creación de tickets/comentarios desde fuentes externas (Gmail).
  - `TicketPipelineService` — transiciones de estado, asignación, prioridad, tags, followers, `handleResponse`.
  - `TicketCommentService` — comentarios manuales con sanitización HTML.
  - `TicketAttachmentService` — uploads y procesamiento de adjuntos.
  - `TicketNotificationService` — despacho email + WhatsApp + n8n.

  Integraciones (`GmailService`, `EmailService`, `WhatsappService`, `N8nService`), cross-cutting helpers (`SidebarCountsService`, `NumberGenerationService`, `EmailTemplateRenderer`, `SettingsService`, `AuthorizationService`, `ProfileImageService`). Reusable mixin logic en `src/Service/Traits/`: `ConfigResolutionTrait`, `GenericAttachmentTrait`, `SecureHttpTrait`, `SettingsEncryptionTrait` (consumed by `AppController` and `GmailImportService` for transparent encryption of sensitive `system_settings` keys), `HtmlSanitizerTrait`, `TicketHistoryLoggerTrait`. Attachments are stored on local disk under `webroot/uploads/`.

  **DI patrón:** los servicios de dominio aceptan dependencias opcionales por constructor (`?Service $svc = null`) con default a instanciación interna; esto habilita testing futuro sin romper callers actuales.
- **`src/Constants/`** — final classes con constantes de dominio. **Nunca hardcodear strings o IDs de dominio**; referenciar estas clases. Archivos:
  - `TicketConstants` — estados de ticket, prioridades, tipos de comentario, labels y colores de presentación.
  - `RoleConstants` — roles de usuario y atajo `STAFF_ROLES`.
  - `CacheConstants` — keys/configs de cache y `DEFAULT_SYSTEM_TITLE`.
  - `SettingKeys` — keys usadas en la tabla `system_settings`.
- **`src/Model/Table/`, `src/Model/Entity/`** — CakePHP ORM. Tickets data family: `Tickets`/`TicketComments`/`TicketHistory`/`TicketFollowers`/`TicketTags`. Plus `Users`, `Tags`, `Attachments`, `EmailTemplates`, `SystemSettings`.
- **`src/Model/Behavior/AuditBehavior.php`** — behavior that powers the `ticket_history` table. New auditable models should attach this rather than rolling custom audit logic.
- **`src/Command/`** — CLI commands: `ImportGmailCommand` (one-shot debug wrapper around `GmailImportService`), `TestEmailCommand`.
- **`src/Controller/WebhooksController.php`** — sin-sesión, sin-CSRF endpoint para integraciones externas. Hoy solo expone `POST /webhooks/gmail/import` (autenticado por shared secret en header `X-Webhook-Token`, almacenado cifrado en `system_settings.webhook_gmail_import_token`). Disparado por n8n; ver `docs/operations/n8n-gmail-webhook.md`.
- **`src/View/`** — `AppView` and `AjaxView`; `Cell/` for reusable view components, `Helper/` for templating helpers.
- **`templates/`** — server-rendered `.php` templates organized per controller.
- **`config/Migrations/`** — versioned schema; migrations are the source of truth for DB structure.

### Routing & request flow
`config/routes.php` is the canonical map. Highlights:
- `/` → `TicketsController::index` (named `home`).
- `/health` → `HealthController::check` (used by Docker healthchecks; verifies Nginx + PHP-FPM + DB).
- `/admin/*` → `Admin` prefix (defaults to `Settings::index`).
- All routes use `DashedRoute` and accept `.json` extension for API-style responses.

`src/Application.php` defines the middleware stack: ErrorHandler → AssetMiddleware → Routing → BodyParser → CSRF (httponly) → SecurityHeaders + an inline CSP middleware (allows `cdn.jsdelivr.net`, jQuery CDN, Google Fonts) → Authentication. Auth uses `Authentication.Session` + `Authentication.Form` against `email`/`password` on `Users`; unauthenticated users redirect to `/users/login`.

### Cross-cutting conventions
- **Ticket status enum**: el modelo canónico de 4 estados (`nuevo`, `abierto`, `pendiente`, `resuelto`) vive en `TicketConstants::STATUSES`. Estados anteriores (`convertido`, `cerrado`, `en_progreso`) fueron consolidados a `resuelto` en la migration `ConsolidateLegacyTicketStatuses` (mayo 2026). Los helpers `StatusHelper::statusLabel/statusColor` mantienen un fallback genérico defensivo, pero ningún flujo de runtime debería producir valores fuera de `STATUSES`.
- **Audit trail**: ticket mutations write to `ticket_history` via `AuditBehavior` (changed_by, field_name, old_value, new_value, description, created). Don't bypass this when mutating ticket entities.
- **Notifications**: outbound notifications (email + WhatsApp + n8n webhook) flow through `NotificationDispatcherTrait` + `EmailTemplateRenderer` + `Renderer/NotificationRenderer`. Add new notification types by extending the renderer + templates rather than calling integrations directly from controllers.
- **Domain events**: ticket lifecycle events live en `src/Domain/Event/` (`TicketCreated`, `TicketAssigned`, `TicketStatusChanged`). Extienden `Cake\Event\Event` y se despachan vía `EventManager::instance()`. El listener `App\Listener\TicketNotificationListener` se registra en `Application::bootstrap` y traduce únicamente los eventos que tienen una notificación outbound asociada hoy: `TicketCreated` → email + WhatsApp de creación, `TicketStatusChanged` → email de cambio de estado. `TicketAssigned` se sigue despachando para futuros consumidores (audit, integraciones, etc.) pero hoy no tiene handler de notificación — cuando se decida notificar asignaciones, agregar un handler real al listener (no un stub log-only). Nuevas notificaciones de cambio de estado deberían emitir un evento en lugar de invocar el servicio de notificaciones directamente. Los eventos `TicketCommentAdded`, `TicketPriorityChanged`, etc. todavía no existen — extender la familia en lugar de cablear `TicketNotificationService` desde nuevos flujos.
- **Attachments**: shared via `GenericAttachmentTrait`. Files are stored on local disk under `webroot/uploads/attachments/{ticket_number}/`. Profile images live under `webroot/uploads/profile_images/`.
- **Sidebar counters**: `SidebarCountsService` produces the unread/unassigned counts displayed across the layout — reuse it instead of querying tables ad-hoc from views.
- **Coding standard**: CakePHP CodeSniffer ruleset (`phpcs.xml`), with `SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint` excluded for `src/Controller/*` (controllers don't need return type hints; services and other classes do). Run `composer cs-fix` then `composer cs-check` before committing.
- **Strict types**: every PHP file declares `declare(strict_types=1);`.
- **Domain methods en `Ticket`**: la entidad expone predicados (`isResolved`, `isOpen`, `isNew`, `isPending`, `isLocked`, `hasAssignee`, `belongsTo`, `isAssignedTo`, `wasCreatedFromEmail`) y reglas de transición (`canTransitionTo`, `canBeAssignedTo`). Estos métodos son la fuente de verdad — controllers y services deben consumirlos en lugar de comparar `status` o `assignee_id` directamente. La matriz `Ticket::TRANSITIONS` define las transiciones legales del state machine; `TicketPipelineService::changeStatus` lanza `InvalidStatusTransitionException` si se viola. `User::isStaff()` agrupa el chequeo de roles admin/agent.

### Configuration & environment
- Local config in `config/app_local.php` (gitignored; copy from `config/app_local.example.php`).
- Runtime configuration is environment-driven; `docker-compose.yml` enumerates the variables (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SECURITY_SALT`, `TRUST_PROXY`, `FULL_BASE_URL`).
- Optional `.env` loading (`config/.env`) is wired through `josegonzalez/dotenv` in `config/bootstrap.php`.
- Per-tenant runtime settings (Gmail OAuth tokens, integration credentials, email templates, etc.) live in `system_settings` and `email_templates` tables and are managed at `/admin/*` — there is no static config file for them.
- Service-side configuration is exposed as the `App\Service\Dto\SystemConfig` value object — composed of `GmailConfig`, `N8nConfig`, `WhatsappConfig`, `AppConfig`, `SmtpConfig` (readonly). Built from the cached settings snapshot in `TicketServiceInitializerTrait::initializeServices` and `Application::bootstrap`, then passed to all ticket services and integration services (`EmailService`, `WhatsappService`, `N8nService`). `GmailService` keeps its own `array $config` shape (decoded `client_secret` + `refresh_token`) because its data flow is OAuth-specific.

### Docker topology
Single `Dockerfile` builds an image that runs Nginx + PHP-FPM (port 80, mapped to 8082 on host). `docker-compose.yml` defines a single service:
- **`web`** — HTTP entrypoint. References `mesadeayuda_network` (assumed to be created externally; database connectivity is reached over the host network via `DB_HOST`).

Gmail import: triggered by n8n via `POST /webhooks/gmail/import` (shared secret in `webhook_gmail_import_token` setting). The CLI command `bin/cake import_gmail` is preserved for manual debug. The previous `worker` service that ran `bin/cake gmail_worker` continuously was removed; recover from git history if needed.

The MySQL database is **not** part of `docker-compose.yml` — it's expected to be provided externally (managed instance, host MySQL, or a separately-orchestrated container).
