# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project context

CakePHP 5.x helpdesk platform (PHP 8.5+, MySQL/MariaDB). Two modules: internal ticketing (`/`) and admin configuration (`/admin`). Native integrations with Gmail API, n8n (webhooks), and WhatsApp (Evolution API).

Documentation in Spanish, code in English. README.md is authoritative for setup; this file captures architecture and workflow rules that aren't obvious from reading individual files.

## Common commands

```bash
# Tests (PHPUnit, suite "Unit" in tests/TestCase)
composer test
composer test-coverage              # HTML coverage in coverage/
vendor/bin/phpunit --filter TicketTest                    # single test class
vendor/bin/phpunit tests/TestCase/Service/Foo/BarTest.php # single file

# Code style (CakePHP CodeSniffer, ruleset in phpcs.xml)
composer cs-check
composer cs-fix

# Static analysis (PHPStan installed; no level configured at repo root — invoke directly)
vendor/bin/phpstan analyse src

# CakePHP console
bin/cake server                       # dev server on :8765
bin/cake migrations migrate
bin/cake migrations status
bin/cake bake migration CreateFooTable
bin/cake import_gmail --max 5         # manual Gmail ingest (prod path is the n8n webhook)
```

Pre-commit expectation: `composer cs-fix && composer cs-check` before any commit.

## High-level architecture

### Layering (fat-service / thin-controller)

```
Controller  → Service  → Domain entities + Model/Table (ORM)
   │            │           │
   │            │           └─ AuditBehavior writes *_history rows
   │            └─ dispatches Domain\Event\* on global EventManager
   └─ delegates; uses Trait/ mixins for shared ticket UI flows
```

- Controllers in `src/Controller/` are deliberately thin. Cross-cutting ticket UI flows live in `src/Controller/Trait/` (`TicketActionsTrait`, `TicketBulkTrait`, `TicketHistoryTrait`, `TicketListingTrait`, `TicketServiceInitializerTrait`, `TicketViewTrait`).
- Business logic lives in `src/Service/`. Reusable service-layer mixins in `src/Service/Traits/` (notably `ConfigResolutionTrait`, `GenericAttachmentTrait`, `HtmlSanitizerTrait`, `SecureHttpTrait`, `SettingsEncryptionTrait`, `TicketHistoryLoggerTrait`).
- Domain primitives (entities and predicates) in `src/Model/Entity/` and `src/Domain/`. Recent refactors moved ticket state checks into `Ticket` predicates — prefer those over reimplementing status logic in services.

### Domain events

Bootstrap (`Application::registerDomainEventListeners()`, src/Application.php:76) registers `TicketNotificationListener` on the global `EventManager` at startup. Services dispatch `App\Domain\Event\*` events (`TicketCreated`, `TicketAssigned`, `TicketStatusChanged`) instead of calling notification/integration code directly.

Rules when extending:
- Event payloads carry IDs only; the listener reloads aggregates from the DB.
- Listeners catch `Throwable` and log — they must NEVER let exceptions propagate back to the dispatcher.
- A no-op listener for `TicketAssigned` was deliberately removed (commit edac652). Don't add log-only stubs back; either implement a real handler or leave the event unsubscribed for future audit/integration subscribers on the global EventManager.

### Routing surface

`config/routes.php` defines two scopes:
- `/` — DashedRoute with `.json` extension; default home is `Tickets::index`. `/admin` prefix routes through `Controller/Admin/`. `/health` (Nginx + PHP-FPM + DB liveness) for Docker healthchecks.
- `/webhooks/*` — CSRF-skipped (see `Application::middleware()`); the only route currently exposed is `POST /webhooks/gmail/import`, the production trigger for Gmail ingestion driven by n8n. Any new webhook must live under this prefix to inherit the CSRF skip.

### Persistence and auditing

`AuditBehavior` (src/Model/Behavior/AuditBehavior.php) is a helper exposing `logChange()` on `TicketHistoryTable`. **It is NOT an automatic model subscriber** — services call it explicitly via `TicketHistoryLoggerTrait::logHistory()` when they mutate audited fields. `ticket_history.changed_by` is nullable; pass `NULL` for system-initiated mutations (inbound ingestion, scheduled jobs).

Schema source of truth is `config/Migrations/`. `bin/cake bake` is allowed (CakePHP convention) but generated artifacts are expected to be edited to match the existing service-layer style.

### Configuration model

Two layers:
1. **File-based**: `config/app_local.php` (gitignored) plus optional `config/.env` loaded by `josegonzalez/dotenv` from `config/bootstrap.php`. DB credentials, `SECURITY_SALT`, `FULL_BASE_URL`, `TRUST_PROXY`.
2. **Tenant/runtime**: `system_settings` and `email_templates` tables, edited via `/admin`. Includes Gmail OAuth tokens and integration credentials. Encrypted-at-rest fields use `SettingsEncryptionTrait`. Settings are cached (`CacheConstants::CACHE_SETTINGS` in the `CacheConstants::CACHE_CONFIG` cache); `SystemConfig` (an immutable DTO in `src/Service/Dto/`) is the read-side projection — services accept `SystemConfig`, not raw arrays. `SettingKeys` enumerates legal keys.

### Notifications and integrations

Outbound channels are wired as adapters that implement `App\Notification\Channel\NotificationChannel` (`EmailChannel`, `WhatsappChannel`). A per-event Strategy under `App\Notification\Strategy\*` builds `NotificationMessage` value objects from a domain event; `TicketNotificationService` routes each message to the channel whose `name()` matches.

To add a new ticket notification:
1. Define a domain event under `App\Domain\Event\` (extending `DomainEvent`).
2. Implement a `TicketNotificationStrategy` that `supports($event)` and `buildMessages($event)`.
3. Subscribe the event in `TicketNotificationListener::implementedEvents()`.
4. Register the strategy in `Application::registerDomainEventListeners()`.

Do NOT call `EmailService`, `WhatsappService`, or any channel directly from controllers or services — publish a domain event and let the listener dispatch.

**WhatsApp: dos integraciones por diseño.**
- **Bot WhatsApp (inbound + outbound conversacional)** → Meta Cloud API (`graph.facebook.com`), gestionado en n8n.
- **Notificaciones outbound de ticket creado al equipo de soporte** → Evolution API, gestionado en backend (`WhatsappService::sendNewEntityNotification`).

Cada API tiene su propio caso de uso y credenciales en `system_settings`.

`GmailImportService` + `TicketIngestionService` cover the inbound side; UTF-8 + markup-safe truncation lives in `TicketIngestionService`. El identificador del ticket es el `id` autoincremental de la tabla `tickets` (arranca en 1000); MySQL garantiza unicidad y atomicidad — no introducir tablas de secuencia ni contadores propios.

### Attachments

`GenericAttachmentTrait` is the shared upload/validation entry point (security tests in `tests/TestCase/Service/...`, commit da5a70d). Files land under `webroot/uploads/attachments/{ticket_number}/`; this path is volume-mounted in `docker-compose.yml` and must remain writable by the FPM user.

### Sidebar counts

Reuse `SidebarCountsService`; do not query ticket tables from views.

## Coding conventions

- `declare(strict_types=1);` is mandatory in every PHP file.
- Constants for ticket statuses/transitions live in `src/Constants/TicketConstants.php`. Roles in `RoleConstants.php`. Setting keys in `SettingKeys.php`. Don't reintroduce magic strings (commit aa7fade).
- Lazy DI is used in `TicketServiceInitializerTrait` for Gmail/n8n services (commit 01f498d) — keep it lazy; instantiating these eagerly inflates request latency.
- HTML coming from email bodies must pass through `HtmlSanitizerTrait` (htmlpurifier) before render or storage.

### CSS y sistema de diseño

- Antes de usar una clase CSS en un template, verifica que el archivo que la define esté cargado por esa vista. Archivos cargados globalmente desde `templates/element/head.php`: `styles`, `components`, `badges`, `tickets-rail`. View-scoped: `tickets-view.css` (solo en la vista de detalle de ticket vía `element/tickets/styles_and_scripts.php`) y `bulk-actions.css` (solo en la vista index de tickets).
- Si una clase se usa en más de una vista (o podría usarse), su CSS debe vivir en `webroot/css/components.css` y estar documentada en `docs/design/DESIGN.md` antes del merge. CSS view-scoped es solo para clases que viven exclusivamente dentro de esa ruta.
- No dupliques una regla CSS en dos archivos. Si necesitas extender un componente compartido en un contexto específico, usa un modifier (`--row`, `--compact`, etc.) en el CSS view-scoped y deja el bloque base en `components.css`.
- `docs/design/DESIGN.md` es la fuente única de los componentes del sistema de diseño. Cuando crees, renombres o muevas un componente compartido, actualiza DESIGN.md en el mismo commit que el CSS.

## Testing

Tests live in `tests/TestCase/{Model,Service}/...` and bootstrap via `tests/bootstrap.php` (no fixtures wired by default — most existing tests are pure unit tests against domain logic and service traits). When adding integration-style tests that touch the ORM, add the necessary fixture wiring rather than mocking the Table layer.

## Reference docs

- `docs/design/DESIGN.md` — design system source of truth (tokens, components, rules). All CSS variables live in `webroot/css/styles.css :root`; **never hardcode colors/spacing/radii in components** — read tokens from there. Add new component specs to this doc before implementing.
- `docs/operations/n8n-gmail-webhook.md` — wiring of the Gmail import webhook.
- `docs/audits/2026-05-14-tickets-module-audit.md` — most recent audit driving the active refactor direction (domain events, predicates, dead-code removal).
