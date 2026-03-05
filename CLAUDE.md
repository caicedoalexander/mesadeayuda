# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Mesa de Ayuda** is a CakePHP 5.x corporate management system integrating helpdesk (support tickets), purchase management, and PQRS (customer requests/complaints). It features integrations with Gmail, n8n, WhatsApp, and AWS S3 for automation and communication.

### Key Modules
- **Helpdesk (Tickets)**: Internal support ticket management with email-to-ticket conversion via Gmail
- **Compras (Purchases)**: Procurement workflow with approval chains and trazability
- **PQRS**: Customer-facing channel for requests, complaints, and suggestions with public tracking portal
- **Admin**: Configuration for integrations, SLA management, statistics, and system settings

## Architecture

### Directory Structure
```
src/
├── Service/         # Business logic services (TicketService, ComprasService, GmailService, etc.)
├── Controller/      # HTTP request handlers (TicketsController, ComprasController, PqrsController)
│   ├── Admin/      # Admin panel controllers
│   ├── Component/  # Reusable controller components
│   └── Traits/     # Controller trait mixins
├── Model/
│   ├── Table/      # ORM table classes for each entity
│   ├── Entity/     # Data entity classes
│   └── Behavior/   # ORM behaviors (auditing, timestamps, etc.)
├── Command/        # Console commands (migrations, import_gmail, etc.)
├── View/
│   ├── Cell/       # Reusable view components
│   └── Helper/     # Custom template helpers
├── Utility/        # Utility functions and helpers
└── Console/        # Console application class

config/
├── Migrations/     # Database migration files (versioned DB schema)
├── routes.php      # URL routing definitions
├── app.php         # Application configuration
├── app_local.php   # Local environment overrides (DB, auth, etc.)
└── bootstrap.php   # Framework bootstrap and service registration

templates/         # Server-rendered views (Blade-like syntax)
tests/TestCase/    # PHPUnit test suites (structure mirrors src/)
webroot/           # Public assets (CSS, JS, images)
```

### Key Services & Integrations
- **TicketService**: Ticket creation, status transitions, assignment, history tracking
- **GmailService**: OAuth authentication, email fetching, attachment handling
- **EmailService**: SMTP sending, template rendering, notification queue
- **ComprasService**: Purchase order management, approval workflows, status tracking
- **N8nService**: Webhook integration for n8n workflows (ticket classification, notifications)
- **WhatsappService**: WhatsApp Business API integration via Evolution API
- **SlaManagementService**: SLA rule evaluation and breach detection
- **StatisticsService**: Analytics queries for dashboards
- **S3Service**: AWS S3 file storage and management

### Database
- **MySQL 8.0+** with utf8mb4 encoding
- Timezone: America/Bogota (UTC-5)
- Migrations managed via CakePHP Migrations plugin
- Audit trail: History tables log all changes (changed_by, field_name, old_value, new_value, description, created)

## Development Commands

### Setup
```bash
composer install                           # Install PHP dependencies
cp config/app_local.example.php config/app_local.php  # Configure database
php bin/cake.php migrations migrate       # Run database migrations
```

### Running the Application
```bash
php bin/cake.php server                   # Start dev server on http://localhost:8765
docker compose up -d --build              # Start Docker environment
```

### Testing
```bash
composer test                             # Run all tests (PHPUnit)
phpunit tests/TestCase/                   # Run specific test suite
phpunit --filter TicketsControllerTest    # Run specific test class
phpunit --filter testView                 # Run specific test method
```

### Code Quality
```bash
composer check                            # Run all checks: tests + cs-check + stan
composer cs-check                         # Check CakePHP coding standards (PHPCS)
composer cs-fix                           # Auto-fix code style violations (PHPCBF)
composer stan                             # Static type analysis (PHPStan level 5)
psalm config/psalm.xml                    # Alternative type checking
```

### Database Maintenance
```bash
php bin/cake.php migrations status        # Show migration status
php bin/cake.php migrations migrate       # Run pending migrations
php bin/cake.php bake migration           # Generate new migration
docker compose exec web php bin/cake.php migrations migrate  # Via Docker
```

### Monitoring & Debugging
```bash
docker compose logs -f web                # Stream application logs
docker compose logs -f worker             # Stream Gmail worker logs
curl http://localhost:8765/health         # Health check endpoint (JSON)
docker compose exec web php bin/cake.php cache clear_all  # Clear cache
```

## Configuration

### Critical Environment Variables
- **SECURITY_SALT**: 64-character hex string (encryption key). Generate: `php -r "echo bin2hex(random_bytes(32));"`
- **DB_HOST, DB_USER, DB_PASSWORD, DB_NAME**: Database connection credentials
- **TRUST_PROXY**: Set to `true` when behind reverse proxy (HTTPS detection)
- **WORKER_ENABLED**: Enable/disable Gmail background worker

### Integration Configuration
Stored in `SystemSettings` table (accessed via `/admin/settings`):
- **Gmail**: OAuth credentials file (client_secret.json), check interval (default 5 min)
- **n8n**: Webhook endpoint URL for outbound automation
- **WhatsApp**: Evolution API base URL and auth token
- **AWS S3**: Bucket name, region, credentials (optional, for file storage)

See `DOCKER.md` for comprehensive environment variable documentation.

## Key CakePHP Concepts Used

### ORM & Models
- **Table classes** (`src/Model/Table/`): Database queries, relationships, validation
- **Entity classes** (`src/Model/Entity/`): Object representation with type hints
- **Behaviors**: DRY pattern for shared model functionality (timestamps, audit trail)

### Controllers & Routing
- Prefix routing: `/admin/*` routes to `Admin` prefix controllers
- RESTful actions: `index`, `view`, `add`, `edit`, `delete` follow conventions
- JSON support: Routes accept `.json` extension for API responses
- Request/Response objects: Type-hint `\Cake\Http\ServerRequest $request`

### Views & Templates
- Server-side rendering with `.php` template files in `templates/`
- Cell pattern: Reusable components rendered via `$this->cell()`
- Bootstrap 5 for UI framework

### Testing
- Uses CakePHP test fixtures (`tests/Fixture/`) for test database setup
- Database gets rolled back after each test
- Mock external services (Gmail API, WhatsApp, n8n) to avoid outbound calls

## Code Style & Standards

- **PSR-12** extended by CakePHP CodeSniffer ruleset
- **Return type hints** mandatory on public methods (except Controllers, excluded by phpcs.xml)
- **Property type declarations** required (strict types)
- **Strict comparison** (`===`, `!==`) preferred over loose (`==`, `!=`)
- **Service injection** over static calls (e.g., `$this->TicketService->create()` in controllers)

### PHPStan Configuration
- Analysis level: **5** (strict)
- Configured to ignore CakePHP magic properties, dynamic finders, and authentication interface quirks
- Run before committing: `composer stan`

## Common Development Patterns

### Creating a New Feature
1. **Add database schema** (Migrations/): `php bin/cake.php bake migration create_table_name`
2. **Generate Model classes** (Table, Entity): `php bin/cake.php bake model TableName`
3. **Create Service class** (`src/Service/FeatureService.php`) with business logic
4. **Build Controller** (`src/Controller/FeatureName.php`) with HTTP request handling
5. **Write tests first** (`tests/TestCase/Controller/FeatureNameControllerTest.php`)
6. **Add routes** if new URL patterns needed (`config/routes.php`)
7. **Create templates** (`templates/FeatureName/`) for views
8. **Run checks** before pushing: `composer check`

### Database Auditing
- All tables have `created` (timestamp), `modified` (timestamp)
- History tables log changes: field_name, old_value, new_value, changed_by (user ID), description, created
- Use `HistoriesBehavior` on models to auto-log changes (enabled on Tickets, Compras, Pqrs tables)

### External Service Integration
- Services are injected into controllers via property type declarations
- Methods should be documented with parameter/return types
- Use environment variables for API credentials (never hardcode)
- Wrap API calls in try-catch with meaningful error handling
- Log errors for debugging: `$this->log($message, LogLevel::ERROR)`

## Docker Deployment

```bash
docker compose up -d --build
# Services: web (Nginx + PHP-FPM), worker (Gmail import)
```

Single Dockerfile runs Nginx + PHP-FPM in one container (port 80). Compatible with Easypanel and single-container platforms. The `worker` container runs `php bin/cake.php gmail_worker` in a continuous loop. It waits for database connectivity on startup (10 attempts, 5s intervals). Gmail OAuth must be configured in admin panel (`/admin/settings`) before emails are imported. Set `WORKER_ENABLED=false` to disable.

## Troubleshooting

### Database Connection Errors
```bash
docker compose exec web php bin/cake.php migrations status
docker compose exec web env | grep DB_
```

### Worker Not Importing Emails
1. Verify SECURITY_SALT set for worker service
2. Check Gmail configured: `/admin/settings`
3. Inspect logs: `docker compose logs -f worker`
4. Test manually: `docker compose exec web php bin/cake.php import_gmail --max 5`

### Code Quality Failures
- **PHPStan**: Usually type hint issues. Check error location and add explicit type casts/assertions
- **PHPCS**: Run `composer cs-fix` to auto-fix most issues
- **PHPUnit**: Check test database connectivity and fixtures are properly defined

### Permission Issues in Docker
```bash
docker compose exec web chown -R www-data:www-data logs tmp webroot/uploads
```

## Important Notes

- **HTTPS Required**: Gmail OAuth requires HTTPS in production. Use reverse proxy (Nginx, Traefik, Caddy) with `TRUST_PROXY=true`
- **Sensitive Data**: Never commit `.env` or `config/app_local.php`. Use `.example` templates
- **Migrations**: Always run migrations before starting application (`php bin/cake.php migrations migrate`)
- **File Uploads**: Public uploads go to `webroot/uploads/`, ensure `tmp/` is writable
- **Gmail Worker**: Enabled by default (`WORKER_ENABLED=true`). Requires Gmail OAuth setup in admin panel (`/admin/settings`). Uses exponential backoff on errors (max 10min)
