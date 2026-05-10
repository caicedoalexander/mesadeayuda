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
bin/cake test_email                   # SMTP smoke test
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

`AuditBehavior` (src/Model/Behavior/AuditBehavior.php) auto-writes `*_history` rows for operational tables. **Never bypass this when mutating audited entities** — go through the Table layer; don't issue raw SQL updates that skip behaviors.

Schema source of truth is `config/Migrations/`. `bin/cake bake` is allowed (CakePHP convention) but generated artifacts are expected to be edited to match the existing service-layer style.

### Configuration model

Two layers:
1. **File-based**: `config/app_local.php` (gitignored) plus optional `config/.env` loaded by `josegonzalez/dotenv` from `config/bootstrap.php`. DB credentials, `SECURITY_SALT`, `FULL_BASE_URL`, `TRUST_PROXY`.
2. **Tenant/runtime**: `system_settings` and `email_templates` tables, edited via `/admin`. Includes Gmail OAuth tokens and integration credentials. Encrypted-at-rest fields use `SettingsEncryptionTrait`. Settings are cached (`CacheConstants::CACHE_SETTINGS` in the `CacheConstants::CACHE_CONFIG` cache); `SystemConfig` (an immutable DTO in `src/Service/Dto/`) is the read-side projection — services accept `SystemConfig`, not raw arrays. `SettingKeys` enumerates legal keys.

### Notifications and integrations

Outbound channels (email, WhatsApp, n8n webhooks) are dispatched through the notification service + `EmailTemplateRenderer`. Do NOT call `EmailService`, `WhatsappService`, or `N8nService` directly from controllers. To add a notification type, extend the renderer and templates rather than wiring integration calls into request handlers.

`GmailImportService` + `TicketIngestionService` cover the inbound side; UTF-8 + markup-safe truncation lives in `TicketIngestionService` (commit b4413a1). Atomic ticket-number allocation runs through `NumberGenerationService` (commit 9ac752a) — don't reintroduce read-modify-write on the counter.

### Attachments

`GenericAttachmentTrait` is the shared upload/validation entry point (security tests in `tests/TestCase/Service/...`, commit da5a70d). Files land under `webroot/uploads/attachments/{ticket_number}/`; this path is volume-mounted in `docker-compose.yml` and must remain writable by the FPM user.

### Sidebar counts

Reuse `SidebarCountsService`; do not query ticket tables from views.

## Coding conventions

- `declare(strict_types=1);` is mandatory in every PHP file.
- Constants for ticket statuses/transitions live in `src/Constants/TicketConstants.php`. Roles in `RoleConstants.php`. Setting keys in `SettingKeys.php`. Don't reintroduce magic strings (commit aa7fade).
- Lazy DI is used in `TicketServiceInitializerTrait` for Gmail/n8n services (commit 01f498d) — keep it lazy; instantiating these eagerly inflates request latency.
- HTML coming from email bodies must pass through `HtmlSanitizerTrait` (htmlpurifier) before render or storage.

## Testing

Tests live in `tests/TestCase/{Model,Service}/...` and bootstrap via `tests/bootstrap.php` (no fixtures wired by default — most existing tests are pure unit tests against domain logic and service traits). When adding integration-style tests that touch the ORM, add the necessary fixture wiring rather than mocking the Table layer.

## Reference docs

- `docs/operations/n8n-gmail-webhook.md` — wiring of the Gmail import webhook.
- `docs/audits/2026-05-07-architecture-audit.md`, `docs/audits/2026-05-09-dead-code-audit.md` — recent audit findings driving the active refactor direction (domain events, predicates, dead-code removal).
