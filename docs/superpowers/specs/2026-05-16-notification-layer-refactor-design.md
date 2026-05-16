# Diseño — Refactor de la capa de notificaciones de tickets

- **Fecha:** 2026-05-16
- **Autor:** Alexander
- **Origen:** auditoría `docs/audits/2026-05-14-tickets-module-audit.md` — cluster **HIGH-5 + HIGH-6 + MED-1**
- **Estado:** aprobado en brainstorming; pendiente de plan de implementación

---

## 1. Objetivo

Cerrar tres hallazgos correlacionados en la capa de notificaciones de tickets:

| ID | Hallazgo | Síntoma actual |
|---|---|---|
| HIGH-5 | Strategy ausente: `switch ($notificationType)` viola OCP | `TicketNotificationService.php:105-136` ramifica por string mágico |
| HIGH-6 | Adapters externos sin interfaz común; viola DIP | `EmailService`, `WhatsappService`, `N8nService` son clases concretas sin contrato compartido |
| MED-1 | Asimetría EDA: `sendResponseNotifications` se invoca directo, no por bus | `TicketPipelineService.php:222` salta el `EventManager` |

Adicionalmente se cierra un **bug latente de duplicación** detectado durante el diseño: cuando `handleResponse` cambia el estado sin comentario, el email `status_change` se envía dos veces — una por el dispatch de `TicketStatusChanged` (línea 219) y otra por la rama `elseif ($hasStatusChange)` en `TicketNotificationService:186`.

## 2. Restricciones y no-objetivos

**Restricciones que el diseño respeta:**
- Capa de persistencia no se toca (CRIT-3 Outbox queda fuera de alcance — sigue pendiente).
- Decisión de producto vigente: NO se notifica al asignado en cambios de asignación (commit `6d472b7`). El refactor no la reintroduce.
- Lazy DI ya aplicada por CR-024 se conserva.
- `TemplateRegistry` (post `0bf06e2`) es el único origen de templates de email.
- Firmas públicas de `TicketPipelineService::handleResponse` no cambian; sólo cambia el dispatch interno.

**No-objetivos:**
- No migrar a colas (HIGH-4 Bulkhead) — depende de CRIT-3.
- No tocar la ingesta de email (`GmailImportService`, `TicketIngestionService`).
- No incluir N8n como canal de notificación de tickets: hoy se usa sólo en flujos de ingesta, no en dispatch de eventos de dominio.
- No abordar MED-2 (ClockInterface) ni MED-6 (lazy DI uniforme) — quedan para otra iteración.

## 3. Diseño

### 3.1 Eventos nuevos del dominio (cierra MED-1)

Dos eventos nuevos en `App\Domain\Event\`:

- **`TicketCommentAdded(int $ticketId, int $commentId, int $actorId, bool $isPublic)`** — comentario público sin cambio de estado.
- **`TicketResponded(int $ticketId, int $commentId, string $oldStatus, string $newStatus, int $actorId)`** — comentario público con cambio de estado.

`TicketStatusChanged` se conserva sin cambios para el caso "status puro sin comentario".

**Regla anti-duplicación en `handleResponse`:** cuando se va a emitir `TicketResponded`, NO se agrega `TicketStatusChanged` a `$pendingEvents`. El "response email" cubre ambos efectos.

Las 3 ramas semánticas de `handleResponse` quedan así:

| Condición | Evento emitido |
|---|---|
| `hasPublicComment && hasStatusChange` | `TicketResponded` |
| `hasPublicComment && !hasStatusChange` | `TicketCommentAdded` |
| `!hasPublicComment && hasStatusChange` | `TicketStatusChanged` (sin cambios) |

### 3.2 Capa de canales (cierra HIGH-6)

Nuevo namespace `App\Notification\Channel\`.

**`NotificationMessage`** — value object inmutable:

```
- channel: string         // 'email' | 'whatsapp'
- recipient: string       // email o phone
- subject: ?string        // null para WhatsApp
- bodyHtml: ?string       // para email
- bodyText: ?string       // para WhatsApp / fallback
- additionalTo: string[]
- additionalCc: string[]
- attachments: array      // shape compatible con GenericAttachmentTrait
- metadata: array         // ticket_id, event_id, correlation_id futuro
```

**`NotificationChannel`** — interfaz:

```php
public function name(): string;                              // 'email' | 'whatsapp'
public function send(NotificationMessage $message): bool;    // true si entregado al transport
```

**Adaptadores concretos**:
- **`EmailChannel`** — envuelve `EmailService` reducido. Recibe `NotificationMessage` ya renderizado y delega al transporte.
- **`WhatsappChannel`** — envuelve `WhatsappService`. Mismo patrón.

**División de responsabilidades:**
- **Canal = transporte puro.** No conoce eventos de dominio ni templates. Recibe contenido listo.
- **Strategy = rendering.** Conoce el evento, recarga el aggregate, resuelve recipients, renderiza templates.
- `EmailService` se reduce a transporte SMTP/Gmail + utilidades de adjuntos. Sus 4 métodos públicos heterogéneos (`sendNewEntityNotification`, `sendEntityStatusChangeNotification`, `sendEntityCommentNotification`, `sendEntityResponseNotification`) se eliminan al final de la migración.

### 3.3 Strategies por evento (cierra HIGH-5)

Nuevo namespace `App\Notification\Strategy\`.

**`TicketNotificationStrategy`** — interfaz:

```php
public function supports(EventInterface $event): bool;
public function buildMessages(EventInterface $event): iterable; // <NotificationMessage>
```

**Strategies concretas:**

| Strategy | Evento | Canales que emite |
|---|---|---|
| `TicketCreatedStrategy` | `TicketCreated` | Email (requester) + WhatsApp (equipo) |
| `TicketStatusChangedStrategy` | `TicketStatusChanged` | Email (requester) |
| `TicketCommentAddedStrategy` | `TicketCommentAdded` | Email (requester + additional_to/cc) |
| `TicketRespondedStrategy` | `TicketResponded` | Email (requester + additional_to/cc) |

**Responsabilidades por strategy:**
1. Recargar el ticket con los `contain` que necesite (`Requesters`, `Assignees`, `Attachments`).
2. Resolver y filtrar destinatarios (excluir `gmail_user_email`, deduplicar contra el requester, etc.).
3. Renderizar template vía `TemplateRegistry::get(...)->render($ctx)`.
4. Emitir uno o más `NotificationMessage` (uno por canal).
5. Manejo defensivo: errores de rendering retornan iterable vacío + `Log::error`; nunca propagan.

**`TicketNotificationService`** se reduce a orquestador:

```php
public function dispatch(EventInterface $event): void {
    foreach ($this->strategies as $strategy) {
        if (!$strategy->supports($event)) continue;
        foreach ($strategy->buildMessages($event) as $message) {
            $channel = $this->channels[$message->channel] ?? null;
            $channel?->send($message);  // errors logged inside channel
        }
    }
}
```

### 3.4 Listener unificado

`TicketNotificationListener` se simplifica a un puente genérico:

```php
public function implementedEvents(): array {
    return [
        TicketCreated::NAME       => 'forward',
        TicketStatusChanged::NAME => 'forward',
        TicketCommentAdded::NAME  => 'forward',
        TicketResponded::NAME     => 'forward',
    ];
}

public function forward(EventInterface $event): void {
    try {
        $this->notifications()->dispatch($event);
    } catch (Throwable $e) {
        Log::error('TicketNotificationListener::forward failed', [
            'event' => $event::class,
            'error' => $e->getMessage(),
        ]);
    }
}
```

**Beneficios:**
- Un solo método reemplaza `onCreated` + `onStatusChanged` (y los futuros).
- Agregar un evento nuevo = 1 línea en `implementedEvents()` + crear la strategy.
- El listener ya NO recarga el aggregate ni ramifica por tipo — eso vive en la strategy.

### 3.5 Wiring de DI

`TicketNotificationService` recibe dos colecciones (en `TicketServiceInitializerTrait` / `Application::registerDomainEventListeners()`):

```php
new TicketNotificationService(
    strategies: [
        new TicketCreatedStrategy(...),
        new TicketStatusChangedStrategy(...),
        new TicketCommentAddedStrategy(...),
        new TicketRespondedStrategy(...),
    ],
    channels: [
        'email'    => new EmailChannel($emailService),
        'whatsapp' => new WhatsappChannel($whatsappService),
    ],
);
```

Se conserva el patrón `Closure` factory que aplica CR-024: el service se construye perezosamente la primera vez que `forward()` se ejecuta. Las strategies a su vez construyen el `TemplateRegistry` perezosamente.

### 3.6 Cambio en `TicketPipelineService`

La llamada directa a `sendResponseNotifications` en línea 222 desaparece. `handleResponse` agrega al buffer `$pendingEvents`:

- `TicketResponded` cuando `hasPublicComment && hasStatusChange`
- `TicketCommentAdded` cuando `hasPublicComment && !hasStatusChange`
- `TicketStatusChanged` cuando `!hasPublicComment && hasStatusChange` (lógica existente, sin cambios)

El loop existente `foreach ($pendingEvents as $event) $this->eventManager->dispatch($event)` hace el resto. **El service deja de tener acoplamiento con `TicketNotificationService`** — sólo conoce el `EventManager`. DIP cerrado en este flujo.

## 4. Plan de migración

Cada fase es commiteable y deja la suite verde. El código antiguo se conserva hasta la fase final para minimizar la ventana de regresión.

| Fase | Cambios | Validación |
|---|---|---|
| 1. Infraestructura | Crear `NotificationMessage` VO, interfaz `NotificationChannel`, adapters `EmailChannel`/`WhatsappChannel` envolviendo APIs existentes sin tocarlas. | Unit tests de adapters con `EmailService`/`WhatsappService` mockeados. |
| 2. Strategies + interfaz | Crear interfaz `TicketNotificationStrategy` y las 4 implementaciones. Cada strategy recarga ticket, renderiza con `TemplateRegistry`, emite `NotificationMessage`. | Unit tests por strategy: dado un evento → assertions sobre `NotificationMessage` (subject, recipient, channel). |
| 3. Eventos nuevos | Crear `TicketCommentAdded` + `TicketResponded` en `App\Domain\Event\`. Aún sin emisores. | Unit tests sobre constructores y constantes `NAME`. |
| 4. Service + Listener | Reescribir `TicketNotificationService::dispatch(EventInterface)` con strategies+channels. Listener genérico con `forward`. | Test de integración: dispatch evento → mocks de canales reciben los `NotificationMessage` correctos. |
| 5. Emisores | Modificar `TicketPipelineService::handleResponse` para emitir los eventos nuevos a `$pendingEvents` con regla anti-duplicación. Eliminar llamada directa a `sendResponseNotifications`. | Test de `TicketPipelineService` — agregar casos `TicketResponded` emitido y `TicketStatusChanged` suprimido. |
| 6. Limpieza | Eliminar de `EmailService` los 4 métodos viejos. Eliminar `sendResponseNotifications`, `dispatchUpdateNotifications`, `dispatchCreationNotifications`. | `composer test` + `phpstan` + búsqueda de referencias muertas. |

## 5. Cobertura de tests

```
tests/TestCase/Notification/
  Channel/
    EmailChannelTest.php       — adapter envía via EmailService mockeado
    WhatsappChannelTest.php    — adapter envía via WhatsappService mockeado
  Strategy/
    TicketCreatedStrategyTest.php
    TicketStatusChangedStrategyTest.php
    TicketCommentAddedStrategyTest.php
    TicketRespondedStrategyTest.php
  NotificationMessageTest.php  — VO inmutabilidad + validación
tests/TestCase/Service/
  TicketNotificationServiceTest.php  — dispatch routing
  TicketPipelineServiceTest.php      — añadir casos TicketResponded + supresión de TicketStatusChanged
```

Estimación: **~12-15 archivos de test nuevos**, todos pure unit tests salvo los del pipeline que ya tienen helpers.

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Regresión silenciosa: un email deja de enviarse | Test de integración del pipeline asserta sobre `EventManager` + spy en mock channels. Smoke manual con preview de email antes de borrar el código viejo. |
| Cambio de orden en envíos (creation: Email→WhatsApp vs WhatsApp→Email) | Strategies retornan `iterable` ordenado; service itera en orden de declaración. Test verifica orden. |
| Duplicación de email en flujo `TicketResponded` + `TicketStatusChanged` | Regla explícita en `handleResponse`: si `hasPublicComment && hasStatusChange`, NO se agrega `TicketStatusChanged` al buffer. Test del pipeline asserta esto. |
| Lazy DI rota | Conservar `Closure` factory pattern actual; el service interno se construye en `forward` del listener. |
| `TemplateRegistry` no encuentra template nuevo | Strategies caen back a `Log::error` y retornan iterable vacío (no propagan). |

## 7. Validación final pre-merge

- `composer cs-fix && composer cs-check` — sin nuevos errores sobre baseline.
- `composer test` — toda la suite verde. Target: 94 baseline + ~15 nuevos ≈ 109 tests.
- `vendor/bin/phpstan analyse src` — sin nuevos errores sobre baseline.
- Smoke manual: crear ticket, agregar comentario público, cambiar estado, verificar que los 3 emails (creación / comentario / response combinado) llegan a la bandeja real sin duplicación.

## 8. Documentación a actualizar

- `docs/audits/2026-05-14-tickets-module-audit.md` — entrada nueva en §11 cerrando HIGH-5, HIGH-6, MED-1; actualizar §1 y §2.
- `CLAUDE.md` §"Notifications and integrations" — reflejar Strategy + Channel + nuevos eventos como la regla del módulo.

## 9. Hallazgos derivados que quedan pendientes

- **CRIT-3** (Transactional Outbox) — no cambia con este refactor; sigue siendo el siguiente paso crítico.
- **HIGH-4** (Bulkhead vía colas) — depende de CRIT-3.
- **MED-2** (ClockInterface en `TicketPipelineService`), **MED-3** (logging con `correlation_id`), **MED-6** (lazy DI uniforme).
- **N8nService como canal de notificación** — si en el futuro se desea publicar eventos de tickets a n8n, basta crear `N8nChannel` y suscribirlo en la strategy correspondiente; el diseño lo permite sin tocar otras partes.
