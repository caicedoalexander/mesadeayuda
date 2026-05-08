# Auditoría — Fase 4: cierre de medios pendientes (5.1–5.6)

**Fecha:** 2026-05-08
**Documento padre:** `docs/audits/2026-05-07-architecture-audit.md`
**Alcance:** medios 5.1, 5.2, 5.3, 5.5, 5.6 (5.4 y 5.7 cerrados en Anexo 5)
**Resultado esperado:** auditoría 100% cerrada, deuda nueva = 0.

---

## 1. Resumen

Una sola fase 4 que cierra los 5 medios pendientes en orden de dependencia:

```
5.6 (doc)  →  5.3 (exceptions sweep)  →  5.5 (SystemConfig VO)  →  5.1 (domain events)  →  5.2 (tests)
```

Razón del orden: 5.5 toca los constructores de los 5 servicios refactorizados en fase 4 anterior; 5.1 vuelve a tocarlos para inyectar el dispatcher, así que conviene que 5.5 vaya antes para no rehacer firmas dos veces. 5.3 es ortogonal y barato. 5.2 cierra contra los artefactos creados en 5.5/5.1 además de la entidad `Ticket`. 5.6 es decisión documental sin código.

**Decisiones tomadas en brainstorming:**

| Ítem | Decisión |
|---|---|
| 5.1 alcance | Mínimo: 3 eventos (`TicketCreated`, `TicketAssigned`, `TicketStatusChanged`) + 1 listener (`TicketNotificationListener`) |
| 5.2 alcance | Unit tests puros (sin DB) sobre `Ticket` (entidad), `SystemConfig` (VO), eventos de dominio |
| 5.3 alcance | Sweep de `RuntimeException` literal en servicios → 2 excepciones tipadas nuevas |
| 5.5 estrategia | Value Object `SystemConfig` readonly con sub-configs tipadas |
| 5.6 acción | Mantener `SidebarCountsService` tal cual; cerrar como "decisión documentada" |
| Secuenciamiento | Fase única (Enfoque A) |

---

## 2. Sub-fase 4.1 — Cierre documental (ítem 5.6)

**Acción:** anotar en Anexo 6 del documento de auditoría que `SidebarCountsService` se mantiene sin cambios.

**Justificación que se debe documentar:**
- Tras la fase 2 (Anexo 3), el servicio ya consume `getAgentStatusCounts` desde el Cell.
- El Cell es hoy el único caller, pero la abstracción está lista para ser reutilizada por futuras vistas.
- Coste de inlinear y eventualmente re-extraer > beneficio.

**Entregable:** sección "Anexo 6 — Cierre fase 4 (medios 5.1–5.6)" en `docs/audits/2026-05-07-architecture-audit.md` (se completa al final de la fase con el cierre de los 5 ítems).

---

## 3. Sub-fase 4.2 — Sweep de excepciones (ítem 5.3)

### Estado actual

4 sitios con `throw new RuntimeException(...)` literal en servicios:

| Archivo | Líneas | Contexto |
|---|---|---|
| `src/Service/GmailService.php` | 127, 131, 172 | Fallos de autenticación OAuth y refresh token |
| `src/Service/Traits/SettingsEncryptionTrait.php` | 121 | Fallo de cifrado/descifrado de claves sensibles |

### Excepciones nuevas

```
src/Service/Exception/
   GmailAuthenticationException.php       // extends \RuntimeException
   SettingsEncryptionException.php        // extends \RuntimeException
```

Ambas heredan de `\RuntimeException` para no romper catch-alls existentes (`catch (\RuntimeException $e)` o `catch (\Exception $e)` siguen funcionando).

### Cambios

1. Crear las 2 clases con docblock breve y constructor por defecto (heredado).
2. Reemplazar los 4 throws en orden:
   - `GmailService.php:127, 131, 172` → `GmailAuthenticationException`
   - `SettingsEncryptionTrait.php:121` → `SettingsEncryptionException`
3. Mantener mensajes y `previous` (cadena de excepciones) idénticos.
4. Verificación: `grep "RuntimeException" src/Service/` — debe quedar 0 ocurrencias en código de servicios (excluyendo `use` statements de las nuevas clases).

### Sin cambios runtime

El comportamiento observable es idéntico: el HTTP response, los logs y las pantallas de error siguen mostrando el mismo mensaje. La única diferencia es que ahora el tipo de excepción es semánticamente correcto.

---

## 4. Sub-fase 4.3 — Value Object `SystemConfig` (ítem 5.5)

### Diseño del VO raíz

`src/Service/Dto/SystemConfig.php`:

```php
final readonly class SystemConfig
{
    public function __construct(
        public GmailConfig $gmail,
        public SmtpConfig $smtp,
        public N8nConfig $n8n,
        public WhatsappConfig $whatsapp,
        public AppConfig $app,
    ) {}

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

    public static function empty(): self
    {
        return self::fromSettingsArray([]);
    }
}
```

### Sub-configs

Cada sub-config es `final readonly` con propiedades tipadas y `static fromArray(array $raw): self`. Defaults explícitos para que `fromArray([])` no falle.

| Clase | Propiedades (tentativas, ajustar contra `SettingKeys`) |
|---|---|
| `GmailConfig` | `clientId`, `clientSecret`, `redirectUri`, `accessToken`, `refreshToken`, `tokenExpiresAt` |
| `SmtpConfig` | `host`, `port`, `username`, `password`, `fromAddress`, `fromName`, `tls` |
| `N8nConfig` | `webhookUrl`, `apiKey`, `enabled` |
| `WhatsappConfig` | `apiUrl`, `apiKey`, `instance`, `enabled` |
| `AppConfig` | `baseUrl`, `systemTitle`, `logoUrl` |

Las propiedades exactas se derivan inspeccionando los call sites actuales en `EmailService`, `WhatsappService`, `N8nService`, `GmailService`. La fuente de verdad de las keys es `src/Constants/SettingKeys.php`.

### Mapper tolerante

`fromArray` no lanza si una key falta. Devuelve sub-config con strings vacíos / `false` / `null` según corresponda. El servicio consumidor decide qué hacer con valores ausentes (típicamente: lanzar excepción específica como `GmailAuthenticationException` cuando la operación lo requiera).

### Refactor de servicios

**Servicios que reciben `SystemConfig`** (firmas nuevas):

| Servicio | Antes | Después |
|---|---|---|
| `TicketIngestionService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `TicketPipelineService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `TicketCommentService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `TicketAttachmentService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `TicketNotificationService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `EmailService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `WhatsappService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `N8nService` | `?array $systemConfig = null` | `?SystemConfig $config = null` |
| `GmailService` | (ver constructor actual) | acepta `?SystemConfig $config = null` |

Default cuando el caller no pasa nada: `$config ?? SystemConfig::empty()`. **No** se hace `Cache::read` dentro del constructor — eso es I/O y rompe la testabilidad. La construcción desde cache vive solo en `TicketServiceInitializerTrait` (controllers) y `Application::bootstrap` (listener registration).

### Initializer del controller

`src/Controller/Trait/TicketServiceInitializerTrait::initializeServices`:

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

`AppController` (si construye servicios directamente) hace lo mismo.

### Riesgo y mitigación

- **Superficie:** 9 servicios + 1 trait + 1 controller. ~10 archivos editados, ~6 archivos nuevos (1 VO raíz + 5 sub-configs).
- **Compatibilidad:** los constructores siguen aceptando `null` como default → no se rompe ningún call site existente que no pase config.
- **Validación:** `composer cs-check` + smoke manual de los flujos clave (login, importar Gmail, ver ticket, asignar) tras la sub-fase.

---

## 5. Sub-fase 4.4 — Domain events (ítem 5.1)

### Estructura de carpetas

```
src/Domain/Event/
   DomainEvent.php              // abstract — extiende Cake\Event\Event
   TicketCreated.php
   TicketAssigned.php
   TicketStatusChanged.php
src/Listener/
   TicketNotificationListener.php
```

### `DomainEvent` base

```php
abstract class DomainEvent extends \Cake\Event\Event
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(string $name, mixed $subject = null, array $data = [])
    {
        parent::__construct($name, $subject, $data);
        $this->occurredAt = new \DateTimeImmutable();
    }
}
```

### Eventos concretos

Todos `final` y `readonly` en sus payloads:

| Evento | Nombre Cake | Payload |
|---|---|---|
| `TicketCreated` | `Ticket.created` | `ticketId: int`, `requesterId: int`, `source: 'email'\|'manual'` |
| `TicketAssigned` | `Ticket.assigned` | `ticketId: int`, `assigneeId: ?int`, `previousAssigneeId: ?int`, `actorId: int` |
| `TicketStatusChanged` | `Ticket.statusChanged` | `ticketId: int`, `oldStatus: string`, `newStatus: string`, `actorId: int` |

Cada evento expone los campos como `public readonly` y delega `getName()` al constructor del padre.

### Listener

`src/Listener/TicketNotificationListener.php`:

```php
final class TicketNotificationListener implements \Cake\Event\EventListenerInterface
{
    public function __construct(private TicketNotificationService $notifications) {}

    public function implementedEvents(): array
    {
        return [
            'Ticket.created'       => 'onCreated',
            'Ticket.assigned'      => 'onAssigned',
            'Ticket.statusChanged' => 'onStatusChanged',
        ];
    }

    public function onCreated(TicketCreated $event): void { /* recargar entidad + dispatchCreationNotifications */ }
    public function onAssigned(TicketAssigned $event): void { /* dispatchUpdateNotifications('assignment') */ }
    public function onStatusChanged(TicketStatusChanged $event): void { /* dispatchUpdateNotifications('status_change') */ }
}
```

Cada handler:
1. Recarga la entidad fresca (`TicketsTable->get($event->ticketId, contain: [...])`).
2. Delega al método correspondiente de `TicketNotificationService`.
3. Captura `\Throwable` y loguea — no propaga (mismo patrón defensivo actual).

### Registro

`src/Application.php::bootstrap()`:

```php
$config = SystemConfig::fromSettingsArray(Cache::read(...));
$notificationService = new TicketNotificationService($config);
EventManager::instance()->on(new TicketNotificationListener($notificationService));
```

### Sitios de dispatch

| Sitio actual | Reemplazo |
|---|---|
| `TicketIngestionService::createFromEmail` línea 133 (`$this->notifications->dispatchCreationNotifications($ticket)`) | `$this->dispatcher->dispatch(new TicketCreated($ticket->id, $ticket->requester_id, 'email'))` |
| `TicketPipelineService::assign` (al persistir cambio de assignee) | `dispatch(new TicketAssigned(...))` — eliminar llamada directa de notification por asignación si existe hoy |
| `TicketPipelineService::changeStatus` (cuando transición es válida y persiste) | `dispatch(new TicketStatusChanged(...))` |
| `TicketPipelineService::handleResponse` (cuando `$oldStatus !== $newStatus`) | `dispatch(new TicketStatusChanged(...))` adicional al `sendResponseNotifications` actual |

**Lo que NO se mueve a eventos en este alcance:**
- `dispatchUpdateNotifications($entity, 'response', ...)` — sigue invocándose directamente desde `handleResponse`.
- `dispatchUpdateNotifications($entity, 'comment', ...)` — sigue invocándose desde donde se invoque hoy.
- Audit trail (`AuditBehavior`) — sigue corriendo vía table events de Cake, ortogonal a domain events.
- Webhook n8n — sigue dentro de `TicketNotificationService::dispatchUpdateNotifications`.

### Inyección del dispatcher

Servicios que disparan eventos reciben `?EventDispatcherInterface $dispatcher = null` (interfaz Cake nativa). Default: `EventManager::instance()`. Esto:
- Mantiene el patrón DI de fase 4 (constructores con defaults internos).
- Permite mocks futuros en tests cuando exista DB infra.

`TicketIngestionService` y `TicketPipelineService` reciben el dispatcher; `TicketCommentService`, `TicketAttachmentService`, `TicketNotificationService` no lo necesitan en este alcance.

### Riesgo y mitigación

- **Riesgo:** medio-alto. Único cambio runtime-sensible de la fase.
- **Modo de fallo:** si el listener no está registrado, los eventos disparan al vacío y no se envían notificaciones.
- **Mitigación:**
  - Smoke obligatorio al final de la sub-fase: crear ticket vía Gmail → debe llegar email + WhatsApp; asignar → debe llegar email; cambiar estado → debe llegar email.
  - Listener captura `\Throwable` y loguea (no rompe el flujo si falla un canal).
  - Test unitario que verifica que cada handler del listener invoca el método correcto de un mock de `TicketNotificationService` (parte de sub-fase 4.5).

---

## 6. Sub-fase 4.5 — Tests unitarios mínimos (ítem 5.2)

### Bootstrap

**Archivos nuevos:**
- `phpunit.xml.dist` (raíz) — testsuite `Unit` apuntando a `tests/TestCase/`. `bootstrap="tests/bootstrap.php"`. Color, no coverage por defecto.
- `tests/bootstrap.php` — autoload + `Cake\Core\Configure` mínimo (`debug=true`, sin DB).
- `.gitignore` — añadir `.phpunit.cache/`, `coverage/`.

**`composer.json`:**
- `require-dev`: `phpunit/phpunit ^10.5`.
- Scripts:
  - `"test": "phpunit"`
  - `"test-coverage": "phpunit --coverage-html coverage"`

### Tests pure-unit (sin fixtures, sin DB)

#### `tests/TestCase/Model/Entity/TicketTest.php`

Cobertura:
- **Predicados de estado:** `isResolved`, `isOpen`, `isNew`, `isPending` — un test por estado canónico, asegura true en su estado y false en los otros.
- **Predicados de relación:** `hasAssignee` (con/sin), `belongsTo($userId)` (true/false), `isAssignedTo($userId)` (true/false con assignee null).
- **Predicado de origen:** `wasCreatedFromEmail` (true cuando `gmail_message_id` set, false cuando null).
- **Lock:** `isLocked` — true para `resuelto`, false para los demás.
- **Transitions:**
  - Para cada par `(from, to)` permitido en `Ticket::TRANSITIONS` → `canTransitionTo($to)` returns true.
  - Para cada par no permitido (matriz inversa) → returns false.
- **Asignación:** `canBeAssignedTo` — true para User staff activo; false para inactivo, no-staff, locked ticket.

Estimado: ~120 LOC, ~15 tests.

#### `tests/TestCase/Service/Dto/SystemConfigTest.php`

Cobertura:
- `fromSettingsArray($completo)` — cada sub-config tiene los valores esperados.
- `fromSettingsArray([])` — todas las sub-configs con defaults seguros, no throws.
- `fromSettingsArray(null)` — equivalente a empty.
- `empty()` — instancia válida con todos los defaults.
- Una prueba por sub-config (`GmailConfig::fromArray`, etc.) verificando keys esperadas.

Estimado: ~80 LOC, ~10 tests.

#### `tests/TestCase/Domain/Event/TicketCreatedTest.php` + `TicketAssignedTest.php` + `TicketStatusChangedTest.php`

Cobertura por evento:
- Constructor congela payload (acceso readonly funciona).
- `getName()` retorna el nombre Cake esperado (`'Ticket.created'`, etc.).
- `occurredAt` se setea al instanciar y es `DateTimeImmutable`.

Estimado: ~30 LOC c/u, 3 archivos, ~9 tests totales.

#### `tests/TestCase/Listener/TicketNotificationListenerTest.php`

Cobertura:
- `implementedEvents()` retorna las 3 keys esperadas.
- `onCreated(event)` invoca `dispatchCreationNotifications` en mock de `TicketNotificationService`.
- `onAssigned(event)` invoca `dispatchUpdateNotifications` con tipo `'assignment'`.
- `onStatusChanged(event)` invoca `dispatchUpdateNotifications` con tipo `'status_change'`.
- Excepción del service no propaga (handler catch-and-log).

Estimado: ~80 LOC, ~6 tests. Requiere recargar entidad — esto necesita stub de `TicketsTable->get`. Si la complejidad del stub crece, este test queda fuera del alcance unit puro y se documenta como pendiente integration.

### CI

Sin cambios. El proyecto no tiene pipeline automatizado; los tests se ejecutan localmente con `composer test`. Documentar en `CLAUDE.md` el comando.

### No incluido

- Integration tests con DB (MySQL test schema, fixtures, transacciones de cleanup).
- Tests de servicios concretos (requieren mocks extensivos de `Table` o DB real).
- Tests de controllers (requieren `IntegrationTestTrait` + DB).

Estos quedan documentados como deuda futura en CLAUDE.md (sección "Testing").

---

## 7. Plan de ejecución

### Orden de commits

1. `docs(audit): start fase 4 medios — anexo 6 (placeholder)`
2. `feat(exception): add GmailAuthenticationException + SettingsEncryptionException`
3. `refactor(services): replace literal RuntimeException with typed exceptions`
4. `feat(dto): add SystemConfig value object with sub-configs`
5. `refactor(services): adopt SystemConfig DTO in ticket services`
6. `refactor(services): adopt SystemConfig DTO in integration services (Email/Whatsapp/N8n/Gmail)`
7. `refactor(controller): build SystemConfig in TicketServiceInitializerTrait`
8. `feat(domain): add ticket domain events (Created/Assigned/StatusChanged)`
9. `feat(listener): add TicketNotificationListener + register in Application`
10. `refactor(services): dispatch domain events instead of direct notification calls`
11. `chore(test): bootstrap PHPUnit + composer scripts`
12. `test(unit): add Ticket entity tests`
13. `test(unit): add SystemConfig + domain event tests`
14. `test(unit): add TicketNotificationListener tests`
15. `docs(audit): close fase 4 (medios 5.1-5.6) in Anexo 6`

### Smoke manual obligatorio

Antes del commit 15, ejecutar:

1. **Login admin:** `https://localhost/users/login` → home con tickets visibles.
2. **Importar email:** `docker compose exec web bin/cake import_gmail --max 1` → ticket creado, log de email enviado.
3. **Asignar ticket:** UI → cambiar assignee → email/WhatsApp llegan al assignee.
4. **Cambiar estado a resuelto:** UI → email al requester con plantilla de resolución.
5. **Transición ilegal:** intentar `nuevo → resuelto` directo (si la matriz no lo permite) → flash error con mensaje de `InvalidStatusTransitionException`.
6. **Validaciones automáticas:**
   - `composer cs-check` → 0 errores.
   - `composer test` → todos los unit tests pasan.

### Entregables

- 6 archivos nuevos en `src/Service/Dto/` (1 raíz + 5 sub-configs).
- 2 archivos nuevos en `src/Service/Exception/`.
- 4 archivos nuevos en `src/Domain/Event/`.
- 1 archivo nuevo en `src/Listener/`.
- ~6 archivos editados en `src/Service/` (constructores + dispatch sites).
- 1 archivo editado en `src/Application.php` (registro listener).
- 1 archivo editado en `src/Controller/Trait/TicketServiceInitializerTrait.php`.
- 1 archivo editado en `src/Service/GmailService.php` (exceptions).
- 1 archivo editado en `src/Service/Traits/SettingsEncryptionTrait.php` (exception).
- ~7 archivos nuevos en `tests/`.
- 2 archivos nuevos de configuración (`phpunit.xml.dist`, `tests/bootstrap.php`).
- 2 archivos editados (`composer.json`, `.gitignore`).
- 2 archivos editados de documentación (`docs/audits/2026-05-07-architecture-audit.md`, `CLAUDE.md`).

**Total estimado:** ~24 archivos nuevos, ~13 archivos editados.

---

## 8. Riesgos y validación

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Mapper de `SystemConfig` no cubre todas las keys actuales | Media | Medio (servicios reciben empty strings, fallan silenciosamente) | Inspección exhaustiva de `SettingKeys` + grep de cada key en servicios antes de fijar el shape del VO |
| Listener no registrado en bootstrap | Baja | Alto (notificaciones no llegan) | Smoke manual obligatorio en sub-fase 4.4 |
| Doble notificación (legacy direct call + nuevo listener) | Media | Medio (usuario recibe email duplicado) | Eliminar las llamadas directas al hacer el cambio en el mismo commit que añade el dispatch |
| Tests unit fallan en CI inexistente | N/A | N/A | Sin CI; ejecución local con `composer test` documentada en CLAUDE.md |
| Compatibilidad rota en callers que pasaban `?array` | Baja | Alto (errores de tipo) | Verificación: `grep -r "new TicketIngestionService\|new TicketPipelineService\|..." src/` antes y después; firmas con default `null` mantienen compatibilidad para callers que no pasan nada |

---

## 9. Criterios de aceptación

- [ ] `grep "RuntimeException" src/Service/` retorna 0 ocurrencias en throws (solo aparece en `extends \RuntimeException` de las clases nuevas).
- [ ] Todos los servicios refactorizados aceptan `?SystemConfig $config = null` como primer parámetro.
- [ ] `SystemConfig::fromSettingsArray([])` no lanza excepciones.
- [ ] `EventManager::instance()->getListeners('Ticket.created')` retorna al menos 1 listener tras `Application::bootstrap`.
- [ ] Cada uno de los 3 dispatch sites en servicios usa `dispatch(new TicketXxx(...))` y NO llama directamente a `TicketNotificationService` para esos casos.
- [ ] `composer test` ejecuta y pasa con ≥30 tests unitarios.
- [ ] `composer cs-check` retorna 0 errores.
- [ ] Smoke manual completo (login + Gmail import + asignar + cambio estado + transición ilegal) sin regresiones.
- [ ] Anexo 6 del documento de auditoría documenta el cierre de los 5 ítems con referencia a este spec y al plan asociado.
- [ ] `CLAUDE.md` actualizado con: (a) sección de testing y comando `composer test`, (b) mención del VO `SystemConfig` en la sección de Configuración, (c) mención de domain events en cross-cutting conventions.

---

## 10. Pendientes explícitamente fuera de alcance

- Integration tests con DB.
- Tests de servicios completos (requieren mocks de Tables).
- Eventos adicionales (`TicketCommentAdded`, `TicketPriorityChanged`, `TicketTagAdded`, `TicketFollowerAdded`).
- Listeners adicionales (audit, n8n separado).
- Asincronía de eventos (queue-based dispatch).
- CI pipeline.

Estos quedan documentados como deuda futura, NO como bugs.
