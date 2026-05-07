# Gmail Webhook Import — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Reemplazar el worker continuo de Gmail (`GmailWorkerCommand` + servicio `worker` en docker-compose) por un endpoint HTTP `POST /webhooks/gmail/import` disparado por un workflow de n8n cada N minutos. El comando CLI `bin/cake import_gmail` se conserva para debug.

**Architecture:** Extracción del cuerpo de `ImportGmailCommand::execute()` a un servicio reutilizable `GmailImportService` que retorna un DTO `GmailImportResult`. Nuevo controlador `WebhooksController` (sin sesión, autenticado por shared secret en header `X-Webhook-Token`) llama al servicio, protegiéndolo con `flock()` (lock por archivo, single-host) y rate limit de 60s. CSRF y autenticación de sesión se omiten para `/webhooks/*`. El token es un setting cifrado en `system_settings`, gestionado desde `/admin/settings`.

**Tech Stack:** PHP 8.1+, CakePHP 5.x, Phinx migrations, Nginx + PHP-FPM (Docker), `flock()` para concurrencia, n8n externo.

---

## Decisiones resueltas (Sección 8 del design)

Antes de la implementación, las decisiones pendientes del design quedan así:

| # | Decisión | Resolución |
|---|----------|------------|
| 1 | Lock engine | **`flock()`** sobre `tmp/gmail_import.lock`. No se introduce Redis (no está en stack). Aceptable para single-host. |
| 2 | Intervalo n8n | 5 minutos por defecto, configurable desde n8n. |
| 3 | `max` por defecto | 50, capado a 200 en servidor. |
| 4 | Ubicación UI token | Nueva sección "Integraciones / Webhooks" al final de `templates/Admin/Settings/index.php`. |
| 5 | Validación paralela | 1 semana con worker activo en paralelo, comparando logs. |

---

## Notas operativas

- **Sin test suite automatizado:** el proyecto no corre PHPUnit (`CLAUDE.md` lo declara explícitamente). Cada tarea termina con **verificación manual** ejecutando `bin/cake`, `curl`, o navegando la UI. No se introduce framework de testing en este plan.
- **Estilo de código:** ejecutar `composer cs-fix` y `composer cs-check` antes de cada commit.
- **`declare(strict_types=1);`** obligatorio en cada archivo PHP nuevo.
- **Ejecución dentro de Docker:** prefijar comandos con `docker compose exec web` cuando aplique. Este plan asume ejecución en host con PHP 8.1+ y MySQL accesible (igual que el dev habitual del proyecto).
- **Rama:** trabajar en la rama `dev` actual (no crear feature branch — solo commits incrementales).
- **Commits frecuentes:** un commit por tarea completada que pase verificación.

---

## Mapa de archivos

### Nuevos
- `src/Service/GmailImportService.php`
- `src/Service/Dto/GmailImportResult.php`
- `src/Service/Exception/GmailNotConfiguredException.php`
- `src/Controller/WebhooksController.php`
- `config/Migrations/YYYYMMDDHHMMSS_AddGmailWebhookToken.php` (timestamp generado por bake)

### Modificados
- `src/Command/ImportGmailCommand.php` — delega al servicio.
- `src/Application.php` — `skipCheckCallback` en CSRF middleware.
- `config/routes.php` — scope `/webhooks` con extensión JSON.
- `src/Utility/SettingKeys.php` — constante `WEBHOOK_GMAIL_IMPORT_TOKEN`.
- `src/Utility/SettingsEncryptionTrait.php` — añadir key al array `$encryptedSettings`.
- `src/Controller/Admin/SettingsController.php` — exponer token al view + acción `regenerateWebhookToken`.
- `templates/Admin/Settings/index.php` — sección "Webhooks".
- `docker/nginx/standalone.conf` — `fastcgi_read_timeout` para `/webhooks/*`.
- `docker/php/php.ini` — `request_terminate_timeout`.

### Eliminados (Stage 7, después de validación)
- `src/Command/GmailWorkerCommand.php`
- Servicio `worker` en `docker-compose.yml`
- Variable `WORKER_ENABLED` en docker-compose y env templates.
- Constante `GmailWorkerCommand::TRIGGER_FILE` y archivo `tmp/gmail_worker_trigger`.

---

# Stage 1 — Extraer GmailImportService + DTO

Refactor puro: extrae la lógica de `ImportGmailCommand::execute()` a un servicio sin tocar el worker. Validación: el comando CLI sigue funcionando idéntico.

---

### Task 1.1: Crear DTO `GmailImportResult`

**Files:**
- Create: `src/Service/Dto/GmailImportResult.php`

**Step 1: Crear el archivo del DTO**

Contenido completo:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * Resultado inmutable de una corrida del import de Gmail.
 *
 * Reemplaza la salida por consola del comando con datos estructurados
 * que pueden serializarse a JSON para la respuesta del webhook.
 */
final readonly class GmailImportResult
{
    /**
     * @param list<string> $errorMessages Mensajes de errores no fatales por mensaje individual
     */
    public function __construct(
        public int $fetched,
        public int $created,
        public int $comments,
        public int $skipped,
        public int $errors,
        public float $durationSeconds,
        public array $errorMessages = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fetched' => $this->fetched,
            'created' => $this->created,
            'comments' => $this->comments,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'duration_seconds' => round($this->durationSeconds, 3),
            'error_messages' => $this->errorMessages,
        ];
    }
}
```

**Step 2: Verificar sintaxis PHP**

Run: `php -l src/Service/Dto/GmailImportResult.php`
Expected: `No syntax errors detected in src/Service/Dto/GmailImportResult.php`

**Step 3: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Service/Dto/GmailImportResult.php
git commit -m "feat(gmail): add GmailImportResult DTO for structured import output"
```

---

### Task 1.2: Crear excepción `GmailNotConfiguredException`

**Files:**
- Create: `src/Service/Exception/GmailNotConfiguredException.php`

**Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Lanzada cuando el import se invoca sin OAuth de Gmail configurado.
 *
 * En el flujo HTTP se traduce a 503 (servicio no configurado).
 */
final class GmailNotConfiguredException extends RuntimeException
{
    public static function missingRefreshToken(): self
    {
        return new self('Gmail OAuth no configurado: falta refresh_token. Autoriza Gmail en /admin/settings.');
    }
}
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Service/Exception/GmailNotConfiguredException.php`
Expected: `No syntax errors detected ...`

**Step 3: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Service/Exception/GmailNotConfiguredException.php
git commit -m "feat(gmail): add GmailNotConfiguredException"
```

---

### Task 1.3: Crear `GmailImportService` (esqueleto + factoría)

Esta tarea crea la clase con `fromSettings()` y firma de `run()` retornando un DTO vacío. La lógica real se mueve en la siguiente tarea.

**Files:**
- Create: `src/Service/GmailImportService.php`

**Step 1: Crear el archivo con esqueleto**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Dto\GmailImportResult;
use App\Service\Exception\GmailNotConfiguredException;
use App\Utility\SettingsEncryptionTrait;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Orquesta el import de Gmail invocable desde CLI o HTTP.
 *
 * Equivalente al cuerpo de ImportGmailCommand::execute() pero sin ConsoleIo:
 * retorna un GmailImportResult con conteos en lugar de imprimir.
 */
final class GmailImportService
{
    use LocatorAwareTrait;
    use SettingsEncryptionTrait;

    public function __construct(
        private readonly GmailService $gmail,
        private readonly TicketService $tickets,
    ) {
    }

    /**
     * Construye el servicio leyendo configuración cifrada desde system_settings.
     *
     * @throws GmailNotConfiguredException si no hay refresh_token
     */
    public static function fromSettings(): self
    {
        $config = GmailService::loadConfigFromDatabase();
        if (empty($config['refresh_token'])) {
            throw GmailNotConfiguredException::missingRefreshToken();
        }

        $instance = new self(
            new GmailService($config),
            new TicketService(self::loadSystemSettings()),
        );

        return $instance;
    }

    /**
     * Carga todos los settings del sistema con desencriptado automático.
     *
     * @return array<string, string>
     */
    private static function loadSystemSettings(): array
    {
        return (new SettingsService())->loadAll();
    }

    /**
     * Ejecuta el import.
     *
     * @param int $max Máximo de mensajes a procesar (cap superior 200)
     * @param string $query Query de búsqueda Gmail (e.g. 'is:unread')
     * @param int $delayMs Delay entre mensajes en milisegundos (rate limit Gmail)
     */
    public function run(int $max = 50, string $query = 'is:unread', int $delayMs = 0): GmailImportResult
    {
        // TODO Task 1.4: mover aquí la lógica de ImportGmailCommand::execute()
        return new GmailImportResult(
            fetched: 0,
            created: 0,
            comments: 0,
            skipped: 0,
            errors: 0,
            durationSeconds: 0.0,
        );
    }
}
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Service/GmailImportService.php`
Expected: `No syntax errors detected ...`

**Step 3: Commit (esqueleto)**

```bash
composer cs-fix && composer cs-check
git add src/Service/GmailImportService.php
git commit -m "feat(gmail): add GmailImportService skeleton (no-op run)"
```

---

### Task 1.4: Mover lógica de import del comando al servicio

Mover líneas 95-247 de `src/Command/ImportGmailCommand.php` al método `run()` del servicio, reemplazando llamadas a `$io` por acumulación en arrays/contadores.

**Files:**
- Modify: `src/Service/GmailImportService.php` (reemplazar `run()` completo)

**Step 1: Reemplazar el método `run()`**

Reemplazar el cuerpo de `run()` (incluido el TODO) con esta implementación. Deja todo lo demás de la clase igual.

```php
    public function run(int $max = 50, string $query = 'is:unread', int $delayMs = 0): GmailImportResult
    {
        $startedAt = microtime(true);
        $max = max(1, min($max, 200));

        $messageIds = $this->gmail->getMessages($query, $max);
        $fetched = count($messageIds);

        if ($fetched === 0) {
            return new GmailImportResult(
                fetched: 0,
                created: 0,
                comments: 0,
                skipped: 0,
                errors: 0,
                durationSeconds: microtime(true) - $startedAt,
            );
        }

        $ticketsTable = $this->fetchTable('Tickets');
        $existingMessageIds = $ticketsTable->find()
            ->select(['gmail_message_id'])
            ->where(['gmail_message_id IN' => $messageIds])
            ->all()
            ->extract('gmail_message_id')
            ->toArray();

        $created = 0;
        $comments = 0;
        $skipped = 0;
        $errors = 0;
        $errorMessages = [];

        foreach ($messageIds as $messageId) {
            try {
                $emailData = $this->gmail->parseMessage($messageId);

                if (!empty($emailData['is_auto_reply'])) {
                    $this->gmail->markAsRead($messageId);
                    $skipped++;
                    continue;
                }

                if (!empty($emailData['is_system_notification'])) {
                    $this->gmail->markAsRead($messageId);
                    $skipped++;
                    continue;
                }

                if (in_array($messageId, $existingMessageIds, true)) {
                    $skipped++;
                    continue;
                }

                $existingTicket = null;
                if (!empty($emailData['gmail_thread_id'])) {
                    $existingTicket = $ticketsTable->find()
                        ->where(['gmail_thread_id' => $emailData['gmail_thread_id']])
                        ->first();
                }

                if ($existingTicket) {
                    $comment = $this->tickets->createCommentFromEmail($existingTicket, $emailData);
                    if ($comment) {
                        $comments++;
                    } else {
                        $skipped++;
                    }
                    $this->gmail->markAsRead($messageId);
                } else {
                    $ticket = $this->tickets->createFromEmail($emailData);
                    if ($ticket) {
                        $created++;
                        $this->gmail->markAsRead($messageId);
                    } else {
                        $errors++;
                        $errorMessages[] = "Failed to create ticket from {$messageId}";
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Gmail import per-message error', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $errors++;
                $errorMessages[] = "{$messageId}: {$e->getMessage()}";
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $result = new GmailImportResult(
            fetched: $fetched,
            created: $created,
            comments: $comments,
            skipped: $skipped,
            errors: $errors,
            durationSeconds: microtime(true) - $startedAt,
            errorMessages: $errorMessages,
        );

        Log::info('Gmail import completed', $result->toArray());

        return $result;
    }
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Service/GmailImportService.php`
Expected: `No syntax errors detected ...`

**Step 3: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Service/GmailImportService.php
git commit -m "feat(gmail): move import logic from command into GmailImportService"
```

---

### Task 1.5: Refactorizar `ImportGmailCommand` para delegar al servicio

Reduce el comando a un thin wrapper alrededor de `GmailImportService`. La salida por consola se reconstruye desde el DTO.

**Files:**
- Modify: `src/Command/ImportGmailCommand.php` (reemplazar archivo completo)

**Step 1: Reemplazar el archivo completo**

```php
<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Exception\GmailNotConfiguredException;
use App\Service\GmailImportService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;

/**
 * ImportGmail command
 *
 * Wrapper CLI sobre GmailImportService (debug manual).
 * Usage: bin/cake import_gmail [--max 50] [--query 'is:unread'] [--delay 1000]
 */
class ImportGmailCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription('Import emails from Gmail and create tickets (debug CLI for GmailImportService)');
        $parser->addOption('max', ['short' => 'm', 'help' => 'Maximum messages', 'default' => 50]);
        $parser->addOption('query', ['help' => 'Gmail search query', 'default' => 'is:unread']);
        $parser->addOption('delay', ['short' => 'd', 'help' => 'Delay between messages (ms)', 'default' => 1000]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $max = (int)$args->getOption('max');
        $query = (string)$args->getOption('query');
        $delay = (int)$args->getOption('delay');

        $io->out("Gmail import — max={$max}, query='{$query}', delay={$delay}ms");
        $io->hr();

        try {
            $result = GmailImportService::fromSettings()->run($max, $query, $delay);
        } catch (GmailNotConfiguredException $e) {
            $io->error($e->getMessage());

            return self::CODE_ERROR;
        } catch (\Throwable $e) {
            $io->error('Fatal error: ' . $e->getMessage());
            Log::error('Gmail import fatal error', ['error' => $e->getMessage()]);

            return self::CODE_ERROR;
        }

        $io->hr();
        $io->out('Import completed!');
        $io->out("  Fetched:  {$result->fetched}");
        $io->out("  Created:  {$result->created}");
        $io->out("  Comments: {$result->comments}");
        $io->out("  Skipped:  {$result->skipped}");
        $io->out("  Errors:   {$result->errors}");
        $io->out('  Duration: ' . round($result->durationSeconds, 2) . 's');

        if ($result->errors > 0) {
            $io->warning('Errors during import:');
            foreach ($result->errorMessages as $msg) {
                $io->warning('  - ' . $msg);
            }
        }

        return self::CODE_SUCCESS;
    }
}
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Command/ImportGmailCommand.php`
Expected: `No syntax errors detected ...`

**Step 3: Verificación manual — el comando sigue funcionando idéntico**

Run: `bin/cake import_gmail --max 1`
Expected:
- Si Gmail está configurado: imprime resumen con conteos.
- Si NO está configurado: error claro `Gmail OAuth no configurado...` y exit 1.

Si la salida coincide con la del comando previo (modulo cosmético), proceder. Si crashea, debugear antes de commit.

**Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Command/ImportGmailCommand.php
git commit -m "refactor(gmail): make ImportGmailCommand delegate to GmailImportService"
```

---

# Stage 2 — Setting `WEBHOOK_GMAIL_IMPORT_TOKEN`

Crea el storage cifrado del shared secret y la migration que lo siembra.

---

### Task 2.1: Añadir constante `WEBHOOK_GMAIL_IMPORT_TOKEN`

**Files:**
- Modify: `src/Utility/SettingKeys.php:36` (final del archivo, antes del `}`)

**Step 1: Editar `SettingKeys.php`**

Justo después del bloque `// ── n8n` (línea 30-35), añadir un nuevo bloque antes del `}` de cierre de clase:

```php
    // ── Webhooks ────────────────────────────────────────────────────────
    public const WEBHOOK_GMAIL_IMPORT_TOKEN = 'webhook_gmail_import_token';
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Utility/SettingKeys.php`
Expected: `No syntax errors detected ...`

**Step 3: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Utility/SettingKeys.php
git commit -m "feat(settings): add WEBHOOK_GMAIL_IMPORT_TOKEN setting key"
```

---

### Task 2.2: Marcar el token como cifrado at-rest

`SettingsEncryptionTrait::$encryptedSettings` (línea 25-29) define qué keys se cifran al guardarse.

**Files:**
- Modify: `src/Utility/SettingsEncryptionTrait.php:25-29`

**Step 1: Añadir la nueva key al array**

Cambiar:

```php
    private array $encryptedSettings = [
        SettingKeys::GMAIL_REFRESH_TOKEN,
        SettingKeys::WHATSAPP_API_KEY,
        SettingKeys::N8N_API_KEY,
    ];
```

por:

```php
    private array $encryptedSettings = [
        SettingKeys::GMAIL_REFRESH_TOKEN,
        SettingKeys::WHATSAPP_API_KEY,
        SettingKeys::N8N_API_KEY,
        SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN,
    ];
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Utility/SettingsEncryptionTrait.php`
Expected: `No syntax errors detected ...`

**Step 3: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Utility/SettingsEncryptionTrait.php
git commit -m "feat(settings): encrypt WEBHOOK_GMAIL_IMPORT_TOKEN at-rest"
```

---

### Task 2.3: Crear migration que siembra un token random

La migration genera un token de 64 hex chars, lo cifra usando el mismo formato que `SettingsEncryptionTrait` (sin importar el trait — duplicamos la lógica mínima dentro de la migration porque Phinx no tiene acceso al ORM ni a traits del namespace App durante runtime).

**Files:**
- Create: `config/Migrations/<timestamp>_AddGmailWebhookToken.php`

**Step 1: Generar el archivo de migration con bake**

Run: `bin/cake bake migration AddGmailWebhookToken`
Expected: crea `config/Migrations/YYYYMMDDHHMMSS_AddGmailWebhookToken.php` con esqueleto vacío.

**Step 2: Reemplazar el contenido generado**

Editar el archivo recién creado y reemplazar todo su contenido con:

```php
<?php
declare(strict_types=1);

use Cake\Utility\Security;
use Migrations\AbstractMigration;

/**
 * Genera y persiste el shared secret usado por POST /webhooks/gmail/import.
 *
 * El token es 64 hex chars, cifrado con Security::encrypt() y prefijo
 * '{encrypted}' (mismo formato que SettingsEncryptionTrait::encryptSetting).
 */
final class AddGmailWebhookToken extends AbstractMigration
{
    private const SETTING_KEY = 'webhook_gmail_import_token';

    public function up(): void
    {
        // No-op si ya existe (idempotente: facilita re-run en entornos compartidos)
        $existing = $this->fetchRow(
            "SELECT id FROM system_settings WHERE setting_key = '" . self::SETTING_KEY . "' LIMIT 1"
        );
        if ($existing) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $encrypted = '{encrypted}' . base64_encode(Security::encrypt($token, Security::getSalt()));

        $now = date('Y-m-d H:i:s');
        $this->table('system_settings')->insert([
            [
                'setting_key' => self::SETTING_KEY,
                'setting_value' => $encrypted,
                'setting_type' => 'string',
                'description' => 'Shared secret para POST /webhooks/gmail/import (n8n)',
                'created' => $now,
                'modified' => $now,
            ],
        ])->save();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING_KEY . "'");
    }
}
```

> **Nota:** la migration usa `Security::getSalt()`, que requiere `SECURITY_SALT` configurado en el entorno. Eso ya es prerrequisito del proyecto.

**Step 3: Verificar sintaxis**

Run: `php -l config/Migrations/*_AddGmailWebhookToken.php`
Expected: `No syntax errors detected ...`

**Step 4: Verificación manual — aplicar y rollback**

Run: `bin/cake migrations migrate`
Expected: status reporta la nueva migration aplicada sin errores.

Verificar el setting en BD:

Run: `bin/cake migrations status`
Expected: la migration aparece con estado `up`.

Probar que el SettingsService lo desencripta:

Run: `bin/cake import_gmail --max 0` (cualquier cmd que toque settings) — no debe romperse por el nuevo registro.

Probar rollback:

Run: `bin/cake migrations rollback`
Expected: la fila desaparece. Re-aplicar con `bin/cake migrations migrate`.

**Step 5: Commit**

```bash
composer cs-fix && composer cs-check
git add config/Migrations/*_AddGmailWebhookToken.php
git commit -m "feat(settings): seed encrypted webhook token via migration"
```

---

# Stage 3 — WebhooksController + routing + middleware

Stage que expone el endpoint HTTP. Sin esto el lock + auth no son verificables.

---

### Task 3.1: Crear `WebhooksController` (stub que responde 200 OK)

Stub primero — permite probar routing/CSRF/auth en aislamiento antes de añadir lógica.

**Files:**
- Create: `src/Controller/WebhooksController.php`

**Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;

/**
 * Endpoints HTTP disparados por sistemas externos (n8n principalmente).
 *
 * Hereda de Controller (no AppController) para evitar Authentication
 * Component, FormProtection, Flash y carga de settings en beforeFilter.
 * La autenticación se hace por shared secret en header X-Webhook-Token.
 */
final class WebhooksController extends Controller
{
    public function gmailImport(): Response
    {
        $this->request->allowMethod(['POST']);

        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => true, 'stub' => true], JSON_THROW_ON_ERROR));
    }
}
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Controller/WebhooksController.php`
Expected: `No syntax errors detected ...`

**Step 3: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Controller/WebhooksController.php
git commit -m "feat(webhooks): add WebhooksController stub"
```

---

### Task 3.2: Conectar la ruta `POST /webhooks/gmail/import`

**Files:**
- Modify: `config/routes.php:52-77` (dentro del scope `/`)

**Step 1: Añadir un scope `/webhooks` independiente**

Justo antes del cierre `});` del callback principal de `Router` (después de la llave de cierre del scope `/`, alrededor de línea 77), añadir:

```php
    $routes->scope('/webhooks', function (RouteBuilder $builder): void {
        $builder->setExtensions(['json']);
        $builder->post(
            '/gmail/import',
            ['controller' => 'Webhooks', 'action' => 'gmailImport'],
            'webhook_gmail_import'
        );
    });
```

**Step 2: Verificar sintaxis**

Run: `php -l config/routes.php`
Expected: `No syntax errors detected ...`

**Step 3: Verificación manual — la ruta resuelve**

Listar rutas:
Run: `bin/cake routes | grep webhooks`
Expected: una línea con `POST /webhooks/gmail/import`.

Llamar el stub (debe fallar por CSRF aún — eso valida que la ruta llega):
Run: `curl -i -X POST http://localhost:8765/webhooks/gmail/import`
Expected: respuesta 403 CSRF o similar (no 404).

**Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add config/routes.php
git commit -m "feat(webhooks): route POST /webhooks/gmail/import"
```

---

### Task 3.3: Excluir `/webhooks/*` del CSRF middleware

**Files:**
- Modify: `src/Application.php:91-95`

**Step 1: Reemplazar el bloque CSRF**

Cambiar:

```php
            // Cross Site Request Forgery (CSRF) Protection Middleware
            // https://book.cakephp.org/5/en/security/csrf.html#cross-site-request-forgery-csrf-middleware
            ->add(new CsrfProtectionMiddleware([
                'httponly' => true,
            ]))
```

por:

```php
            // Cross Site Request Forgery (CSRF) Protection Middleware
            // https://book.cakephp.org/5/en/security/csrf.html#cross-site-request-forgery-csrf-middleware
            ->add((new CsrfProtectionMiddleware([
                'httponly' => true,
            ]))->skipCheckCallback(static function ($request): bool {
                return str_starts_with($request->getUri()->getPath(), '/webhooks/');
            }))
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Application.php`
Expected: `No syntax errors detected ...`

**Step 3: Verificación manual — el stub responde 200**

Run: `curl -i -X POST http://localhost:8765/webhooks/gmail/import`
Expected: `HTTP/1.1 200 OK` con body `{"ok":true,"stub":true}`.

**Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Application.php
git commit -m "feat(webhooks): skip CSRF for /webhooks/* paths"
```

---

### Task 3.4: Implementar verificación de token

**Files:**
- Modify: `src/Controller/WebhooksController.php`

**Step 1: Añadir verificación de token al stub**

Reemplazar contenido completo del archivo:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\SettingsService;
use App\Utility\SettingKeys;
use Cake\Controller\Controller;
use Cake\Http\Response;

final class WebhooksController extends Controller
{
    public function gmailImport(): Response
    {
        $this->request->allowMethod(['POST']);

        if (!$this->verifyToken()) {
            return $this->jsonError(401, 'invalid_token');
        }

        // TODO Task 3.5: lock + ratelimit + servicio
        return $this->jsonOk(['stub' => true]);
    }

    private function verifyToken(): bool
    {
        $provided = (string)$this->request->getHeaderLine('X-Webhook-Token');
        if ($provided === '') {
            return false;
        }

        $settings = (new SettingsService())->loadAll();
        $expected = $settings[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? null;

        return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonOk(array $body): Response
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => true] + $body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function jsonError(int $code, string $error, array $extra = []): Response
    {
        return $this->response
            ->withStatus($code)
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => false, 'error' => $error] + $extra, JSON_THROW_ON_ERROR));
    }
}
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Controller/WebhooksController.php`
Expected: `No syntax errors detected ...`

**Step 3: Verificación manual — token correcto vs incorrecto**

Obtener el token desde la BD (descifrado):

Run en MySQL:
```sql
SELECT setting_value FROM system_settings WHERE setting_key = 'webhook_gmail_import_token';
```

(El valor está cifrado, prefijo `{encrypted}`. Para obtener el plaintext, consultar UI cuando esté lista en Stage 4 o usar un one-shot tinker:)

Run: `bin/cake server` en otra terminal, luego:
```bash
php -r 'require "vendor/autoload.php"; require "config/bootstrap.php"; $s = (new App\Service\SettingsService())->loadAll(); echo $s["webhook_gmail_import_token"] . PHP_EOL;'
```

Sin token:
Run: `curl -i -X POST http://localhost:8765/webhooks/gmail/import`
Expected: 401 con `{"ok":false,"error":"invalid_token"}`.

Con token correcto:
Run: `curl -i -X POST -H "X-Webhook-Token: <plaintext>" http://localhost:8765/webhooks/gmail/import`
Expected: 200 con `{"ok":true,"stub":true}`.

**Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Controller/WebhooksController.php
git commit -m "feat(webhooks): verify shared secret via X-Webhook-Token header"
```

---

### Task 3.5: Añadir lock con flock + rate limit + invocar servicio

**Files:**
- Modify: `src/Controller/WebhooksController.php`

**Step 1: Reemplazar el archivo completo con versión final**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Exception\GmailNotConfiguredException;
use App\Service\GmailImportService;
use App\Service\SettingsService;
use App\Utility\SettingKeys;
use Cake\Cache\Cache;
use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Log\Log;

final class WebhooksController extends Controller
{
    private const LOCK_FILENAME = 'gmail_import.lock';
    private const RATE_LIMIT_KEY = 'gmail_import_last_run';
    private const RATE_LIMIT_CACHE = 'default';
    private const MIN_INTERVAL_SECONDS = 60;
    private const REQUEST_TIME_LIMIT = 300;

    public function gmailImport(): Response
    {
        $this->request->allowMethod(['POST']);

        if (!$this->verifyToken()) {
            return $this->jsonError(401, 'invalid_token');
        }

        if ($this->ranRecently()) {
            return $this->jsonError(429, 'too_soon', [
                'retry_after_seconds' => self::MIN_INTERVAL_SECONDS,
            ]);
        }

        $lock = $this->acquireFileLock();
        if ($lock === null) {
            return $this->jsonError(409, 'already_running');
        }

        @set_time_limit(self::REQUEST_TIME_LIMIT);
        ignore_user_abort(true);

        try {
            $service = GmailImportService::fromSettings();
            $max = (int)($this->request->getData('max') ?? 50);
            $query = (string)($this->request->getData('query') ?? 'is:unread');

            $result = $service->run($max, $query, 0);

            Cache::write(self::RATE_LIMIT_KEY, time(), self::RATE_LIMIT_CACHE);

            return $this->jsonOk($result->toArray());
        } catch (GmailNotConfiguredException $e) {
            return $this->jsonError(503, 'not_configured');
        } catch (\Throwable $e) {
            Log::error('Gmail webhook import failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return $this->jsonError(500, 'import_failed');
        } finally {
            $this->releaseFileLock($lock);
        }
    }

    private function verifyToken(): bool
    {
        $provided = (string)$this->request->getHeaderLine('X-Webhook-Token');
        if ($provided === '') {
            return false;
        }

        $settings = (new SettingsService())->loadAll();
        $expected = $settings[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? null;

        return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
    }

    private function ranRecently(): bool
    {
        $last = (int)(Cache::read(self::RATE_LIMIT_KEY, self::RATE_LIMIT_CACHE) ?? 0);

        return $last > 0 && (time() - $last) < self::MIN_INTERVAL_SECONDS;
    }

    /**
     * Adquiere lock exclusivo no bloqueante sobre tmp/gmail_import.lock.
     *
     * @return resource|null Handle abierto si se obtuvo el lock, null si está ocupado
     */
    private function acquireFileLock()
    {
        $path = TMP . self::LOCK_FILENAME;
        $fp = @fopen($path, 'c');
        if ($fp === false) {
            Log::error('Gmail webhook: cannot open lock file', ['path' => $path]);

            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return null;
        }

        return $fp;
    }

    /**
     * @param resource|null $fp
     */
    private function releaseFileLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonOk(array $body): Response
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => true] + $body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function jsonError(int $code, string $error, array $extra = []): Response
    {
        return $this->response
            ->withStatus($code)
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => false, 'error' => $error] + $extra, JSON_THROW_ON_ERROR));
    }
}
```

**Step 2: Verificar sintaxis**

Run: `php -l src/Controller/WebhooksController.php`
Expected: `No syntax errors detected ...`

**Step 3: Verificación manual — flujo completo**

Caso 1 — éxito básico:
Run: `curl -i -X POST -H "X-Webhook-Token: <plaintext>" -H "Content-Type: application/json" -d '{"max":1}' http://localhost:8765/webhooks/gmail/import`
Expected: 200 con JSON: `{"ok":true,"fetched":...,"created":...,"comments":...,"skipped":...,"errors":...,"duration_seconds":...,"error_messages":[]}`.

Caso 2 — rate limit (correr el comando dos veces seguidas):
Expected: la segunda devuelve 429 con `retry_after_seconds: 60`.

Caso 3 — lock concurrente (en dos terminales simultáneamente, después de esperar el rate limit):
Expected: una corrida devuelve 200, la otra 409 `already_running`.

Caso 4 — token vacío:
Run: `curl -i -X POST http://localhost:8765/webhooks/gmail/import`
Expected: 401 `invalid_token`.

Caso 5 — Gmail no configurado: temporalmente vaciar `gmail_refresh_token` en BD y probar:
Expected: 503 `not_configured`. Restaurar la fila después.

**Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Controller/WebhooksController.php
git commit -m "feat(webhooks): wire GmailImportService with flock and rate limit"
```

---

### Task 3.6: Subir timeouts en Nginx para `/webhooks/*`

**Files:**
- Modify: `docker/nginx/standalone.conf`

**Step 1: Añadir un location bloque dedicado**

Insertar este bloque **antes** del bloque `location ~ \.php$` actual (línea 22):

```nginx
    # Webhooks endpoints — long-running imports (Gmail webhook puede tardar hasta 5 min)
    location ~ ^/webhooks/ {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ ^/webhooks/.*\.php$ {
        # No aplica directamente — webhooks van por /index.php
    }
```

Y modificar el `location ~ \.php$` existente para incrementar `fastcgi_read_timeout` solo cuando el script es webhook. La forma más simple: subir el timeout global del block PHP de `300` a `360`. Cambiar:

```nginx
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
```

por:

```nginx
        fastcgi_read_timeout 360;
        fastcgi_send_timeout 360;
```

> **Nota:** se sube el timeout para todos los endpoints PHP-FPM. Es aceptable para un helpdesk interno; si hubiera problemas de slowloris, se segmentaría con `fastcgi_param REQUEST_URI ...` o un upstream separado.

**Step 2: Verificar sintaxis nginx**

Run: `docker compose exec web nginx -t`
Expected: `nginx: configuration file ... test is successful`.

(Si Docker no está corriendo, omitir esta verificación; se valida en deploy.)

**Step 3: Commit**

```bash
git add docker/nginx/standalone.conf
git commit -m "chore(docker): raise fastcgi timeout to 360s for long imports"
```

---

### Task 3.7: Subir `request_terminate_timeout` en PHP-FPM

**Files:**
- Modify: `docker/php/php.ini`

**Step 1: Inspeccionar el archivo**

Run: `cat docker/php/php.ini` (o `Read`).

**Step 2: Editar el setting**

Si existe `max_execution_time`, subirlo a `360`. Si existe `request_terminate_timeout` (en el php-fpm pool conf, no `php.ini`), subirlo a `360`.

Si solo hay `php.ini` (sin pool conf separado), añadir o ajustar:

```ini
max_execution_time = 360
```

> **Nota:** `request_terminate_timeout` usualmente vive en `www.conf` del pool. Verificar dentro del Dockerfile / `docker/php/` qué archivos están montados. Si no existe, no se requiere acción adicional — el default suele ser ilimitado y `set_time_limit(300)` en el controller maneja el caso PHP.

**Step 3: Commit**

```bash
git add docker/php/php.ini
git commit -m "chore(docker): raise PHP max_execution_time for webhook imports"
```

---

# Stage 4 — UI del token en Settings

Permite al admin ver/copiar/regenerar el token sin tocar la BD.

---

### Task 4.1: Exponer token y URL al view de Settings

**Files:**
- Modify: `src/Controller/Admin/SettingsController.php:59-95`

**Step 1: Pasar el token y la URL al view**

Justo antes de `$this->set('settings', $this->settingsService->loadAll());` (línea 94), añadir:

```php
        $allSettings = $this->settingsService->loadAll();
        $webhookToken = $allSettings[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? '';
        $webhookUrl = \Cake\Routing\Router::url(['_name' => 'webhook_gmail_import'], true);
        $lastWebhookRun = (int)(\Cake\Cache\Cache::read('gmail_import_last_run', 'default') ?? 0);

        $this->set([
            'settings' => $allSettings,
            'webhookGmailToken' => $webhookToken,
            'webhookGmailUrl' => $webhookUrl,
            'webhookGmailLastRun' => $lastWebhookRun > 0 ? date('Y-m-d H:i:s', $lastWebhookRun) : null,
        ]);

        return;
```

Y eliminar el `$this->set('settings', $this->settingsService->loadAll());` antiguo de la línea 94.

> **Nota:** dejar el `return;` explícito porque la acción `index` sin POST cae al render por defecto.

**Step 2: Añadir acción `regenerateWebhookToken`**

Después de la acción `gmailAuth` (al final del controller, antes del `}` de cierre), añadir:

```php
    /**
     * Regenera el shared secret del webhook de Gmail.
     */
    public function regenerateWebhookToken(): \Cake\Http\Response
    {
        $this->request->allowMethod(['POST']);

        $token = bin2hex(random_bytes(32));
        $saved = $this->settingsService->saveSetting(SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN, $token);

        if ($saved) {
            $this->Flash->success('Token de webhook regenerado. Actualiza la credencial en n8n.');
        } else {
            $this->Flash->error('No se pudo regenerar el token.');
        }

        return $this->redirect(['action' => 'index']);
    }
```

**Step 3: Permitir la acción en `FormProtection`**

En `beforeFilter` (línea 43-45) añadir `'regenerateWebhookToken'` al array `unlockedActions`:

```php
        $this->FormProtection->setConfig('unlockedActions', [
            'index', 'gmailAuth', 'testWhatsapp', 'regenerateWebhookToken',
        ]);
```

> **Nota:** la acción usa POST estándar de Cake con CSRF (heredado de AppController), no es webhook.

**Step 4: Verificar sintaxis**

Run: `php -l src/Controller/Admin/SettingsController.php`
Expected: `No syntax errors detected ...`

**Step 5: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Controller/Admin/SettingsController.php
git commit -m "feat(admin): expose webhook token and add regenerate action"
```

---

### Task 4.2: Sección "Webhooks" en `templates/Admin/Settings/index.php`

**Files:**
- Modify: `templates/Admin/Settings/index.php` (al final de la página, antes del cierre del último contenedor de secciones)

**Step 1: Localizar el lugar de inserción**

Run: `grep -n 'n8n\|N8N\|whatsapp' templates/Admin/Settings/index.php | tail -10`

Identificar el bloque de la última sección (probablemente n8n o WhatsApp) y añadir la nueva sección justo después de su `</div>` de cierre.

**Step 2: Añadir la sección**

Insertar (sustituye `<!-- AQUÍ -->` por el lugar identificado):

```php
<!-- ── Sección Webhooks (Gmail import) ─────────────────────────── -->
<div class="settings-section">
    <h4>Webhooks — Gmail Import</h4>
    <p class="settings-description">
        Endpoint disparado por n8n para importar correos. Reemplaza al worker continuo.
    </p>

    <div class="setting-row">
        <label>URL del webhook</label>
        <code class="webhook-url"><?= h($webhookGmailUrl) ?></code>
    </div>

    <div class="setting-row">
        <label>Token (X-Webhook-Token)</label>
        <div class="webhook-token-field">
            <input type="password"
                   id="webhook-gmail-token"
                   value="<?= h($webhookGmailToken) ?>"
                   readonly
                   style="width: 60ch; font-family: monospace;">
            <button type="button" onclick="document.getElementById('webhook-gmail-token').type = (document.getElementById('webhook-gmail-token').type === 'password' ? 'text' : 'password')">
                Mostrar / ocultar
            </button>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhook-gmail-token').value)">
                Copiar
            </button>
        </div>
    </div>

    <div class="setting-row">
        <label>Última ejecución</label>
        <span><?= $webhookGmailLastRun ? h($webhookGmailLastRun) : '— sin registros —' ?></span>
    </div>

    <div class="setting-row">
        <?= $this->Form->postLink(
            'Regenerar token',
            ['action' => 'regenerateWebhookToken'],
            [
                'class' => 'btn btn-warning',
                'confirm' => '¿Seguro? El token actual dejará de funcionar inmediatamente; deberás actualizarlo en n8n.',
            ]
        ) ?>
    </div>
</div>
```

> **Nota:** el estilo CSS usa las clases existentes en `templates/Admin/Settings/index.php` (variables `--admin-*`). Si el template usa nombres distintos, ajustar los selectores `.settings-section`, `.setting-row`, etc., al match real (ver líneas 1-80 del archivo para conocer las convenciones).

**Step 3: Verificación manual — la UI carga**

Run: `bin/cake server` en otra terminal.

Login como admin → navegar a `/admin/settings`. Verificar:
- Sección "Webhooks — Gmail Import" visible al final.
- Campo del token oculto por defecto (input type=password).
- Botón "Mostrar / ocultar" alterna visibilidad.
- Botón "Copiar" copia al clipboard.
- Botón "Regenerar token" muestra confirm; al aceptar, el token cambia y aparece flash success.
- Tras invocar el webhook con `curl`, el campo "Última ejecución" se actualiza en el siguiente refresh.

**Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add templates/Admin/Settings/index.php
git commit -m "feat(admin): add Webhooks section with token management UI"
```

---

# Stage 5 — Workflow n8n (fuera del repo)

Este stage no toca código del proyecto. Documenta los pasos manuales en n8n.

---

### Task 5.1: Crear el workflow en n8n

**Pasos manuales en la UI de n8n:**

1. Crear nuevo workflow: "Mesa de Ayuda — Gmail Import Trigger".
2. Añadir nodo **Schedule Trigger**: cada 5 min.
3. Añadir nodo **HTTP Request**:
   - Method: `POST`
   - URL: `${FULL_BASE_URL}/webhooks/gmail/import` (e.g. `https://mesa.dominio.local/webhooks/gmail/import`)
   - Headers: `X-Webhook-Token: ={{ $env.GMAIL_WEBHOOK_TOKEN }}`
   - Body (JSON): `{"max": 50, "query": "is:unread"}`
   - Timeout: 300000 ms
   - Retry on fail: 0 (el lock + ratelimit ya manejan retries)
   - Continue on fail: ON (para no romper el workflow en 429/409)
4. Añadir nodo **IF**: condición `{{ $json.ok === true || $json.error === 'too_soon' || $json.error === 'already_running' }}`. La rama TRUE termina silenciosa (NoOp).
5. La rama FALSE va a un nodo **Slack** (o Email) con mensaje:
   `Gmail webhook import falló: {{ $json.error }}. Detalles: {{ JSON.stringify($json) }}`
6. Guardar el token (plaintext) como **Credential** o variable `GMAIL_WEBHOOK_TOKEN` del entorno n8n. **No hardcoded.**
7. Activar el workflow.

**Verificación:**
- Tras 5 min, ver en n8n executions: una corrida exitosa (200) o un 429/409 (esperado si hay otra corrida activa o reciente).
- Comparar contadores con `bin/cake import_gmail` corrido manualmente — deben coincidir.

**Step 1: Documentar en `docs/`**

Crear `docs/operations/n8n-gmail-webhook.md` con un dump del workflow exportado desde n8n (Export → Download).

**Step 2: Commit**

```bash
git add docs/operations/n8n-gmail-webhook.md
git commit -m "docs(ops): document n8n workflow for Gmail webhook trigger"
```

---

# Stage 6 — Validación paralela (1 semana)

**Sin cambios de código.** El worker sigue corriendo en compose. Métricas a comparar diariamente:

- `docker logs mesadeayuda_worker --since 24h | grep 'Import completed'`
- Logs de n8n del workflow.
- Conteos en `tickets` table por `gmail_message_id` único.

**Criterios para avanzar a Stage 7:**

- ≥ 5 corridas consecutivas del webhook con `errors=0`.
- No hay tickets duplicados creados (mismo `gmail_message_id`).
- Latencia p95 del endpoint < 60s.
- Sin alertas críticas (401/500/503).

Si algún criterio falla, debugear antes de avanzar.

---

# Stage 7 — Apagar y eliminar el worker

Una vez validado, eliminar código y servicios obsoletos.

---

### Task 7.1: Comentar el servicio worker en docker-compose

**Files:**
- Modify: `docker-compose.yml:39-76`

**Step 1: Comentar todas las líneas del bloque `worker:`**

Cambiar las líneas 39-76 (todo el servicio `worker`) a comentarios `#` precediendo cada línea. Mantener el archivo válido sintácticamente (YAML).

**Step 2: Verificación manual**

Run: `docker compose config`
Expected: el output ya no incluye el servicio `worker`. El servicio `web` sigue válido.

**Step 3: Verificar que la red `copcsa-networks` ya no es necesaria**

Si solo el worker la usaba, comentar también el bloque `networks: copcsa: ...` (línea 78-81).

**Step 4: Bajar el contenedor del worker**

Run: `docker compose down worker`
Expected: el contenedor `mesadeayuda_worker` se detiene y elimina. `web` sigue corriendo.

**Step 5: Commit**

```bash
git add docker-compose.yml
git commit -m "chore(docker): disable worker service (replaced by webhook)"
```

---

### Task 7.2: Borrar `GmailWorkerCommand`

**Files:**
- Delete: `src/Command/GmailWorkerCommand.php`

**Step 1: Borrar archivo**

Run: `git rm src/Command/GmailWorkerCommand.php`

**Step 2: Borrar trigger file si existe**

Run: `[ -f tmp/gmail_worker_trigger ] && rm tmp/gmail_worker_trigger; true`

**Step 3: Buscar referencias huérfanas**

Run: `grep -r 'GmailWorkerCommand\|gmail_worker\|WORKER_ENABLED\|TRIGGER_FILE' src/ config/ docs/ 2>&1 | grep -v '\.lock'`
Expected: cero matches en `src/` y `config/`. Pueden quedar referencias en `docs/` históricos.

Si hay matches en código, eliminarlos (probablemente solo en `CLAUDE.md` y `docs/audits/`).

**Step 4: Verificación manual**

Run: `bin/cake` (sin args, lista comandos)
Expected: `gmail_worker` ya no aparece. `import_gmail` sí.

**Step 5: Commit**

```bash
composer cs-fix && composer cs-check
git add -A
git commit -m "chore(gmail): remove GmailWorkerCommand (replaced by webhook)"
```

---

### Task 7.3: Eliminar variable `WORKER_ENABLED` del compose y env templates

**Files:**
- Modify: `docker-compose.yml` (si quedó en el bloque web por accidente)
- Modify: `.env.example` (si existe)
- Modify: `config/app_local.example.php` (si referencia `WORKER_ENABLED`)

**Step 1: Buscar y eliminar referencias**

Run: `grep -rn 'WORKER_ENABLED' . --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git`

Para cada match, eliminar la línea completa.

**Step 2: Commit**

```bash
git add -A
git commit -m "chore(env): remove WORKER_ENABLED variable references"
```

---

### Task 7.4: Actualizar CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

**Step 1: Eliminar referencias al worker**

Buscar y eliminar/actualizar:
- Línea sobre `bin/cake gmail_worker` (sección Common commands).
- Mención del servicio `worker` en la sección Docker topology.
- Referencias a `WORKER_ENABLED`.

Reemplazar con una línea sobre el webhook:

> Gmail import: triggered by n8n via `POST /webhooks/gmail/import` (shared secret in `webhook_gmail_import_token` setting). The CLI command `bin/cake import_gmail` is preserved for manual debug.

**Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md to reflect webhook-driven Gmail import"
```

---

# Verificación final del plan

Después de completar Stage 7:

- `bin/cake import_gmail --max 1` ✔ funciona (debug CLI).
- `curl -X POST -H "X-Webhook-Token: ..." http://host/webhooks/gmail/import` ✔ retorna 200 con conteos.
- `docker compose ps` ✔ solo muestra `web` (sin `worker`).
- `grep -rn 'GmailWorkerCommand\|WORKER_ENABLED' src/ config/` ✔ cero matches.
- UI `/admin/settings` ✔ muestra sección Webhooks operativa.

---

# Riesgos críticos del plan

1. **Stage 1.4 — pérdida de comportamiento por refactor:** la lógica del comando es compleja (filtros 1-4, threading, mark-as-read en error vs success). El plan replica línea a línea pero puede haber sutilezas. **Mitigación:** comparar `bin/cake import_gmail --max 5` antes y después del refactor.

2. **Stage 2.3 — `Security::getSalt()` en migración:** funciona durante `bin/cake migrations migrate` porque carga bootstrap, pero si la migration corre en un contexto sin `SECURITY_SALT`, falla con excepción clara. **Mitigación:** el setting es idempotente (no-op si ya existe); en deploy usar siempre el mismo entorno.

3. **Stage 3.5 — `flock()` en NFS o sistemas de archivos en red:** no es atómico. **Mitigación:** este proyecto monta `tmp/` como volume Docker local — OK. Documentar limitación si en futuro se mueve a NFS.

4. **Stage 7.1 — pérdida de procesos en flight:** apagar el worker mientras procesa un mensaje puede dejar Gmail con un mensaje sin marcar como leído. **Mitigación:** ejecutar `docker compose stop worker` (no `down`) durante baja carga (madrugada).
