# Spec — Gmail-Ingest Fix + Audit Quick Wins (MED-4 / MED-7 / MED-6)

- **Fecha:** 2026-05-16
- **Driver:** continuación de la auditoría `docs/audits/2026-05-14-tickets-module-audit.md`
- **Modo:** cluster — un fix urgente + tres quick wins de hallazgos medios
- **Salud auditada esperada al cierre:** ~85% → ~90%

---

## 1. Contexto

La auditoría está ~85% cerrada. Esta sesión tiene dos objetivos:

1. **Reparar una regresión latente** introducida por el refactor del 2026-05-16 (notification layer). El refactor convirtió `TicketNotificationService` en orquestador strategies+channels y eliminó el método `getN8nService()`, pero dejó `TicketIngestionService` apuntando al API viejo. Resultado: la ingesta de Gmail (`bin/cake import_gmail` y `POST /webhooks/gmail/import`) crashea con `TypeError` antes de procesar el primer mensaje. El auditor no detectó esta regresión.
2. **Cerrar tres findings medios** de la auditoría que son cleanup mecánico: MED-4 (helper residual `getEntityComponents()`), MED-7 (factory `Ticket::fromEmailIngest()`), y MED-6 (lazy DI — ya obsoleto por el refactor mencionado, solo requiere marcar cerrado).

---

## 2. Alcance

### Dentro del alcance

- Fix de runtime en `TicketIngestionService` (constructor + llamada N8n).
- Eliminación completa del helper `getEntityComponents()` y sus 11 callsites.
- Factory `Ticket::fromEmailIngest()` + adopción en `TicketIngestionService::createFromEmail`.
- Actualización de §11 de la auditoría para registrar el cierre de MED-4, MED-6, MED-7 y la regresión descubierta.

### Fuera del alcance

- Reescribir N8n como `NotificationChannel` (mencionado en §11 del audit como opción futura). N8n es un webhook event-to-system, no una notificación a personas — los canales actuales (`EmailChannel`, `WhatsappChannel`) son para mensajes a usuarios. La distinción es deliberada.
- Tocar el bypass de `email_to`/`email_cc` en `TicketIngestionService::createCommentFromEmail` (línea 219+). El factory cubre solo `createFromEmail`; comments se quedan como están.
- CRIT-3 (Outbox), HIGH-4 (Bulkhead), MED-2, MED-3, MED-5.

---

## 3. Diseño detallado

### 3.1 Fix de regresión Gmail-ingest

**Síntoma exacto:**

`src/Service/TicketIngestionService.php:49`
```php
$this->notifications = $notifications ?? new TicketNotificationService($this->config);
//                                                                       ^^^^^^^^^^^^^
// SystemConfig pasado donde se espera array (firma actual:
//   __construct(array $strategies = [], array $channels = []))
// → TypeError en construcción cuando $notifications es null
```

`src/Service/TicketIngestionService.php:151`
```php
$this->notifications->getN8nService()->sendTicketCreatedWebhook($ticket);
//                    ^^^^^^^^^^^^^
// Método eliminado en el refactor del 2026-05-16. Error: Call to undefined method.
```

**Camino del crash:** `GmailImportService::fromSettings()` (línea 49) instancia `new TicketIngestionService($config)` sin pasar `$notifications`, así que el fallback roto del constructor se ejecuta siempre.

**Cambio:**

`TicketIngestionService` reemplaza la dependencia indirecta vía `TicketNotificationService` por una dependencia directa a `N8nService`.

```php
// Antes
private TicketNotificationService $notifications;

public function __construct(
    ?SystemConfig $config = null,
    ?TicketAttachmentService $attachments = null,
    ?TicketNotificationService $notifications = null,
    ?EventManagerInterface $eventManager = null,
) {
    // ...
    $this->notifications = $notifications ?? new TicketNotificationService($this->config);
}

// Después
private N8nService $n8n;

public function __construct(
    ?SystemConfig $config = null,
    ?TicketAttachmentService $attachments = null,
    ?N8nService $n8n = null,
    ?EventManagerInterface $eventManager = null,
) {
    // ...
    $this->n8n = $n8n ?? new N8nService($this->config);
}
```

Línea 151 pasa a `$this->n8n->sendTicketCreatedWebhook($ticket)`.

**Razón de fondo:** la dependencia de `TicketIngestionService` con `TicketNotificationService` era una fachada accidental (`getN8nService()` exponía la interna). N8n NO es un canal de notificación a personas — es un webhook que dispara tagging por IA en n8n. Inyectar `N8nService` directamente refleja el dominio correctamente.

**Test de regresión:** un `TicketIngestionServiceTest` con un único test que construye `new TicketIngestionService($config)` (con `$config` válido) y verifica que la construcción no lanza. Esto bloquea futuras regresiones del fallback del constructor. No requiere fixtures de DB.

---

### 3.2 MED-4 — Eliminación de `getEntityComponents()`

**Helper a eliminar** (`src/Controller/Trait/TicketServiceInitializerTrait.php:84-99`):

```php
private function getEntityComponents(): array
{
    $components = [
        'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
        'service' => $this->ticketPipeline ?? null,
        'displayName' => 'Ticket',
        'tableName' => 'Tickets',
        'foreignKey' => 'ticket_id',
    ];
    return array_merge($components, [
        0 => $components['table'],
        1 => $components['service'],
        2 => $components['displayName'],
    ]);
}
```

**Patrón observado en los 11 callsites:** `displayName` siempre es `'Ticket'`, `tableName` siempre `'Tickets'`, `foreignKey` siempre `'ticket_id'`. No abstrae nada — es residuo de un intento previo de soportar múltiples entity types vía `$entityType`.

**Mapeo de reemplazo:**

| Patrón actual | Reemplazo |
|---|---|
| `$components = $this->getEntityComponents(); $components['table']->get($id)` | `$this->fetchTable('Tickets')->get($id)` |
| `$components['service']->method(...)` | `$this->ticketPipeline->method(...)` |
| `$components['displayName']` (siempre `'Ticket'`) | literal `'Ticket'` inline |
| `[$table, $service, $entityName] = $this->getEntityComponents();` | tres líneas o inline directo |
| `[, , $entityName] = ...` | literal `'Ticket'` |
| `[$table, , $entityName] = ...` | `$this->fetchTable('Tickets')` + literal |
| `$components['tableName']` | literal `'Tickets'` |
| `$components['foreignKey']` | literal `'ticket_id'` |

**Callsites (11 total):**

- `TicketActionsTrait.php:119, 166, 205, 233` (4)
- `TicketBulkTrait.php:65, 116, 159, 205` (4)
- `TicketViewTrait.php:57` (1)
- `TicketListingTrait.php:57` (1)
- `TicketHistoryTrait.php:43` (1)

**Beneficios laterales:**

- **i18n más limpio:** `__("{$entityName} asignada correctamente.")` evita extracción gettext; `__("Ticket asignada correctamente.")` literal sí se extrae.
- **Tipo más fuerte:** `$components['table']` queda como `mixed` para PHPStan; `$this->fetchTable('Tickets')` retorna `\Cake\ORM\Table` con inferencia útil.

**Riesgo:** ninguno. Es refactor mecánico verificable con `composer test` + `phpstan analyse src/Controller`.

**Diff esperado:** +22 / −44 líneas (net negative).

---

### 3.3 MED-7 — `Ticket::fromEmailIngest()` factory

**Código actual** (`TicketIngestionService.php:110-129`):

```php
$ticket = $ticketsTable->newEntity([
    'ticket_number' => $ticketNumber,
    'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
    'gmail_thread_id' => $emailData['gmail_thread_id'] ?? null,
    'subject' => $subject,
    'description' => $description,
    'status' => TicketConstants::STATUS_NUEVO,
    'priority' => TicketConstants::PRIORITY_MEDIA,
    'requester_id' => $user->id,
    'channel' => $channel,
    'source_email' => $fromEmail,
], ['accessibleFields' => [
    'ticket_number' => true, 'gmail_message_id' => true, 'gmail_thread_id' => true,
    'status' => true, 'requester_id' => true, 'channel' => true, 'source_email' => true,
]]);
assert($ticket instanceof Ticket);

$ticket->email_to = !empty($emailData['email_to']) ? $emailData['email_to'] : null;
$ticket->email_cc = !empty($emailData['email_cc']) ? $emailData['email_cc'] : null;
```

Mezcla 4 concerns: field assignment, `accessibleFields` override por el cierre de `$_accessible`, `assert(instanceof)` por el retorno genérico de `newEntity`, y bypass directo para campos array.

**Factory propuesto** (`src/Model/Entity/Ticket.php`):

```php
/**
 * Construye un Ticket nuevo a partir de un email ingestado (Gmail / WA bot).
 *
 * Encapsula la decisión de estado y prioridad iniciales, y bypasea el cierre
 * de $_accessible — legítimo porque es la entidad construyéndose a sí misma;
 * el cierre sigue protegiendo mass-assign desde controllers/marshalling.
 */
public static function fromEmailIngest(
    string $ticketNumber,
    int $requesterId,
    string $subject,
    string $sanitizedDescription,
    string $channel,
    string $sourceEmail,
    ?string $gmailMessageId = null,
    ?string $gmailThreadId = null,
    mixed $emailTo = null,
    mixed $emailCc = null,
): self {
    $ticket = new self();
    $ticket->ticket_number = $ticketNumber;
    $ticket->gmail_message_id = $gmailMessageId;
    $ticket->gmail_thread_id = $gmailThreadId;
    $ticket->subject = $subject !== '' ? $subject : '(Sin asunto)';
    $ticket->description = $sanitizedDescription;
    $ticket->status = TicketConstants::STATUS_NUEVO;
    $ticket->priority = TicketConstants::PRIORITY_MEDIA;
    $ticket->requester_id = $requesterId;
    $ticket->channel = $channel;
    $ticket->source_email = $sourceEmail;
    $ticket->email_to = $emailTo;
    $ticket->email_cc = $emailCc;
    return $ticket;
}
```

**Adopción** en `TicketIngestionService::createFromEmail`:

```php
$ticket = Ticket::fromEmailIngest(
    ticketNumber: $ticketsTable->generateTicketNumber(),
    requesterId: (int)$user->id,
    subject: trim($emailData['subject'] ?? ''),
    sanitizedDescription: $description,
    channel: $channel,
    sourceEmail: $fromEmail,
    gmailMessageId: $emailData['gmail_message_id'] ?? null,
    gmailThreadId: $emailData['gmail_thread_id'] ?? null,
    emailTo: !empty($emailData['email_to']) ? $emailData['email_to'] : null,
    emailCc: !empty($emailData['email_cc']) ? $emailData['email_cc'] : null,
);

if (!$ticketsTable->save($ticket)) {
    Log::error('Failed to save ticket', ['errors' => $ticket->getErrors()]);
    return null;
}
```

La elección de status inicial, prioridad inicial y fallback `(Sin asunto)` pasan a vivir en la entidad — donde pertenecen.

**Riesgo:** bypass de los marshallers de CakePHP. Los campos involucrados son strings/ints/null/arrays nativos. Las reglas de validación del Table se aplican al `save()` independientemente de cómo se construyó la entidad. Se requiere correr el suite completo para verificar no regresión.

**Test:** un `TicketTest::testFromEmailIngestProducesValidEntity` que verifique:
- status inicial = `STATUS_NUEVO`.
- priority inicial = `PRIORITY_MEDIA`.
- `(Sin asunto)` cuando subject vacío.
- Pass-through de gmail ids, email_to, email_cc.

**Diff esperado:** +50 (factory + test) / −18 (eliminación de la construcción inline).

---

### 3.4 MED-6 — Cierre administrativo

El hallazgo MED-6 referenciaba "lazy DI inconsistente en `TicketNotificationService.php:36-49`". El refactor del 2026-05-16 eliminó completamente esa instanciación interna: el servicio ya no construye `EmailService`/`WhatsappService`/`N8nService` — los recibe inyectados desde `Application::registerDomainEventListeners()`. El hallazgo es estructuralmente obsoleto.

**Cambio:** actualizar §11 de `docs/audits/2026-05-14-tickets-module-audit.md` para registrar:
- MED-6 cerrado como obsoleto por el refactor previo.
- MED-4 cerrado en esta iteración.
- MED-7 cerrado en esta iteración.
- Regresión Gmail-ingest descubierta + cerrada (no estaba en la auditoría original — agregar nota explicando cómo se pasó por alto).

---

## 4. Plan de validación

Para cada bloque, antes de marcar terminado:

| Validación | Cómo |
|---|---|
| Construcción de `TicketIngestionService` no lanza | Nuevo test unitario `TicketIngestionServiceTest::testConstructsWithoutNotifications` |
| `composer test` verde | Suite completa |
| `composer cs-check` sin nuevos errores | Diff contra baseline |
| `vendor/bin/phpstan analyse src` sin nuevos errores | Diff contra baseline (37 errores pre-existentes documentados en auditoría) |
| `composer cs-fix` no-op antes de commit | Pre-commit hygiene |
| Smoke manual: `bin/cake import_gmail --max 1` | Opcional, solo si hay credenciales Gmail configuradas en dev |

---

## 5. Plan de commits

Cuatro commits independientes para mantener bisect util:

1. `fix(ingestion): repair Gmail-ingest TypeError after notification refactor` — solo el fix de regresión + su test.
2. `refactor(controller): drop unused getEntityComponents helper` — MED-4.
3. `refactor(domain): introduce Ticket::fromEmailIngest factory` — MED-7.
4. `docs(audit): close MED-4/MED-6/MED-7 + regression note in §11` — actualización del audit doc.

El orden 1 → 2 → 3 → 4 permite revertir solo el factory (commit 3) sin perder el fix de runtime, si surgiera algún problema con el bypass de marshallers.

---

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| El bypass de marshallers en `fromEmailIngest()` rompe alguna conversión de tipo silenciosa | Suite completa después de adoptar el factory; revisar logs de cualquier failed save |
| Algún callsite de `getEntityComponents()` no detectado por grep | Grep final por `getEntityComponents` debe retornar 0 hits antes de mergear |
| Cambiar firma del constructor de `TicketIngestionService` rompe algún caller externo | `GmailImportService::fromSettings` es el único caller (verificado). El parámetro `$notifications` que se renombra a `$n8n` solo se usaba allí como `null` |
| El test de smoke de Gmail no se puede correr sin credenciales | Test unitario de construcción es suficiente para bloquear la regresión; el smoke es opcional |

---

## 7. Definición de hecho

- [ ] `composer test` verde.
- [ ] `composer cs-check` sin diferencia respecto al baseline.
- [ ] `phpstan analyse src` sin nuevos errores respecto al baseline.
- [ ] `grep -r "getEntityComponents" src/` retorna 0 hits.
- [ ] §11 de la auditoría actualizada con las entradas del 2026-05-16.
- [ ] 4 commits en `main` o branch dedicada.
