# Diseño — Reemplazar worker Gmail por webhook disparado por n8n

**Fecha:** 2026-05-04
**Estado:** Diseño aprobado, pendiente plan de implementación
**Autor:** Alexander
**Contexto:** Code review previo (`docs/audits/` — Gmail import module) identificó que el worker requiere arranque manual, no se inicia automáticamente y, si crashea, debe reiniciarse a mano. Este documento propone reemplazarlo por un endpoint HTTP disparado por n8n (que ya forma parte del stack de integraciones).

---

## 1. Objetivo

Eliminar el contenedor `worker` y `GmailWorkerCommand`. Sustituir el ciclo continuo por:

- Un endpoint `POST /webhooks/gmail/import` en el contenedor `web`.
- Un workflow de n8n que lo dispare cada N minutos vía Schedule Trigger.

El comando CLI `bin/cake import_gmail` se conserva como herramienta de debug manual.

---

## 2. Visión general

```
┌──────────────────┐        ┌───────────────────────────────────────┐
│  n8n             │        │  Mesa de Ayuda (contenedor `web`)     │
│                  │        │                                       │
│ Schedule Trigger │ ──────►│ POST /webhooks/gmail/import           │
│   cada 5 min     │  HTTP  │   ├─ verifica X-Webhook-Token         │
│                  │  +     │   ├─ adquiere lock (Cache::add)       │
│ HTTP Request     │ token  │   ├─ GmailImportService::run()        │
│                  │        │   └─ JSON {created, comments, ...}    │
│ Error branch     │        │                                       │
│   → notificar    │        │  bin/cake import_gmail (debug manual) │
└──────────────────┘        └───────────────────────────────────────┘

Eliminado: contenedor `worker`, GmailWorkerCommand, WORKER_ENABLED, TRIGGER_FILE
```

---

## 3. Componentes

### 3.1 Servicio `GmailImportService` (extraído de `ImportGmailCommand`)

**Problema actual:** `ImportGmailCommand::execute()` mezcla orquestación con `ConsoleIo`. Hay que poder llamarlo desde un controlador HTTP sin tocar la consola.

**Archivo nuevo:** `src/Service/GmailImportService.php`

```php
final class GmailImportService
{
    public function __construct(
        private readonly GmailService $gmail,
        private readonly TicketService $tickets,
        private readonly TicketsTable $ticketsTable,
        private readonly LoggerInterface $log,
    ) {}

    public static function fromSettings(): self
    {
        $config = GmailService::loadConfigFromDatabase();
        if (empty($config['refresh_token'])) {
            throw new GmailNotConfiguredException();
        }
        $systemConfig = SettingsService::all(); // ya decryptado
        return new self(
            new GmailService($config),
            new TicketService($systemConfig),
            (new TableLocator())->get('Tickets'),
            Log::engine('default'),
        );
    }

    public function run(int $max = 50, string $query = 'is:unread', int $delayMs = 0): GmailImportResult
    {
        // exactamente la lógica de ImportGmailCommand::execute líneas 95-247,
        // pero retornando GmailImportResult en vez de imprimir a $io
    }
}
```

**Archivo nuevo:** `src/Service/Dto/GmailImportResult.php`

```php
final readonly class GmailImportResult
{
    public function __construct(
        public int $fetched,
        public int $created,
        public int $comments,
        public int $skipped,
        public int $errors,
        public float $durationSeconds,
        public array $errorMessages = [],
    ) {}

    public function toArray(): array { /* ... */ }
}
```

**Refactor de `ImportGmailCommand`** queda en ~30 líneas: parsea opciones, llama `GmailImportService::run()`, imprime resultado. Se mantiene para debug CLI:

```bash
bin/cake import_gmail --max 5 --query 'subject:test'
```

---

### 3.2 Webhook controller

**Archivo nuevo:** `src/Controller/WebhooksController.php`

```php
final class WebhooksController extends AppController
{
    private const LOCK_KEY = 'gmail_import_lock';
    private const RATE_LIMIT_KEY = 'gmail_import_last_run';
    private const MIN_INTERVAL_SECONDS = 60;

    public function initialize(): void
    {
        parent::initialize();
        // Sin auth de sesión — usamos shared secret
        $this->Authentication->allowUnauthenticated(['gmailImport']);
    }

    public function gmailImport()
    {
        $this->request->allowMethod(['POST']);

        // 1. Verificar shared secret
        if (!$this->verifyToken()) {
            return $this->jsonError(401, 'invalid_token');
        }

        // 2. Rate limit: evita doble-trigger accidental
        if ($this->ranRecently()) {
            return $this->jsonError(429, 'too_soon', [
                'retry_after_seconds' => self::MIN_INTERVAL_SECONDS,
            ]);
        }

        // 3. Lock concurrente: si una corrida sigue activa, salimos limpio
        if (!Cache::add(self::LOCK_KEY, time(), 'long')) {
            return $this->jsonError(409, 'already_running');
        }

        // 4. Timeout permisivo (n8n default 5 min)
        set_time_limit(300);
        ignore_user_abort(true);

        try {
            $service = GmailImportService::fromSettings();
            $max = (int)($this->request->getData('max') ?? 50);
            $query = (string)($this->request->getData('query') ?? 'is:unread');

            $result = $service->run(min($max, 200), $query);

            Cache::write(self::RATE_LIMIT_KEY, time(), 'long');

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
            Cache::delete(self::LOCK_KEY, 'long');
        }
    }

    private function verifyToken(): bool
    {
        $provided = (string)$this->request->getHeaderLine('X-Webhook-Token');
        if ($provided === '') return false;
        $expected = SettingsService::get(SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN);
        return $expected !== null && hash_equals($expected, $provided);
    }

    private function ranRecently(): bool
    {
        $last = (int)(Cache::read(self::RATE_LIMIT_KEY, 'long') ?? 0);
        return ($last > 0) && (time() - $last) < self::MIN_INTERVAL_SECONDS;
    }

    private function jsonOk(array $body): \Cake\Http\Response { /* ... */ }
    private function jsonError(int $code, string $error, array $extra = []): \Cake\Http\Response { /* ... */ }
}
```

**Códigos HTTP:**

| Código | Significado | Acción esperada en n8n |
|--------|-------------|------------------------|
| 200 | Import ejecutado (puede tener `errors > 0`) | Continuar; opcionalmente alertar si `errors > threshold` |
| 401 | Token inválido | Alerta crítica |
| 429 | Última corrida hace <60s | Ignorar (no es error) |
| 409 | Otra corrida en progreso | Ignorar (no es error) |
| 503 | Gmail OAuth no configurado | Alerta de configuración |
| 500 | Error fatal | Alerta crítica |

---

### 3.3 Routes y middleware

**`config/routes.php`** — añadir scope dedicado:

```php
$routes->scope('/webhooks', function (RouteBuilder $builder): void {
    $builder->setExtensions(['json']);
    $builder->post('/gmail/import', [
        'controller' => 'Webhooks',
        'action' => 'gmailImport',
    ], 'webhook_gmail_import');
});
```

**`src/Application.php`** — excluir CSRF para `/webhooks/*`:

```php
$csrf = new CsrfProtectionMiddleware(['httponly' => true]);
$csrf->skipCheckCallback(fn($req) => str_starts_with($req->getUri()->getPath(), '/webhooks/'));
$middlewareQueue->add($csrf);
```

> **Nota auth:** `AppController` ya añade `Authentication`. Usamos `$this->Authentication->allowUnauthenticated(['gmailImport'])` en el controller en lugar de tocar el middleware global.

---

### 3.4 Shared secret

**`src/Utility/SettingKeys.php`** — añadir:

```php
public const WEBHOOK_GMAIL_IMPORT_TOKEN = 'WEBHOOK_GMAIL_IMPORT_TOKEN';
```

**`src/Utility/SettingsEncryptionTrait::shouldEncrypt()`** — incluir la nueva key (cifrado at-rest).

**Migration** `config/Migrations/YYYYMMDDHHMMSS_AddGmailWebhookToken.php`:

```php
public function up(): void
{
    $token = bin2hex(random_bytes(32)); // 64 chars hex
    // Reusar SettingsService o helper para cifrar
    $encrypted = (new SettingsEncryptionTrait())
        ->encryptSetting($token, SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN);

    $this->table('system_settings')->insert([
        'setting_key' => SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN,
        'setting_value' => $encrypted,
        'description' => 'Shared secret para POST /webhooks/gmail/import desde n8n',
        'is_encrypted' => 1,
        'created' => date('Y-m-d H:i:s'),
        'modified' => date('Y-m-d H:i:s'),
    ])->save();
}
```

**UI en `/admin/settings`** — añadir sección "Integraciones / Webhooks":

- Mostrar el token con botón **Mostrar** (oculto por defecto), **Copiar**, **Regenerar**.
- Mostrar la URL completa: `{FULL_BASE_URL}/webhooks/gmail/import`.
- Mostrar timestamp de la última ejecución (leída de `Cache::read('gmail_import_last_run')`).

---

### 3.5 Concurrency lock — requisito de Cache engine

`Cache::add()` es atómico **solo si el engine lo soporta** (Redis, Memcached). El engine `File` (default en CakePHP) **no es atómico** y podría permitir doble ejecución bajo race.

**Decisión a tomar antes de implementar:**

- **Opción A (preferida):** añadir Redis al stack. n8n probablemente ya lo usa internamente; reutilizar instancia.
- **Opción B (fallback single-host):** usar `flock()` sobre archivo en `tmp/`:

```php
private function acquireFileLock(): bool|resource
{
    $fp = fopen(TMP . 'gmail_import.lock', 'c');
    if ($fp === false) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}
```

---

### 3.6 Configuración Nginx / PHP-FPM

El endpoint puede tardar hasta 5 min. Verificar:

**`docker/nginx/conf.d/default.conf`**:
```nginx
location ~ ^/webhooks/ {
    fastcgi_read_timeout 360s;
    fastcgi_pass php-fpm:9000;
    # ... resto igual al location PHP normal
}
```

**`docker/php/php-fpm.conf`**: `request_terminate_timeout = 360s` (o más).

Sin esto, el import se trunca a los 30-60s y n8n recibe 504.

---

### 3.7 Configuración del workflow n8n

**Workflow "Gmail Import Trigger":**

| Nodo | Tipo | Configuración |
|------|------|---------------|
| 1. **Schedule** | Schedule Trigger | Every 5 minutes |
| 2. **HTTP Request** | HTTP Request | Method: POST<br>URL: `https://mesa-de-ayuda.dominio/webhooks/gmail/import`<br>Headers: `X-Webhook-Token: {{ $env.GMAIL_WEBHOOK_TOKEN }}`<br>Body JSON: `{"max": 50}`<br>Timeout: 300000 (5 min)<br>Retry on fail: 0 |
| 3. **IF** | If | `{{$json.ok}}` === `true` |
| 4a. **Success** | NoOp | (silencioso) |
| 4b. **Error** | Slack/Email | "Gmail import falló: {{$json.error}}" |

El token se guarda en n8n como **Credential** o variable de entorno (`GMAIL_WEBHOOK_TOKEN`), nunca hardcoded.

**Filtro de errores reales** en la rama de error (excluir 429/409 que son normales):

```javascript
{{ $json.ok === false && !['too_soon', 'already_running'].includes($json.error) }}
```

---

## 4. Cambios en docker-compose

**Eliminar tras periodo de validación:**
- Servicio `worker` completo.
- Variable de entorno `WORKER_ENABLED`.
- Volúmenes/redes específicas del worker (`copcsa-networks` si solo era usada por el worker).

**Mantener:**
- Servicio `web` sin cambios estructurales (solo tweaks de timeout en Nginx/FPM si aplica).

---

## 5. Archivos afectados

### Nuevos
- `src/Service/GmailImportService.php`
- `src/Service/Dto/GmailImportResult.php`
- `src/Service/Exception/GmailNotConfiguredException.php`
- `src/Controller/WebhooksController.php`
- `config/Migrations/YYYYMMDDHHMMSS_AddGmailWebhookToken.php`

### Modificados
- `src/Command/ImportGmailCommand.php` — delega al servicio (~30 líneas).
- `config/routes.php` — añadir scope `/webhooks`.
- `src/Application.php` — `skipCheckCallback` en CSRF middleware.
- `src/Utility/SettingKeys.php` — constante nueva.
- `src/Utility/SettingsEncryptionTrait.php` — incluir key en `shouldEncrypt`.
- `templates/Admin/Settings/index.php` (o equivalente) — UI del token y URL.
- `docker/nginx/conf.d/*.conf` — `fastcgi_read_timeout` para `/webhooks/*`.
- `docker/php/php-fpm.conf` — `request_terminate_timeout`.

### Eliminados (en commit posterior, tras validación)
- `src/Command/GmailWorkerCommand.php`
- Servicio `worker` en `docker-compose.yml`.
- Variable `WORKER_ENABLED` en docker-compose y `.env.example`.
- Constante `GmailWorkerCommand::TRIGGER_FILE` y archivo `tmp/gmail_worker_trigger`.

---

## 6. Plan de migración (orden de commits)

| # | Commit | Riesgo | Reversible |
|---|--------|--------|------------|
| 1 | Extraer `GmailImportService` + DTO; refactor `ImportGmailCommand` para delegar | Bajo | git revert |
| 2 | Migration + UI para `WEBHOOK_GMAIL_IMPORT_TOKEN` | Nulo | git revert + migration rollback |
| 3 | Añadir `WebhooksController`, ruta, skip CSRF, ajustes Nginx/FPM | Medio | git revert |
| 4 | Crear workflow en n8n (fuera del repo) | Nulo | desactivar workflow |
| 5 | **Validación 1 semana**: worker sigue activo en paralelo, comparar logs | — | — |
| 6 | Apagar `worker` en docker-compose (comentar, no borrar) | Bajo | descomentar |
| 7 | Borrar `GmailWorkerCommand` + servicio worker + `WORKER_ENABLED` | Bajo | git revert |

---

## 7. Trade-offs

| Aspecto | Antes (worker) | Después (webhook) |
|---------|----------------|-------------------|
| Latencia ingesta | 1-5 min (intervalo configurable) | 5 min (intervalo n8n) |
| Disponibilidad | Worker debe arrancar manual; si crashea, 0 emails | n8n + endpoint vivo siempre que `web` esté arriba |
| Costo operativo | Cron implícito + monitoreo del worker | Solo monitoreo del workflow n8n (que ya existe) |
| Idempotencia | Mismo problema (CR-001 del audit previo) | Mismo problema — **no se resuelve aquí**, requiere fix paralelo |
| Picos de carga | Worker absorbe en background | Cuenta contra PHP-FPM workers; un import largo bloquea 1 worker FPM por hasta 5 min |
| Escalabilidad horizontal | Worker no escala (lock único) | Lock distribuido en Cache; escala con réplicas web |
| Debug | `docker logs worker` | `docker logs web` + UI n8n executions |

**Punto débil real:** un import largo consume un worker FPM por minutos. Para un helpdesk interno con poco tráfico web no es problema. Si tiene picos, considerar mover el endpoint a un PHP-FPM pool separado o aceptar la limitación.

---

## 8. Decisiones pendientes (a resolver antes del plan)

1. **Cache engine para el lock:** Redis (preferido) vs `flock()` (fallback)? Verificar si Redis ya existe en el stack de la organización.
2. **Intervalo n8n:** 5 min (sugerido) o configurable desde n8n por ambiente.
3. **`max` por defecto:** 50 actual. Confirmar que un batch de 50 emails cabe en 5 min de timeout incluso con adjuntos pesados.
4. **Ubicación UI del token:** sección nueva "Webhooks" en `/admin/settings` o reusar la sección Gmail existente.
5. **Periodo de validación paralela:** 1 semana propuesta. Acortar/alargar según volumen real de correos.

---

## 9. Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|------------|
| n8n cae → no se importa correo | Mismo riesgo actual (worker cae también). Monitor de n8n debe alertar. |
| Token leakeado | Regeneración inmediata desde UI; cifrado at-rest; `hash_equals` evita timing attacks. |
| Doble ejecución concurrente (n8n dispara mientras corrida anterior sigue) | Lock distribuido (`Cache::add` o `flock`) + 409 inmediato. |
| Import largo bloquea worker FPM | `set_time_limit(300)` + lock evita acumulación; `max=50` cap superior; alerta en Grafana si la duración crece. |
| `request_terminate_timeout` corta el import | Configurar 360s en FPM y Nginx para `/webhooks/*`. |
| Migración deja sin token al instalador fresh | Migration genera token random automáticamente; UI permite regenerar. |

---

## 10. Referencias

- Code review previo: `docs/audits/2026-05-04-admin-module-review.md` (sección Gmail import).
- Hallazgos críticos relacionados que **no** resuelve este diseño y siguen pendientes:
  - **CR-001**: dedupe de comentarios por `gmail_message_id` (idempotencia).
  - **CR-004**: caché compartida del access token OAuth.
  - **CR-005**: validar tamaño de adjunto antes de descarga.
- CakePHP 5 Authentication: `allowUnauthenticated()` — https://book.cakephp.org/authentication/3/en/
- CakePHP 5 CSRF: `skipCheckCallback` — https://book.cakephp.org/5/en/security/csrf.html
