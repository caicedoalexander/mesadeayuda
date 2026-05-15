# Spec — Resiliencia en llamadas HTTP salientes (CRIT-1 + CRIT-2)

- **Fecha:** 2026-05-15
- **Auditoría origen:** `docs/audits/2026-05-14-tickets-module-audit.md`
- **Hallazgos cubiertos:** CRIT-1 (Circuit Breaker ausente) y CRIT-2 (sin Retry/Backoff).
- **Hallazgos NO cubiertos en esta spec:** CRIT-3 (Outbox), HIGH-1 (transaccionalidad), HIGH-4..HIGH-6, todos los MED/LOW.

---

## 1. Objetivo

Eliminar dos clases de fallo concretas documentadas en el audit:

1. Un proveedor externo degradado (WhatsApp/n8n/Gmail) hoy puede bloquear workers PHP-FPM durante ~33 minutos en un `bin/cake import_gmail --max 200` (200 × 10s de timeout).
2. Un HTTP 429/503 transitorio en `downloadAttachment` se loguea como error y el adjunto se pierde definitivamente — no hay reintento.

La intervención debe ser quirúrgica: no cambia firmas públicas, no requiere migración de DB, y los call sites existentes siguen funcionando sin modificación.

---

## 2. Alcance

**Dentro del alcance:**
- Refactor de `SecureHttpTrait::secureCurlPost` para delegar en un cliente HTTP resiliente.
- Nuevos componentes en `src/Service/Resilience/`: `CircuitBreaker`, `RetryPolicy`, `ResilientHttpClient`, `CircuitOpenException`.
- Configuración en `config/app.php` bajo `Resilience.*`, override vía `.env`.
- Tests unitarios para los tres componentes nuevos + ampliación de tests del trait.

**Fuera del alcance:**
- Llamadas a Gmail API que pasan por `Google\Client` (librería oficial Google) en `GmailService`. Estas no atraviesan `secureCurlPost` y quedan sin protección hasta una iteración futura.
- Políticas de retry/CB diferenciadas por endpoint. Todos los hosts comparten la misma política conservadora.
- Métricas Prometheus/OpenTelemetry. Solo se añade log estructurado.
- Resolver MED-3 (correlation_id consistente en logs). Se introducen logs estructurados nuevos, pero la estandarización transversal queda pendiente.

---

## 3. Arquitectura

### Componentes nuevos

Todos en `src/Service/Resilience/`:

| Clase | Responsabilidad |
|---|---|
| `RetryPolicy` (final readonly) | Value object inmutable. Predicado `shouldRetry(int $httpCode, int $curlErrno): bool` + cálculo de delay con backoff exponencial + jitter. |
| `CircuitBreaker` | Máquina de estados CLOSED/OPEN/HALF_OPEN. Persiste estado en `Cake\Cache\Cache`. Clave por host (`cb:{host}`). |
| `ResilientHttpClient` | Orquestador. Combina CB + Retry. Recibe una closure que ejecuta el POST real. |
| `CircuitOpenException` | Excepción de control de flujo cuando el CB está abierto. Capturada dentro del trait, NO escapa a callers. |

### Integración con código existente

- `SecureHttpTrait::secureCurlPost` se refactoriza así:
  - La lógica curl actual (validación URL, clamp timeout 30s, headers, ejecución) se extrae a un método privado `executeRawCurlPost(string $url, array $data, int $timeout): array`.
  - `secureCurlPost` ahora obtiene un `ResilientHttpClient` (lazy: `$this->resilientHttp ??= new ResilientHttpClient(...)` construido a partir de `Configure::read('Resilience')`) y le pasa la closure que invoca `executeRawCurlPost`.
  - **Firma pública sin cambios.** `WhatsappService`, `N8nService`, `GmailService` no se tocan.
- `CircuitOpenException` se captura dentro de `secureCurlPost` y se convierte al shape de respuesta estándar:
  ```php
  ['success' => false, 'error' => 'Circuit breaker open for host ...', 'circuit_breaker' => true, 'http_code' => 0]
  ```
  Los call sites existentes ya hacen `if (!$response['success'])` y siguen funcionando. La clave `circuit_breaker` es informativa para logging/UI futuro.

### Configuración

```php
// config/app.php (defaults)
'Resilience' => [
    'circuitBreaker' => [
        'failureThreshold' => env('RESILIENCE_CB_THRESHOLD', 5),
        'cooldownSeconds'  => env('RESILIENCE_CB_COOLDOWN', 30),
    ],
    'retry' => [
        'maxAttempts'      => env('RESILIENCE_RETRY_ATTEMPTS', 3),
        'baseDelayMs'      => env('RESILIENCE_RETRY_BASE_MS', 200),
        'backoffMultiplier'=> 2.5,
        'jitterMs'         => 100,
    ],
],
```

Cache: se reutiliza un cache config existente (preferentemente `CacheConstants::CACHE_CONFIG`) con un prefijo `cb:` para no colisionar. **Requisito:** el cache backend debe ser compartido entre workers FPM (file o redis), NO `Array`. En entorno de tests se usa `ArrayEngine` aislado.

---

## 4. Máquina de estados del Circuit Breaker

```
CLOSED ──(failures >= threshold)──> OPEN
  ▲                                  │
  │                                  │ (cooldown elapsed)
  │                                  ▼
  └──(success in HALF_OPEN)──── HALF_OPEN
                                     │
                                     └─(failure)──> OPEN (reinicia cooldown)
```

**Parámetros default:**
- `failureThreshold = 5` — fallos consecutivos para abrir.
- `cooldownSeconds = 30` — tiempo en OPEN antes de pasar a HALF_OPEN.
- `successThreshold = 1` — un éxito en HALF_OPEN cierra el breaker.

**Estructura persistida** (clave: `cb:{host}`):
```php
[
    'state'     => 'closed'|'open'|'half_open',
    'failures'  => int,
    'openedAt'  => int|null,  // unix timestamp
]
```

**Algoritmo `ResilientHttpClient::send(string $url, callable $executor): array`:**
1. `$host = parse_url($url, PHP_URL_HOST)`. Si null/false → ejecuta sin CB (la validación de URL ya la hace el trait).
2. Lee estado del CB para `$host`.
3. Si `OPEN` y `now - openedAt < cooldown` → lanza `CircuitOpenException`.
4. Si `OPEN` y cooldown vencido → transición a `HALF_OPEN` (escribe), deja pasar UN intento.
5. Ejecuta la llamada con `RetryPolicy::loop()`.
6. Resultado:
   - **Éxito**: si estaba HALF_OPEN o CLOSED-con-failures, resetea a `state=CLOSED, failures=0, openedAt=null`.
   - **Fallo definitivo** (tras agotar retries): incrementa `failures`. Si `failures >= threshold` O estaba HALF_OPEN, marca `state=OPEN, openedAt=now`.

**Race conditions:** Dos workers FPM pueden leer/escribir concurrentemente. Es aceptable: en el peor caso un worker extra pasa antes de que el CB abra. NO usamos locking distribuido — el costo no justifica precisión perfecta para este caso.

**Errores que NO cuentan como fallo del CB:**
- 4xx no-429 (errores del cliente, no del servicio remoto). Un WhatsApp 400 "número inválido" no debe abrir el breaker.

---

## 5. Política de Retry

```php
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 200,
        public float $backoffMultiplier = 2.5,
        public int $jitterMs = 100,
    ) {}

    public function shouldRetry(int $httpCode, int $curlErrno): bool
    {
        if ($curlErrno === CURLE_OPERATION_TIMEOUTED) return true;
        if ($httpCode >= 500 && $httpCode <= 599) return true;
        if ($httpCode === 429) return true;
        return false;
    }

    public function delayForAttempt(int $attempt): int
    {
        $base = (int) ($this->baseDelayMs * ($this->backoffMultiplier ** ($attempt - 1)));
        return $base + random_int(0, $this->jitterMs);
    }
}
```

**Bucle de retry** (dentro de `ResilientHttpClient::send`):
```
for attempt in 1..maxAttempts:
    result = $executor()
    if result.success: return result
    if attempt < maxAttempts AND policy.shouldRetry(result.http_code, result.curl_errno):
        usleep(policy.delayForAttempt(attempt) * 1000)
        continue
    return result  // fallo definitivo, se propaga al CB
```

**Delays con defaults:** ~200ms, ~500ms, ~1.25s (+ jitter hasta 100ms). Worst case agregado ~2s.

**Testeabilidad de `usleep`:** `ResilientHttpClient` recibe una closure `sleepFn` opcional (`?callable`) en su constructor. En tests se pasa una closure que registra el delay sin dormir; en prod default `usleep`.

---

## 6. Logging

Niveles y eventos:

| Evento | Nivel | Contexto |
|---|---|---|
| Retry de petición | `warning` | `host`, `attempt`, `http_code`, `curl_errno`, `delay_ms` |
| Apertura de CB | `error` | `host`, `failures`, `last_http_code` |
| HALF_OPEN tras cooldown | `info` | `host`, `opened_at` |
| Cierre de CB | `info` | `host` |
| CB rechazó petición | `warning` | `host`, `seconds_open` |

Los logs usan el canal por defecto de CakePHP (`Log::write`). No se introduce un canal nuevo en esta iteración.

---

## 7. Estrategia de testing

Todos los tests son unitarios; no tocan red ni cache real.

**`tests/TestCase/Service/Resilience/RetryPolicyTest.php`**
- Matriz `shouldRetry`: códigos 200, 400, 401, 403, 404, 429, 500, 502, 503, 504 + errno `CURLE_OPERATION_TIMEOUTED`, `CURLE_COULDNT_RESOLVE_HOST`, `CURLE_OK`.
- `delayForAttempt(1..3)` retorna valores dentro de los rangos esperados (base ≤ resultado ≤ base + jitter).

**`tests/TestCase/Service/Resilience/CircuitBreakerTest.php`**
Uses `Cake\Cache\Engine\ArrayEngine` configurado en `setUp`. Casos:
- CLOSED + N-1 failures → estado sigue CLOSED, `failures` incrementa.
- CLOSED + N failures → transición a OPEN, `openedAt` set.
- OPEN antes del cooldown → `isAvailable($host)` retorna false.
- OPEN tras cooldown (manipulando `openedAt` en cache directamente) → `isAvailable` retorna true y promueve a HALF_OPEN.
- HALF_OPEN + éxito → vuelve a CLOSED, `failures = 0`.
- HALF_OPEN + fallo → OPEN, `openedAt` actualizado.
- 4xx no-429 NO incrementa `failures` (se delega al caller distinguir, ver §8).

**`tests/TestCase/Service/Resilience/ResilientHttpClientTest.php`**
- Executor mock con secuencia configurable: `[500, 500, 200]` → 3 llamadas, retorna éxito.
- `[503, 503, 503]` → 3 llamadas, retorna fallo, CB incrementa failures.
- `[400]` → 1 llamada, retorna fallo, CB NO incrementa.
- `[429, 200]` → 2 llamadas, retorna éxito.
- CB en OPEN → 0 llamadas al executor, lanza `CircuitOpenException`.
- Verifica delays acumulados vía `sleepFn` mock.

**`tests/TestCase/Service/Traits/SecureHttpTraitTest.php`** (ampliación)
- Clase anónima usando el trait + inyectando `ResilientHttpClient` con executor mock.
- Verifica shape de respuesta cuando `CircuitOpenException` se lanza: `['success' => false, 'circuit_breaker' => true, ...]`.

**Sanidad en consumidores** (solo si no existen tests felices ya):
- `WhatsappService` — un test happy-path mockeando el trait para confirmar que la integración no se rompió.
- Análogo para `N8nService` si no existe.

---

## 8. Distinción de errores en el flujo

Decisión clave: **¿quién decide si un fallo cuenta para el CB?**

`ResilientHttpClient` recibe el resultado del executor (`['success' => bool, 'http_code' => int, 'curl_errno' => int]`). El propio cliente aplica las reglas:

- Si `success === true` → notifica éxito al CB.
- Si `success === false` y código es 4xx no-429 → NO notifica fallo al CB, pero igualmente retorna la respuesta de error al caller.
- Si `success === false` y código es 5xx/429/timeout → tras agotar retries, notifica fallo al CB.

Esto encapsula la política en un solo lugar. `RetryPolicy::shouldRetry` y la regla de "qué cuenta como fallo del CB" comparten el mismo predicado.

---

## 9. Despliegue

1. **Sin migraciones de DB.** Sin breaking changes en firmas públicas.
2. **Config:** añadir bloque `Resilience` en `config/app.php` con defaults; documentar variables `.env` opcionales en `config/.env.example` si existe.
3. **Cache backend:** verificar que el cache config usado (`CacheConstants::CACHE_CONFIG` o equivalente) NO sea `Array` en producción. Documentar requisito en README sección Docker.
4. **Rollback de emergencia:** setear `RESILIENCE_CB_THRESHOLD=999999` en `.env` neutraliza el CB sin redeploy. El retry sigue activo (es seguro).
5. **Observabilidad post-deploy:** monitorear logs por `circuit_breaker.opened` durante 48h. Falsos positivos → ajustar threshold/cooldown.

### Validación pre-merge

```bash
composer cs-fix && composer cs-check        # esperado: solo errores pre-existentes
vendor/bin/phpunit tests/TestCase/Service/Resilience/
vendor/bin/phpunit tests/TestCase/Service/Traits/SecureHttpTraitTest.php
vendor/bin/phpstan analyse src/Service/Resilience src/Service/Traits/SecureHttpTrait.php
```

### Actualización del audit

Al cerrar el frente, añadir entrada en `§11` de `docs/audits/2026-05-14-tickets-module-audit.md`:
- CRIT-1 y CRIT-2 cerrados.
- Actualizar §1 (salud arquitectónica → estimada 78-80%).
- Actualizar §9 (acciones priorizadas → marcar #1 completado).
- Actualizar §2 (matriz de patrones → Circuit Breaker y Retry pasan a Verde).

---

## 10. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Cache backend en `Array` en prod por error de config → CB no comparte estado entre workers | Doc explícita en README; check en `bin/cake doctor` futuro (fuera de scope). |
| Threshold demasiado bajo → falsos positivos cierran proveedor sano | Default conservador (5 fallos); rollback vía env var sin redeploy. |
| Retries amplifican carga a backend ya saturado | Backoff exponencial + jitter limita rate. 429 contribuye a abrir CB → corta el ciclo. |
| `random_int` en hot path con baja entropía en CLI → degradación insignificante | Aceptable; alternativa `mt_rand` solo si profiling lo justifica. |
| Pérdida de información cuando CB rechaza | Log estructurado registra cada rechazo con `host` y `seconds_open`. |

---

## 11. Lo que NO se hace en esta iteración

- **HIGH-1 (transaccionalidad de `handleResponse`)**: queda pendiente. La resiliencia HTTP reduce el riesgo de p&eacute;rdida de notificaciones pero no resuelve la ausencia de `Connection::transactional()`.
- **CRIT-3 (Outbox)**: tras esta iteración, los listeners siguen siendo síncronos. El CB protege contra latencia explosiva, pero un crash entre `save()` y `dispatch()` sigue perdiendo el evento.
- **Google\Client en Gmail API**: no se envuelve. Si en el futuro se requiere, se haría con un Guzzle middleware o decorator de `Google\Http\REST`.
- **Rate Limiter outbound (parte de HIGH-4)**: no se introduce. El backoff de retry actúa como amortiguador parcial.
