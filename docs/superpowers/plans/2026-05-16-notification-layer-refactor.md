# Notification Layer Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el `switch` en `TicketNotificationService` y los métodos heterogéneos de `EmailService`/`WhatsappService` por una arquitectura de Strategy-por-evento + canales polimórficos, y mover los flujos `comment` y `response` al bus de eventos del dominio.

**Architecture:** Eventos de dominio (`TicketCreated`, `TicketStatusChanged`, `TicketCommentAdded`, `TicketResponded`) → listener genérico que reenvía al `TicketNotificationService` → strategies por evento construyen `NotificationMessage` value objects → canales polimórficos (`EmailChannel`, `WhatsappChannel`) realizan el transporte. `TicketPipelineService` deja de invocar al notification service directamente; sólo publica eventos al `EventManager`.

**Tech Stack:** PHP 8.5+, CakePHP 5.x, PHPUnit (suite "Unit"). Sin nuevas dependencias.

**Spec de referencia:** `docs/superpowers/specs/2026-05-16-notification-layer-refactor-design.md`

---

## Convenciones generales

- Todos los archivos `.php` empiezan con `<?php` y `declare(strict_types=1);`.
- Namespace base de producción: `App\Notification\Channel`, `App\Notification\Strategy`, `App\Domain\Event`.
- Namespace base de tests: `App\Test\TestCase\Notification\Channel`, `App\Test\TestCase\Notification\Strategy`, `App\Test\TestCase\Domain\Event`.
- Cada test extiende `Cake\TestSuite\TestCase`.
- Comandos de PowerShell entre comillas dobles, paths con backslash o forward slash (ambos válidos).
- Antes de cada commit: `composer cs-fix` y `composer cs-check`. Si cs-check encuentra errores en archivos NO tocados por la tarea, ignorarlos (son baseline).
- Cada tarea termina con un commit. Mensajes en inglés siguiendo conventional commits.

---

## File Structure

**Producción (crear):**
```
src/Notification/Channel/
  NotificationMessage.php       — value object inmutable de mensaje a despachar
  NotificationChannel.php       — interfaz (name(), send(NotificationMessage))
  EmailChannel.php              — adapter sobre EmailService
  WhatsappChannel.php           — adapter sobre WhatsappService
src/Notification/Strategy/
  TicketNotificationStrategy.php       — interfaz (supports, buildMessages)
  AbstractTicketStrategy.php           — base con helpers compartidos (fetchTable, renderer, filtro recipients)
  TicketCreatedStrategy.php            — Email + WhatsApp
  TicketStatusChangedStrategy.php      — Email
  TicketCommentAddedStrategy.php       — Email
  TicketRespondedStrategy.php          — Email
src/Domain/Event/
  TicketCommentAdded.php
  TicketResponded.php
```

**Producción (modificar):**
```
src/Service/EmailService.php             — exponer dispatch(NotificationMessage); luego borrar métodos viejos
src/Service/WhatsappService.php          — sin cambios estructurales (sendMessage ya público)
src/Service/TicketNotificationService.php — reescribir como orquestador strategies+channels
src/Listener/TicketNotificationListener.php — forward genérico, suscribir 4 eventos
src/Service/TicketPipelineService.php    — emitir TicketCommentAdded/TicketResponded; eliminar llamada directa
src/Application.php                       — wiring DI con strategies y channels
CLAUDE.md                                 — sección "Notifications and integrations"
docs/audits/2026-05-14-tickets-module-audit.md — §11 cierra HIGH-5/HIGH-6/MED-1
```

**Tests (crear):**
```
tests/TestCase/Notification/Channel/
  NotificationMessageTest.php
  EmailChannelTest.php
  WhatsappChannelTest.php
tests/TestCase/Notification/Strategy/
  TicketCreatedStrategyTest.php
  TicketStatusChangedStrategyTest.php
  TicketCommentAddedStrategyTest.php
  TicketRespondedStrategyTest.php
tests/TestCase/Domain/Event/
  TicketCommentAddedTest.php
  TicketRespondedTest.php
tests/TestCase/Service/
  TicketNotificationServiceTest.php
```

**Tests (modificar):**
```
tests/TestCase/Service/TicketPipelineServiceTest.php  — assertions sobre EventManager, eliminar sendResponseNotifications expectations
```

---

# Fase 1 — Infraestructura de canales

## Task 1: NotificationMessage VO

**Files:**
- Create: `src/Notification/Channel/NotificationMessage.php`
- Test: `tests/TestCase/Notification/Channel/NotificationMessageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Notification/Channel/NotificationMessageTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\NotificationMessage;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

class NotificationMessageTest extends TestCase
{
    public function testEmailFactoryProducesEmailChannelMessage(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Hello',
            bodyHtml: '<p>Hi</p>',
            additionalTo: [['email' => 'cc@example.com', 'name' => 'CC']],
            additionalCc: [],
            attachments: [],
            metadata: ['ticket_id' => 42],
        );

        $this->assertSame('email', $msg->channel);
        $this->assertSame('user@example.com', $msg->recipient);
        $this->assertSame('Hello', $msg->subject);
        $this->assertSame('<p>Hi</p>', $msg->bodyHtml);
        $this->assertNull($msg->bodyText);
        $this->assertSame(42, $msg->metadata['ticket_id']);
    }

    public function testWhatsappFactoryProducesWhatsappChannelMessage(): void
    {
        $msg = NotificationMessage::whatsapp(
            recipient: '+573001234567',
            bodyText: 'Nuevo ticket #T-0001',
            metadata: ['ticket_id' => 1],
        );

        $this->assertSame('whatsapp', $msg->channel);
        $this->assertSame('+573001234567', $msg->recipient);
        $this->assertNull($msg->subject);
        $this->assertNull($msg->bodyHtml);
        $this->assertSame('Nuevo ticket #T-0001', $msg->bodyText);
    }

    public function testEmailFactoryRejectsEmptyRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NotificationMessage::email(recipient: '', subject: 'x', bodyHtml: 'x');
    }

    public function testWhatsappFactoryRejectsEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NotificationMessage::whatsapp(recipient: '+57x', bodyText: '');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Notification/Channel/NotificationMessageTest.php
```

Expected: FAIL with "Class App\Notification\Channel\NotificationMessage not found".

- [ ] **Step 3: Write minimal implementation**

Create `src/Notification/Channel/NotificationMessage.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Channel;

use InvalidArgumentException;

/**
 * Inmutable value object that represents a single notification message
 * ready to be delivered by a channel adapter. Strategies build instances
 * of this class; channels consume them.
 *
 * Use the named factory methods (email(), whatsapp()) instead of the
 * constructor — they enforce per-channel invariants.
 */
final class NotificationMessage
{
    /**
     * @param array<int, array{email: string, name?: string}> $additionalTo
     * @param array<int, array{email: string, name?: string}> $additionalCc
     * @param array<int, mixed> $attachments
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public readonly string $channel,
        public readonly string $recipient,
        public readonly ?string $subject,
        public readonly ?string $bodyHtml,
        public readonly ?string $bodyText,
        public readonly array $additionalTo,
        public readonly array $additionalCc,
        public readonly array $attachments,
        public readonly array $metadata,
    ) {
    }

    /**
     * @param array<int, array{email: string, name?: string}> $additionalTo
     * @param array<int, array{email: string, name?: string}> $additionalCc
     * @param array<int, mixed> $attachments
     * @param array<string, mixed> $metadata
     */
    public static function email(
        string $recipient,
        string $subject,
        string $bodyHtml,
        array $additionalTo = [],
        array $additionalCc = [],
        array $attachments = [],
        array $metadata = [],
    ): self {
        if ($recipient === '') {
            throw new InvalidArgumentException('Email recipient cannot be empty');
        }

        return new self(
            channel: 'email',
            recipient: $recipient,
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: null,
            additionalTo: $additionalTo,
            additionalCc: $additionalCc,
            attachments: $attachments,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function whatsapp(
        string $recipient,
        string $bodyText,
        array $metadata = [],
    ): self {
        if ($recipient === '') {
            throw new InvalidArgumentException('WhatsApp recipient cannot be empty');
        }
        if ($bodyText === '') {
            throw new InvalidArgumentException('WhatsApp body text cannot be empty');
        }

        return new self(
            channel: 'whatsapp',
            recipient: $recipient,
            subject: null,
            bodyHtml: null,
            bodyText: $bodyText,
            additionalTo: [],
            additionalCc: [],
            attachments: [],
            metadata: $metadata,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Notification/Channel/NotificationMessageTest.php
```

Expected: PASS (4 tests).

- [ ] **Step 5: Run cs + commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Channel/NotificationMessage.php tests/TestCase/Notification/Channel/NotificationMessageTest.php
git commit -m "feat(notification): add NotificationMessage value object"
```

---

## Task 2: NotificationChannel interface

**Files:**
- Create: `src/Notification/Channel/NotificationChannel.php`

- [ ] **Step 1: Write the interface**

Create `src/Notification/Channel/NotificationChannel.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * Transport-layer contract: receives a fully-rendered NotificationMessage
 * and delivers it. Implementations must be side-effect-only — they MUST
 * NOT propagate exceptions; errors must be logged and the call return false.
 */
interface NotificationChannel
{
    /**
     * Stable name used by strategies to target this channel (e.g. 'email').
     */
    public function name(): string;

    /**
     * Deliver the message. Returns true if accepted by the underlying
     * transport, false on any failure (already logged by the adapter).
     */
    public function send(NotificationMessage $message): bool;
}
```

- [ ] **Step 2: Verify autoload**

```
composer dump-autoload
```

Expected: "Generated optimized autoload files".

- [ ] **Step 3: Commit**

```
composer cs-fix
git add src/Notification/Channel/NotificationChannel.php
git commit -m "feat(notification): add NotificationChannel interface"
```

---

## Task 3: Expose dispatch() on EmailService

`EmailChannel` necesita una API pública en `EmailService` que reciba un `NotificationMessage` (subject + body ya renderizados) y haga el transporte. Hoy `sendEmail` es privado. Lo exponemos como nuevo método público sin tocar el resto.

**Files:**
- Modify: `src/Service/EmailService.php`

- [ ] **Step 1: Add public dispatch(NotificationMessage) method**

Open `src/Service/EmailService.php`. Add new `use` near the top:

```php
use App\Notification\Channel\NotificationMessage;
```

Then add this public method right above the existing `private function sendEmail(...)` (around line 205):

```php
/**
 * Transport entry-point used by EmailChannel. Delegates to the same
 * Gmail-backed implementation as the legacy methods but accepts an
 * already-rendered NotificationMessage instead of per-event arguments.
 */
public function dispatch(NotificationMessage $message): bool
{
    if ($message->channel !== 'email') {
        return false;
    }

    return $this->sendEmail(
        to: $message->recipient,
        subject: (string)$message->subject,
        body: (string)$message->bodyHtml,
        attachments: $message->attachments,
        additionalTo: $message->additionalTo,
        additionalCc: $message->additionalCc,
    );
}
```

- [ ] **Step 2: Run full suite to confirm nothing regressed**

```
composer test
```

Expected: PASS (94 tests baseline; no new tests added yet).

- [ ] **Step 3: Commit**

```
composer cs-fix
git add src/Service/EmailService.php
git commit -m "feat(email): expose dispatch(NotificationMessage) on EmailService"
```

---

## Task 4: EmailChannel adapter

**Files:**
- Create: `src/Notification/Channel/EmailChannel.php`
- Test: `tests/TestCase/Notification/Channel/EmailChannelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Notification/Channel/EmailChannelTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\EmailChannel;
use App\Notification\Channel\NotificationMessage;
use App\Service\EmailService;
use Cake\TestSuite\TestCase;

class EmailChannelTest extends TestCase
{
    public function testNameReturnsEmail(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $channel = new EmailChannel($emailService);
        $this->assertSame('email', $channel->name());
    }

    public function testSendDelegatesToEmailServiceDispatch(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Hello',
            bodyHtml: '<p>Hi</p>',
        );

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('dispatch')
            ->with($msg)
            ->willReturn(true);

        $channel = new EmailChannel($emailService);
        $this->assertTrue($channel->send($msg));
    }

    public function testSendRejectsNonEmailChannelMessages(): void
    {
        $msg = NotificationMessage::whatsapp(recipient: '+57x', bodyText: 'hi');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('dispatch');

        $channel = new EmailChannel($emailService);
        $this->assertFalse($channel->send($msg));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Notification/Channel/EmailChannelTest.php
```

Expected: FAIL with "Class App\Notification\Channel\EmailChannel not found".

- [ ] **Step 3: Write implementation**

Create `src/Notification/Channel/EmailChannel.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Channel;

use App\Service\EmailService;
use Cake\Log\Log;

/**
 * Email transport adapter. Wraps EmailService::dispatch() so the rest of
 * the notification pipeline only sees the NotificationChannel contract.
 */
final class EmailChannel implements NotificationChannel
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function name(): string
    {
        return 'email';
    }

    public function send(NotificationMessage $message): bool
    {
        if ($message->channel !== 'email') {
            Log::warning('EmailChannel received a non-email message; dropping', [
                'channel' => $message->channel,
            ]);

            return false;
        }

        return $this->emailService->dispatch($message);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Notification/Channel/EmailChannelTest.php
```

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Channel/EmailChannel.php tests/TestCase/Notification/Channel/EmailChannelTest.php
git commit -m "feat(notification): add EmailChannel adapter"
```

---

## Task 5: WhatsappChannel adapter

`WhatsappService::sendMessage(string $number, string $text)` ya es público. El adapter sólo extrae los datos del VO y delega.

**Files:**
- Create: `src/Notification/Channel/WhatsappChannel.php`
- Test: `tests/TestCase/Notification/Channel/WhatsappChannelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Notification/Channel/WhatsappChannelTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\NotificationMessage;
use App\Notification\Channel\WhatsappChannel;
use App\Service\WhatsappService;
use Cake\TestSuite\TestCase;

class WhatsappChannelTest extends TestCase
{
    public function testNameReturnsWhatsapp(): void
    {
        $whatsappService = $this->createMock(WhatsappService::class);
        $channel = new WhatsappChannel($whatsappService);
        $this->assertSame('whatsapp', $channel->name());
    }

    public function testSendDelegatesToWhatsappServiceSendMessage(): void
    {
        $msg = NotificationMessage::whatsapp(
            recipient: '+573001234567',
            bodyText: 'New ticket T-0001',
        );

        $whatsappService = $this->createMock(WhatsappService::class);
        $whatsappService->expects($this->once())
            ->method('sendMessage')
            ->with('+573001234567', 'New ticket T-0001')
            ->willReturn(true);

        $channel = new WhatsappChannel($whatsappService);
        $this->assertTrue($channel->send($msg));
    }

    public function testSendRejectsNonWhatsappChannelMessages(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 's',
            bodyHtml: 'b',
        );

        $whatsappService = $this->createMock(WhatsappService::class);
        $whatsappService->expects($this->never())->method('sendMessage');

        $channel = new WhatsappChannel($whatsappService);
        $this->assertFalse($channel->send($msg));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Notification/Channel/WhatsappChannelTest.php
```

Expected: FAIL with "Class App\Notification\Channel\WhatsappChannel not found".

- [ ] **Step 3: Write implementation**

Create `src/Notification/Channel/WhatsappChannel.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Channel;

use App\Service\WhatsappService;
use Cake\Log\Log;

/**
 * WhatsApp transport adapter. Wraps WhatsappService::sendMessage(),
 * extracting recipient and body from the NotificationMessage.
 */
final class WhatsappChannel implements NotificationChannel
{
    public function __construct(private readonly WhatsappService $whatsappService)
    {
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function send(NotificationMessage $message): bool
    {
        if ($message->channel !== 'whatsapp') {
            Log::warning('WhatsappChannel received a non-whatsapp message; dropping', [
                'channel' => $message->channel,
            ]);

            return false;
        }

        return $this->whatsappService->sendMessage(
            $message->recipient,
            (string)$message->bodyText,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Notification/Channel/WhatsappChannelTest.php
```

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Channel/WhatsappChannel.php tests/TestCase/Notification/Channel/WhatsappChannelTest.php
git commit -m "feat(notification): add WhatsappChannel adapter"
```

---

# Fase 2 — Strategies por evento

## Task 6: TicketNotificationStrategy interface

**Files:**
- Create: `src/Notification/Strategy/TicketNotificationStrategy.php`

- [ ] **Step 1: Write interface**

Create `src/Notification/Strategy/TicketNotificationStrategy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Notification\Channel\NotificationMessage;
use Cake\Event\EventInterface;

/**
 * Builds NotificationMessage instances from a domain event. Each
 * implementation owns one event type (or family) and decides which
 * channels receive a message.
 *
 * Implementations MUST NOT throw — errors must be logged and an empty
 * iterable returned so the dispatcher can continue with the next
 * strategy.
 */
interface TicketNotificationStrategy
{
    public function supports(EventInterface $event): bool;

    /**
     * @return iterable<NotificationMessage>
     */
    public function buildMessages(EventInterface $event): iterable;
}
```

- [ ] **Step 2: Commit**

```
composer cs-fix
git add src/Notification/Strategy/TicketNotificationStrategy.php
git commit -m "feat(notification): add TicketNotificationStrategy interface"
```

---

## Task 7: AbstractTicketStrategy base class

Las 4 strategies comparten: acceso a `Tickets` table, `TemplateRegistry`, `NotificationRenderer`, helper de filtrado de recipients, helpers de logging. Extraemos a una clase abstracta.

**Files:**
- Create: `src/Notification/Strategy/AbstractTicketStrategy.php`

- [ ] **Step 1: Write the base class**

Create `src/Notification/Strategy/AbstractTicketStrategy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Constants\SettingKeys;
use App\Notification\Email\TemplateRegistry;
use App\Service\Dto\SystemConfig;
use App\Service\Renderer\NotificationRenderer;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Shared plumbing for ticket-event strategies. Concrete subclasses focus
 * on the event → message mapping and let this class handle lazy
 * collaborators and recipient filtering.
 */
abstract class AbstractTicketStrategy implements TicketNotificationStrategy
{
    use LocatorAwareTrait;

    protected ?NotificationRenderer $renderer = null;
    protected ?TemplateRegistry $templates = null;

    public function __construct(protected readonly ?SystemConfig $config = null)
    {
    }

    abstract public function supports(EventInterface $event): bool;

    /**
     * @return iterable<\App\Notification\Channel\NotificationMessage>
     */
    abstract public function buildMessages(EventInterface $event): iterable;

    protected function renderer(): NotificationRenderer
    {
        return $this->renderer ??= new NotificationRenderer();
    }

    protected function templates(): TemplateRegistry
    {
        return $this->templates ??= new TemplateRegistry();
    }

    /**
     * Filter recipient list, removing duplicates against the requester and
     * the system Gmail user.
     *
     * @param string|array<int, array{email: string, name?: string}>|null $recipients
     * @param array<int, string> $excludeEmails lower-cased
     * @return array<int, array{email: string, name?: string}>
     */
    protected function filterRecipients(string|array|null $recipients, array $excludeEmails): array
    {
        if (empty($recipients)) {
            return [];
        }

        $decoded = is_string($recipients) ? json_decode($recipients, true) : $recipients;
        if (!is_array($decoded)) {
            return [];
        }

        $filtered = [];
        foreach ($decoded as $recipient) {
            if (!empty($recipient['email'])) {
                $email = strtolower((string)$recipient['email']);
                if (!in_array($email, $excludeEmails, true)) {
                    $filtered[] = $recipient;
                }
            }
        }

        return $filtered;
    }

    protected function gmailUserEmail(): string
    {
        $settings = $this->config?->toSettingsArray() ?? [];

        return strtolower((string)($settings[SettingKeys::GMAIL_USER_EMAIL] ?? ''));
    }

    /**
     * Defensive wrapper for the message-building closure. Logs any throwable
     * and returns an empty list so the dispatcher keeps going.
     *
     * @template T
     * @param callable(): iterable<T> $builder
     * @return iterable<T>
     */
    protected function safeBuild(callable $builder, EventInterface $event): iterable
    {
        try {
            return $builder();
        } catch (Throwable $e) {
            Log::error(static::class . ' failed to build messages', [
                'event' => $event->getName(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
```

- [ ] **Step 2: Verify autoload**

```
composer dump-autoload
```

- [ ] **Step 3: Commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Strategy/AbstractTicketStrategy.php
git commit -m "feat(notification): add AbstractTicketStrategy base class"
```

---

## Task 8: TicketCreatedStrategy

Cubre el flujo actual de `dispatchCreationNotifications`: Email al requester + WhatsApp al equipo.

**Files:**
- Create: `src/Notification/Strategy/TicketCreatedStrategy.php`
- Test: `tests/TestCase/Notification/Strategy/TicketCreatedStrategyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Notification/Strategy/TicketCreatedStrategyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketCreatedStrategy;
use Cake\TestSuite\TestCase;

class TicketCreatedStrategyTest extends TestCase
{
    public function testSupportsTicketCreatedOnly(): void
    {
        $strategy = new TicketCreatedStrategy();
        $created = new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual');
        $statusChanged = new TicketStatusChanged(1, 'abierto', 'resuelto', 2);

        $this->assertTrue($strategy->supports($created));
        $this->assertFalse($strategy->supports($statusChanged));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketCannotBeLoaded(): void
    {
        // Strategy must NOT throw when the ticket is missing — returns empty
        // iterable so the dispatcher can continue with other strategies.
        $strategy = new TicketCreatedStrategy();
        $event = new TicketCreated(ticketId: 999999, requesterId: 0, source: 'manual');

        $messages = iterator_to_array($strategy->buildMessages($event), false);

        $this->assertSame([], $messages);
    }
}
```

> Nota: tests más profundos (assertions sobre subject/recipient) requieren fixtures de tabla y se postergan a Task 16 (TicketNotificationServiceTest), donde se mockea la strategy completa.

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Notification/Strategy/TicketCreatedStrategyTest.php
```

Expected: FAIL with "Class TicketCreatedStrategy not found".

- [ ] **Step 3: Write implementation**

Create `src/Notification/Strategy/TicketCreatedStrategy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Constants\SettingKeys;
use App\Domain\Event\TicketCreated;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Generator;

/**
 * Builds notifications for TicketCreated:
 *  - Email to the requester (template: ticket_created)
 *  - WhatsApp to the tickets team number (text rendered via NotificationRenderer)
 *
 * WhatsApp recipient (config WHATSAPP_TICKETS_NUMBER) is resolved at
 * dispatch time by WhatsappChannel; if missing or disabled, the channel
 * logs and skips. The strategy still emits the message — channel-level
 * gating is the channel's responsibility.
 */
final class TicketCreatedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketCreated;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn (): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketCreated) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters']);

        // Email
        $excludeEmails = array_filter([
            strtolower((string)($ticket->requester->email ?? '')),
            $this->gmailUserEmail(),
        ]);

        $emailTo = $this->filterRecipients($ticket->email_to ?? null, $excludeEmails);
        $emailCc = $this->filterRecipients($ticket->email_cc ?? null, $excludeEmails);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
        );
        $rendered = $this->templates()->get('ticket_created')->render($ctx);

        if (!empty($ticket->requester->email)) {
            yield NotificationMessage::email(
                recipient: (string)$ticket->requester->email,
                subject: $rendered->subject,
                bodyHtml: $rendered->bodyHtml,
                additionalTo: $emailTo,
                additionalCc: $emailCc,
                metadata: ['ticket_id' => $ticket->id, 'event' => $event->getName()],
            );
        }

        // WhatsApp — recipient resolution is delegated to the channel/service,
        // which reads WHATSAPP_TICKETS_NUMBER from settings. We pass a sentinel
        // recipient that the channel maps to the configured number.
        $waNumber = (string)($this->config?->toSettingsArray()[SettingKeys::WHATSAPP_TICKETS_NUMBER] ?? '');
        if ($waNumber !== '') {
            $text = $this->renderer()->renderWhatsappNewTicket($ticket);
            yield NotificationMessage::whatsapp(
                recipient: $waNumber,
                bodyText: $text,
                metadata: ['ticket_id' => $ticket->id, 'event' => $event->getName()],
            );
        } else {
            Log::info('WhatsApp tickets number not configured, skipping WhatsApp message');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Notification/Strategy/TicketCreatedStrategyTest.php
```

Expected: PASS (2 tests). The "ticket missing" case relies on `safeBuild` swallowing the `RecordNotFoundException` thrown by `get(999999)`.

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Strategy/TicketCreatedStrategy.php tests/TestCase/Notification/Strategy/TicketCreatedStrategyTest.php
git commit -m "feat(notification): add TicketCreatedStrategy"
```

---

## Task 9: TicketStatusChangedStrategy

**Files:**
- Create: `src/Notification/Strategy/TicketStatusChangedStrategy.php`
- Test: `tests/TestCase/Notification/Strategy/TicketStatusChangedStrategyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Notification/Strategy/TicketStatusChangedStrategyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Notification\Strategy\TicketStatusChangedStrategy;
use Cake\TestSuite\TestCase;

class TicketStatusChangedStrategyTest extends TestCase
{
    public function testSupportsTicketStatusChangedOnly(): void
    {
        $strategy = new TicketStatusChangedStrategy();
        $statusChanged = new TicketStatusChanged(1, 'abierto', 'resuelto', 2);
        $created = new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual');

        $this->assertTrue($strategy->supports($statusChanged));
        $this->assertFalse($strategy->supports($created));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketCannotBeLoaded(): void
    {
        $strategy = new TicketStatusChangedStrategy();
        $event = new TicketStatusChanged(999999, 'abierto', 'resuelto', null);

        $messages = iterator_to_array($strategy->buildMessages($event), false);

        $this->assertSame([], $messages);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Notification/Strategy/TicketStatusChangedStrategyTest.php
```

Expected: FAIL with "Class TicketStatusChangedStrategy not found".

- [ ] **Step 3: Write implementation**

Create `src/Notification/Strategy/TicketStatusChangedStrategy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Domain\Event\TicketStatusChanged;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Generator;

/**
 * Builds the requester-facing email when a ticket transitions between
 * statuses without a public comment. The matching template is
 * `ticket_status_changed`.
 */
final class TicketStatusChangedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketStatusChanged;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn (): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketStatusChanged) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees', 'Attachments']);

        if (empty($ticket->requester->email)) {
            return;
        }

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
            oldStatus: $event->oldStatus,
            newStatus: $event->newStatus,
            actor: $ticket->assignee ?? null,
        );
        $rendered = $this->templates()->get('ticket_status_changed')->render($ctx);

        yield NotificationMessage::email(
            recipient: (string)$ticket->requester->email,
            subject: $rendered->subject,
            bodyHtml: $rendered->bodyHtml,
            metadata: [
                'ticket_id' => $ticket->id,
                'event' => $event->getName(),
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Notification/Strategy/TicketStatusChangedStrategyTest.php
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Strategy/TicketStatusChangedStrategy.php tests/TestCase/Notification/Strategy/TicketStatusChangedStrategyTest.php
git commit -m "feat(notification): add TicketStatusChangedStrategy"
```

---

## Task 10: TicketCommentAddedStrategy + TicketRespondedStrategy

Las dos strategies de comentarios comparten estructura (recargar ticket + comment, armar attachments del comment, renderizar template, emitir email con additional_to/cc). Las creamos en la misma task pero en archivos separados.

**Files:**
- Create: `src/Notification/Strategy/TicketCommentAddedStrategy.php`
- Create: `src/Notification/Strategy/TicketRespondedStrategy.php`
- Test: `tests/TestCase/Notification/Strategy/TicketCommentAddedStrategyTest.php`
- Test: `tests/TestCase/Notification/Strategy/TicketRespondedStrategyTest.php`

> Estos eventos no existen aún (se crean en Task 12). Para que las strategies compilen referenciando los nombres, primero declaramos las clases-evento como stubs mínimos en este task, y los completamos en Task 12.
>
> **No, mejor:** reordenamos. Hacemos Task 12 (eventos nuevos) ANTES de Task 10. Saltamos a Task 12 ahora y volvemos.

**REORDENAR EJECUCIÓN: Ejecutar Task 12 antes que Task 10.** El plan documenta el orden lógico arquitectónico; la implementación necesita los eventos primero.

Una vez que `TicketCommentAdded` y `TicketResponded` existen (post-Task 12), continuar aquí.

- [ ] **Step 1: Write the failing test for TicketCommentAddedStrategy**

Create `tests/TestCase/Notification/Strategy/TicketCommentAddedStrategyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
use App\Notification\Strategy\TicketCommentAddedStrategy;
use Cake\TestSuite\TestCase;

class TicketCommentAddedStrategyTest extends TestCase
{
    public function testSupportsTicketCommentAddedOnly(): void
    {
        $strategy = new TicketCommentAddedStrategy();
        $event = new TicketCommentAdded(ticketId: 1, commentId: 10, actorId: 2, isPublic: true);
        $other = new TicketResponded(1, 10, 'abierto', 'resuelto', 2);

        $this->assertTrue($strategy->supports($event));
        $this->assertFalse($strategy->supports($other));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketMissing(): void
    {
        $strategy = new TicketCommentAddedStrategy();
        $event = new TicketCommentAdded(ticketId: 999999, commentId: 999999, actorId: 0, isPublic: true);

        $this->assertSame([], iterator_to_array($strategy->buildMessages($event), false));
    }
}
```

- [ ] **Step 2: Write the failing test for TicketRespondedStrategy**

Create `tests/TestCase/Notification/Strategy/TicketRespondedStrategyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
use App\Notification\Strategy\TicketRespondedStrategy;
use Cake\TestSuite\TestCase;

class TicketRespondedStrategyTest extends TestCase
{
    public function testSupportsTicketRespondedOnly(): void
    {
        $strategy = new TicketRespondedStrategy();
        $event = new TicketResponded(1, 10, 'abierto', 'resuelto', 2);
        $other = new TicketCommentAdded(ticketId: 1, commentId: 10, actorId: 2, isPublic: true);

        $this->assertTrue($strategy->supports($event));
        $this->assertFalse($strategy->supports($other));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketMissing(): void
    {
        $strategy = new TicketRespondedStrategy();
        $event = new TicketResponded(999999, 999999, 'abierto', 'resuelto', null);

        $this->assertSame([], iterator_to_array($strategy->buildMessages($event), false));
    }
}
```

- [ ] **Step 3: Run both tests to verify they fail**

```
vendor\bin\phpunit tests/TestCase/Notification/Strategy/TicketCommentAddedStrategyTest.php tests/TestCase/Notification/Strategy/TicketRespondedStrategyTest.php
```

Expected: FAIL with "Class TicketCommentAddedStrategy not found" and "Class TicketRespondedStrategy not found".

- [ ] **Step 4: Implement TicketCommentAddedStrategy**

Create `src/Notification/Strategy/TicketCommentAddedStrategy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Domain\Event\TicketCommentAdded;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Generator;

/**
 * Builds the requester-facing email when a public comment is added to a
 * ticket WITHOUT a status change. Template: `ticket_comment_added`.
 *
 * The strategy reloads ticket + comment so it can render the actor name
 * and attachment list independent of the dispatcher state.
 */
final class TicketCommentAddedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketCommentAdded;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn (): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketCommentAdded) {
            return;
        }

        if (!$event->isPublic) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees', 'Attachments']);
        $comment = $this->fetchTable('TicketComments')->get($event->commentId, contain: ['Users']);

        if (empty($ticket->requester->email)) {
            return;
        }

        $commentAttachments = [];
        if (!empty($ticket->attachments)) {
            foreach ($ticket->attachments as $attachment) {
                if ($attachment->comment_id === $comment->id && !$attachment->is_inline) {
                    $commentAttachments[] = $attachment;
                }
            }
        }

        $excludeEmails = array_filter([
            strtolower((string)($ticket->requester->email ?? '')),
            $this->gmailUserEmail(),
        ]);
        $emailTo = $this->filterRecipients($comment->email_to ?? $ticket->email_to ?? null, $excludeEmails);
        $emailCc = $this->filterRecipients($comment->email_cc ?? $ticket->email_cc ?? null, $excludeEmails);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
            comment: $comment,
            actor: $comment->user ?? null,
            commentAttachments: $commentAttachments,
        );
        $rendered = $this->templates()->get('ticket_comment_added')->render($ctx);

        yield NotificationMessage::email(
            recipient: (string)$ticket->requester->email,
            subject: $rendered->subject,
            bodyHtml: $rendered->bodyHtml,
            additionalTo: $emailTo,
            additionalCc: $emailCc,
            attachments: $commentAttachments,
            metadata: ['ticket_id' => $ticket->id, 'comment_id' => $comment->id, 'event' => $event->getName()],
        );
    }
}
```

- [ ] **Step 5: Implement TicketRespondedStrategy**

Create `src/Notification/Strategy/TicketRespondedStrategy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Domain\Event\TicketResponded;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Generator;

/**
 * Builds the requester-facing email when a public comment AND a status
 * change happen together (the "response" flow in handleResponse).
 * Template: `ticket_updated` — combines comment body + status transition
 * in a single message to avoid duplicate emails.
 */
final class TicketRespondedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketResponded;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn (): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketResponded) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees', 'Attachments']);
        $comment = $this->fetchTable('TicketComments')->get($event->commentId, contain: ['Users']);

        if (empty($ticket->requester->email)) {
            return;
        }

        $commentAttachments = [];
        if (!empty($ticket->attachments)) {
            foreach ($ticket->attachments as $attachment) {
                if ($attachment->comment_id === $comment->id && !$attachment->is_inline) {
                    $commentAttachments[] = $attachment;
                }
            }
        }

        $excludeEmails = array_filter([
            strtolower((string)($ticket->requester->email ?? '')),
            $this->gmailUserEmail(),
        ]);
        $emailTo = $this->filterRecipients($comment->email_to ?? $ticket->email_to ?? null, $excludeEmails);
        $emailCc = $this->filterRecipients($comment->email_cc ?? $ticket->email_cc ?? null, $excludeEmails);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
            comment: $comment,
            oldStatus: $event->oldStatus,
            newStatus: $event->newStatus,
            actor: $comment->user ?? null,
            commentAttachments: $commentAttachments,
        );
        $rendered = $this->templates()->get('ticket_updated')->render($ctx);

        yield NotificationMessage::email(
            recipient: (string)$ticket->requester->email,
            subject: $rendered->subject,
            bodyHtml: $rendered->bodyHtml,
            additionalTo: $emailTo,
            additionalCc: $emailCc,
            attachments: $commentAttachments,
            metadata: [
                'ticket_id' => $ticket->id,
                'comment_id' => $comment->id,
                'event' => $event->getName(),
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ],
        );
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

```
vendor\bin\phpunit tests/TestCase/Notification/Strategy/
```

Expected: PASS (8 tests total across 4 strategy test files).

- [ ] **Step 7: Commit**

```
composer cs-fix
composer cs-check
git add src/Notification/Strategy/TicketCommentAddedStrategy.php src/Notification/Strategy/TicketRespondedStrategy.php tests/TestCase/Notification/Strategy/TicketCommentAddedStrategyTest.php tests/TestCase/Notification/Strategy/TicketRespondedStrategyTest.php
git commit -m "feat(notification): add comment/response ticket strategies"
```

---

# Fase 3 — Eventos nuevos del dominio

## Task 11: TicketCommentAdded event

**EJECUTAR ANTES DE TASK 10.**

**Files:**
- Create: `src/Domain/Event/TicketCommentAdded.php`
- Test: `tests/TestCase/Domain/Event/TicketCommentAddedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Domain/Event/TicketCommentAddedTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Event;

use App\Domain\Event\TicketCommentAdded;
use Cake\TestSuite\TestCase;

class TicketCommentAddedTest extends TestCase
{
    public function testConstructorStoresPayloadAndExposesName(): void
    {
        $event = new TicketCommentAdded(
            ticketId: 42,
            commentId: 100,
            actorId: 7,
            isPublic: true,
        );

        $this->assertSame('Ticket.commentAdded', TicketCommentAdded::NAME);
        $this->assertSame(42, $event->ticketId);
        $this->assertSame(100, $event->commentId);
        $this->assertSame(7, $event->actorId);
        $this->assertTrue($event->isPublic);
        $this->assertSame('Ticket.commentAdded', $event->getName());

        $payload = $event->getData();
        $this->assertSame(42, $payload['ticketId']);
        $this->assertSame(100, $payload['commentId']);
        $this->assertSame(7, $payload['actorId']);
        $this->assertTrue($payload['isPublic']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Domain/Event/TicketCommentAddedTest.php
```

Expected: FAIL with "Class TicketCommentAdded not found".

- [ ] **Step 3: Write implementation**

Create `src/Domain/Event/TicketCommentAdded.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a public comment is added to a ticket WITHOUT a
 * status change. The matching status-change-only event is
 * TicketStatusChanged; the combined response is TicketResponded.
 */
final class TicketCommentAdded extends DomainEvent
{
    public const NAME = 'Ticket.commentAdded';

    public function __construct(
        public readonly int $ticketId,
        public readonly int $commentId,
        public readonly int $actorId,
        public readonly bool $isPublic,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'commentId' => $commentId,
            'actorId' => $actorId,
            'isPublic' => $isPublic,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Domain/Event/TicketCommentAddedTest.php
```

Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Domain/Event/TicketCommentAdded.php tests/TestCase/Domain/Event/TicketCommentAddedTest.php
git commit -m "feat(domain): add TicketCommentAdded event"
```

---

## Task 12: TicketResponded event

**Files:**
- Create: `src/Domain/Event/TicketResponded.php`
- Test: `tests/TestCase/Domain/Event/TicketRespondedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Domain/Event/TicketRespondedTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Event;

use App\Domain\Event\TicketResponded;
use Cake\TestSuite\TestCase;

class TicketRespondedTest extends TestCase
{
    public function testConstructorStoresPayloadAndExposesName(): void
    {
        $event = new TicketResponded(
            ticketId: 42,
            commentId: 100,
            oldStatus: 'abierto',
            newStatus: 'resuelto',
            actorId: 7,
        );

        $this->assertSame('Ticket.responded', TicketResponded::NAME);
        $this->assertSame(42, $event->ticketId);
        $this->assertSame(100, $event->commentId);
        $this->assertSame('abierto', $event->oldStatus);
        $this->assertSame('resuelto', $event->newStatus);
        $this->assertSame(7, $event->actorId);

        $payload = $event->getData();
        $this->assertSame(42, $payload['ticketId']);
        $this->assertSame('resuelto', $payload['newStatus']);
    }

    public function testActorIdMayBeNull(): void
    {
        $event = new TicketResponded(1, 1, 'a', 'b', null);
        $this->assertNull($event->actorId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Domain/Event/TicketRespondedTest.php
```

Expected: FAIL with "Class TicketResponded not found".

- [ ] **Step 3: Write implementation**

Create `src/Domain/Event/TicketResponded.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a public comment AND a status change are committed
 * together (the "response" flow in TicketPipelineService::handleResponse).
 *
 * When this event is emitted, the caller MUST NOT also dispatch
 * TicketStatusChanged for the same transition — the matching strategy
 * sends one combined email covering both effects.
 */
final class TicketResponded extends DomainEvent
{
    public const NAME = 'Ticket.responded';

    public function __construct(
        public readonly int $ticketId,
        public readonly int $commentId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'commentId' => $commentId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'actorId' => $actorId,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor\bin\phpunit tests/TestCase/Domain/Event/TicketRespondedTest.php
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Domain/Event/TicketResponded.php tests/TestCase/Domain/Event/TicketRespondedTest.php
git commit -m "feat(domain): add TicketResponded event"
```

> Después de este task, **regresar a Task 10** (comment/response strategies).

---

# Fase 4 — Service + Listener

## Task 13: TicketNotificationService refactor

Reemplazamos los métodos `dispatchCreationNotifications`, `dispatchUpdateNotifications` y `sendResponseNotifications` por un único `dispatch(EventInterface)`. Los métodos viejos se conservan temporalmente para no romper consumidores hasta Task 17.

**Files:**
- Modify: `src/Service/TicketNotificationService.php`
- Test: `tests/TestCase/Service/TicketNotificationServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Service/TicketNotificationServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Notification\Channel\NotificationChannel;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketNotificationStrategy;
use App\Service\TicketNotificationService;
use Cake\Event\Event;
use Cake\TestSuite\TestCase;

class TicketNotificationServiceTest extends TestCase
{
    public function testDispatchRoutesMessagesToMatchingChannels(): void
    {
        $emailMsg = NotificationMessage::email('a@b.c', 's', '<p>b</p>');
        $waMsg = NotificationMessage::whatsapp('+57x', 'hello');

        $strategy = $this->createMock(TicketNotificationStrategy::class);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('buildMessages')->willReturn([$emailMsg, $waMsg]);

        $emailChannel = $this->createMock(NotificationChannel::class);
        $emailChannel->method('name')->willReturn('email');
        $emailChannel->expects($this->once())->method('send')->with($emailMsg)->willReturn(true);

        $waChannel = $this->createMock(NotificationChannel::class);
        $waChannel->method('name')->willReturn('whatsapp');
        $waChannel->expects($this->once())->method('send')->with($waMsg)->willReturn(true);

        $service = new TicketNotificationService(
            strategies: [$strategy],
            channels: [$emailChannel, $waChannel],
        );

        $service->dispatch(new Event('Ticket.created'));
    }

    public function testDispatchSkipsStrategiesThatDoNotSupportTheEvent(): void
    {
        $strategy = $this->createMock(TicketNotificationStrategy::class);
        $strategy->method('supports')->willReturn(false);
        $strategy->expects($this->never())->method('buildMessages');

        $channel = $this->createMock(NotificationChannel::class);
        $channel->method('name')->willReturn('email');
        $channel->expects($this->never())->method('send');

        $service = new TicketNotificationService(
            strategies: [$strategy],
            channels: [$channel],
        );

        $service->dispatch(new Event('Ticket.created'));
    }

    public function testDispatchSilentlyDropsMessageWhenNoChannelMatches(): void
    {
        $msg = NotificationMessage::whatsapp('+57x', 'hello');

        $strategy = $this->createMock(TicketNotificationStrategy::class);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('buildMessages')->willReturn([$msg]);

        $emailOnly = $this->createMock(NotificationChannel::class);
        $emailOnly->method('name')->willReturn('email');
        $emailOnly->expects($this->never())->method('send');

        $service = new TicketNotificationService(
            strategies: [$strategy],
            channels: [$emailOnly],
        );

        $service->dispatch(new Event('Ticket.created'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
vendor\bin\phpunit tests/TestCase/Service/TicketNotificationServiceTest.php
```

Expected: FAIL — constructor signature mismatch (existing service takes SystemConfig, not strategies/channels).

- [ ] **Step 3: Rewrite TicketNotificationService**

Replace the contents of `src/Service/TicketNotificationService.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Notification\Channel\NotificationChannel;
use App\Notification\Strategy\TicketNotificationStrategy;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Throwable;

/**
 * Orchestrates ticket notifications: takes a domain event, asks each
 * strategy whether it supports it, lets the supporting strategies build
 * NotificationMessage instances, then routes each message to the channel
 * whose name() matches the message's channel field.
 *
 * The service has no event-type knowledge — adding a new event is two
 * file changes: a new strategy and one new line in the listener.
 */
class TicketNotificationService
{
    /** @var list<\App\Notification\Strategy\TicketNotificationStrategy> */
    private array $strategies;

    /** @var array<string, \App\Notification\Channel\NotificationChannel> */
    private array $channels;

    /**
     * @param list<\App\Notification\Strategy\TicketNotificationStrategy> $strategies
     * @param list<\App\Notification\Channel\NotificationChannel> $channels
     */
    public function __construct(array $strategies = [], array $channels = [])
    {
        $this->strategies = $strategies;
        $this->channels = [];
        foreach ($channels as $channel) {
            $this->channels[$channel->name()] = $channel;
        }
    }

    public function dispatch(EventInterface $event): void
    {
        foreach ($this->strategies as $strategy) {
            if (!$strategy->supports($event)) {
                continue;
            }
            try {
                foreach ($strategy->buildMessages($event) as $message) {
                    $channel = $this->channels[$message->channel] ?? null;
                    if ($channel === null) {
                        Log::info('No channel registered for message; dropping', [
                            'channel' => $message->channel,
                            'event' => $event->getName(),
                        ]);
                        continue;
                    }
                    $channel->send($message);
                }
            } catch (Throwable $e) {
                Log::error('TicketNotificationService strategy failed', [
                    'strategy' => $strategy::class,
                    'event' => $event->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
```

> **Importante:** este cambio rompe `TicketPipelineService::__construct` y los tests existentes que esperan `dispatchCreationNotifications`/`sendResponseNotifications` en el service. Esos consumidores se actualizan en Tasks 15 y 16 antes de correr la suite completa.

- [ ] **Step 4: Run only the new test**

```
vendor\bin\phpunit tests/TestCase/Service/TicketNotificationServiceTest.php
```

Expected: PASS (3 tests). La suite completa todavía no pasa — eso es esperado hasta Task 16.

- [ ] **Step 5: Commit (suite intentionally partial)**

```
composer cs-fix
git add src/Service/TicketNotificationService.php tests/TestCase/Service/TicketNotificationServiceTest.php
git commit -m "refactor(notification): TicketNotificationService becomes strategy/channel orchestrator"
```

---

## Task 14: TicketNotificationListener forward genérico

**Files:**
- Modify: `src/Listener/TicketNotificationListener.php`

- [ ] **Step 1: Replace the listener with a generic forwarder**

Replace `src/Listener/TicketNotificationListener.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Listener;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketResponded;
use App\Domain\Event\TicketStatusChanged;
use App\Service\TicketNotificationService;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Closure;
use Throwable;

/**
 * Generic bridge between the global EventManager and the strategy/channel
 * pipeline. The listener does not know about specific events anymore —
 * it forwards every supported event to TicketNotificationService::dispatch().
 *
 * Adding a new ticket event = one new line in implementedEvents() plus a
 * matching strategy. The listener stays untouched.
 *
 * The dispatcher is built lazily via a factory closure so CLI processes
 * that never dispatch a ticket event (`bin/cake migrations migrate`)
 * don't pay the cost of building the service at bootstrap.
 *
 * Any Throwable raised during forwarding is caught and logged. The
 * listener MUST NOT propagate exceptions back to the dispatcher.
 */
final class TicketNotificationListener implements EventListenerInterface
{
    private ?TicketNotificationService $notifications = null;

    /**
     * @param \Closure(): \App\Service\TicketNotificationService $notificationsFactory
     */
    public function __construct(private readonly Closure $notificationsFactory)
    {
    }

    /**
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return [
            TicketCreated::NAME       => 'forward',
            TicketStatusChanged::NAME => 'forward',
            TicketCommentAdded::NAME  => 'forward',
            TicketResponded::NAME     => 'forward',
        ];
    }

    public function forward(EventInterface $event): void
    {
        try {
            $this->notifications()->dispatch($event);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::forward failed', [
                'event' => $event->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifications(): TicketNotificationService
    {
        return $this->notifications ??= ($this->notificationsFactory)();
    }
}
```

- [ ] **Step 2: Commit**

```
composer cs-fix
git add src/Listener/TicketNotificationListener.php
git commit -m "refactor(listener): generic forward to TicketNotificationService"
```

---

## Task 15: Wire DI in Application.php

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 1: Replace the notifications factory**

In `src/Application.php`, locate `registerDomainEventListeners()` (around line 76). Replace the body with:

```php
private function registerDomainEventListeners(): void
{
    // Lazy: notification stack is built only when a ticket event fires.
    $notificationsFactory = static function (): TicketNotificationService {
        $raw = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
        $config = SystemConfig::fromSettingsArray(is_array($raw) ? $raw : null);

        $strategies = [
            new TicketCreatedStrategy($config),
            new TicketStatusChangedStrategy($config),
            new TicketCommentAddedStrategy($config),
            new TicketRespondedStrategy($config),
        ];

        $channels = [
            new EmailChannel(new EmailService($config)),
            new WhatsappChannel(new WhatsappService($config)),
        ];

        return new TicketNotificationService($strategies, $channels);
    };

    EventManager::instance()->on(new TicketNotificationListener($notificationsFactory));
}
```

Add the matching `use` statements at the top of the file (with the others):

```php
use App\Notification\Channel\EmailChannel;
use App\Notification\Channel\WhatsappChannel;
use App\Notification\Strategy\TicketCommentAddedStrategy;
use App\Notification\Strategy\TicketCreatedStrategy;
use App\Notification\Strategy\TicketRespondedStrategy;
use App\Notification\Strategy\TicketStatusChangedStrategy;
use App\Service\EmailService;
use App\Service\WhatsappService;
```

- [ ] **Step 2: Smoke-load Application without running the server**

```
php -r "require 'vendor/autoload.php'; require 'config/bootstrap.php'; echo \"OK\n\";"
```

Expected: "OK" with no fatal errors. If autoload errors appear, run `composer dump-autoload` and retry.

- [ ] **Step 3: Commit**

```
composer cs-fix
git add src/Application.php
git commit -m "refactor(bootstrap): wire strategy/channel DI for notifications"
```

---

# Fase 5 — Emisores

## Task 16: TicketPipelineService emits new events; remove direct call

Este task hace dos cosas que deben ir juntas para que la suite vuelva a verde:
1. `handleResponse` deja de invocar `$this->notifications->sendResponseNotifications(...)`.
2. En su lugar, agrega `TicketCommentAdded`, `TicketResponded`, o `TicketStatusChanged` al buffer `$pendingEvents` según la rama, con la **regla anti-duplicación**: si se emite `TicketResponded`, NO se agrega `TicketStatusChanged`.
3. Tests de `TicketPipelineServiceTest` se actualizan para asertar sobre eventos del bus en lugar de llamadas al notification service.

**Files:**
- Modify: `src/Service/TicketPipelineService.php`
- Modify: `tests/TestCase/Service/TicketPipelineServiceTest.php`

- [ ] **Step 1: Add use imports in TicketPipelineService**

In `src/Service/TicketPipelineService.php`, add to the `use` block near the top:

```php
use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
```

- [ ] **Step 2: Update handleResponse — replace lines 188-220 region**

Locate the block in `handleResponse` that currently looks like:

```php
// TX2: status change ...
if ($hasStatusChange) {
    try {
        $connection->transactional(function () use (..., &$pendingEvents): bool {
            $ok = $this->changeStatus($entity, $newStatus, $userId, null, true, deferDispatch: true);
            if (!$ok) {
                return false;
            }
            $pendingEvents[] = new TicketStatusChanged(...);

            return true;
        });
    } catch (InvalidStatusTransitionException $e) {
        // ...
    }
}

// Post-commit dispatch
foreach ($pendingEvents as $event) {
    $this->eventManager->dispatch($event);
}

$this->notifications->sendResponseNotifications(...);
```

Replace from "TX2: status change" through the `sendResponseNotifications` call with:

```php
// TX2: status change. The TicketStatusChanged event is only buffered when
// there's NO public comment — when there is, TicketResponded covers both
// effects and TicketStatusChanged would duplicate the email.
$hasPublicComment = $hasComment && $commentType === TicketConstants::COMMENT_PUBLIC && $comment !== null;
$emitTicketResponded = $hasPublicComment && $hasStatusChange;

if ($hasStatusChange) {
    try {
        $connection->transactional(function () use (
            $entity,
            $newStatus,
            $oldStatus,
            $userId,
            $emitTicketResponded,
            $comment,
            &$pendingEvents,
        ): bool {
            $ok = $this->changeStatus($entity, $newStatus, $userId, null, true, deferDispatch: true);
            if (!$ok) {
                return false;
            }

            if ($emitTicketResponded) {
                $pendingEvents[] = new TicketResponded(
                    ticketId: (int)$entity->id,
                    commentId: (int)$comment->id,
                    oldStatus: $oldStatus,
                    newStatus: (string)$newStatus,
                    actorId: $userId,
                );
            } else {
                $pendingEvents[] = new TicketStatusChanged(
                    ticketId: (int)$entity->id,
                    oldStatus: $oldStatus,
                    newStatus: (string)$newStatus,
                    actorId: $userId,
                );
            }

            return true;
        });
    } catch (InvalidStatusTransitionException $e) {
        Log::warning('Response committed but status transition rejected', [
            'ticket_id' => $entityId,
            'from' => $oldStatus,
            'to' => $newStatus,
            'error' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'message' => sprintf(
                'Comentario guardado, pero no se pudo cambiar el estado: %s',
                $e->getMessage(),
            ),
            'entity' => $entity,
        ];
    }
}

// Public-comment-only branch (no status change).
if ($hasPublicComment && !$hasStatusChange) {
    $pendingEvents[] = new TicketCommentAdded(
        ticketId: (int)$entity->id,
        commentId: (int)$comment->id,
        actorId: $userId,
        isPublic: true,
    );
}

// Post-commit: dispatch buffered domain events. Notification routing is
// fully delegated to the EventManager — no direct call to the service.
foreach ($pendingEvents as $event) {
    $this->eventManager->dispatch($event);
}
```

> The `$this->notifications->sendResponseNotifications(...)` block (lines 222-232 of the current file) is now **deleted**.

- [ ] **Step 3: Remove the now-unused $this->notifications property usage check**

`$this->notifications` is still declared and constructed but no longer used in `handleResponse`. Leave the property in place for now (Task 18 removes it together with the rest of the legacy surface). Add a comment above the property declaration:

```php
/**
 * @deprecated Kept for backwards-compat with other callers until full cleanup. Notifications flow through the EventManager.
 */
private TicketNotificationService $notifications;
```

> Skip this step if PHPStan complains about the deprecated property — it's a transient state.

- [ ] **Step 4: Update existing TicketPipelineServiceTest**

Open `tests/TestCase/Service/TicketPipelineServiceTest.php`. Two tests fail after Task 13 because the `TicketNotificationService` constructor changed (no longer accepts `SystemConfig`) and `sendResponseNotifications` doesn't exist anymore.

Replace every `$notifications = $this->createMock(TicketNotificationService::class);` block that expects `sendResponseNotifications` with assertions on a captured `EventManager`. Pattern:

```php
$dispatched = [];
$eventManager = new EventManager();
$eventManager->on('Ticket.responded', function ($event) use (&$dispatched): void {
    $dispatched[] = $event;
});
$eventManager->on('Ticket.statusChanged', function ($event) use (&$dispatched): void {
    $dispatched[] = $event;
});
$eventManager->on('Ticket.commentAdded', function ($event) use (&$dispatched): void {
    $dispatched[] = $event;
});
```

Then inject this `$eventManager` into `buildService(...)` and assert:

```php
$this->assertCount(1, $dispatched);
$this->assertInstanceOf(TicketResponded::class, $dispatched[0]->getSubject() ?? $dispatched[0]);
```

> CakePHP wraps domain events; the subject of `Cake\Event\Event` is the data array. Our `DomainEvent` extends `Cake\Event\Event` — assert on `$dispatched[0]` directly being an instance of the domain event class.

Add a new test for the anti-duplication rule:

```php
public function testResponseFlowEmitsTicketRespondedNotStatusChanged(): void
{
    $comment = new TicketComment(['id' => 99]);
    $comment->setNew(false);

    $comments = $this->createMock(TicketCommentService::class);
    $comments->method('addComment')->willReturn($comment);

    $attachments = $this->createMock(TicketAttachmentService::class);
    $notifications = $this->createMock(TicketNotificationService::class);

    $dispatched = [];
    foreach (['Ticket.responded', 'Ticket.statusChanged', 'Ticket.commentAdded'] as $name) {
        $this->eventManager->on($name, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });
    }

    $service = $this->buildService($comments, $attachments, $notifications);
    $this->stubTicketsTable($service);

    $service->handleResponse(
        entityId: 1,
        userId: 7,
        data: [
            'comment_body' => 'we fixed it',
            'comment_type' => TicketConstants::COMMENT_PUBLIC,
            'status' => TicketConstants::STATUS_RESUELTO,
        ],
        files: [],
    );

    $this->assertCount(1, $dispatched, 'Exactly one event should be dispatched');
    $this->assertSame('Ticket.responded', $dispatched[0]->getName());
}

public function testCommentOnlyFlowEmitsTicketCommentAdded(): void
{
    $comment = new TicketComment(['id' => 99]);
    $comment->setNew(false);

    $comments = $this->createMock(TicketCommentService::class);
    $comments->method('addComment')->willReturn($comment);

    $attachments = $this->createMock(TicketAttachmentService::class);
    $notifications = $this->createMock(TicketNotificationService::class);

    $dispatched = [];
    foreach (['Ticket.responded', 'Ticket.statusChanged', 'Ticket.commentAdded'] as $name) {
        $this->eventManager->on($name, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });
    }

    $service = $this->buildService($comments, $attachments, $notifications);
    $this->stubTicketsTable($service);

    $service->handleResponse(
        entityId: 1,
        userId: 7,
        data: [
            'comment_body' => 'note',
            'comment_type' => TicketConstants::COMMENT_PUBLIC,
            'status' => null,
        ],
        files: [],
    );

    $this->assertCount(1, $dispatched);
    $this->assertSame('Ticket.commentAdded', $dispatched[0]->getName());
}
```

> Replace the existing `testHandleResponseDispatchesStatusChangedAfterCommit` (if present) with the response-flow variant above, since the response flow now suppresses `TicketStatusChanged`.

- [ ] **Step 5: Run the pipeline test file**

```
vendor\bin\phpunit tests/TestCase/Service/TicketPipelineServiceTest.php
```

Expected: PASS. If a test still references `sendResponseNotifications` as an expectation on the mock, change `expects($this->never())->method('sendResponseNotifications')` to assert no event was dispatched.

- [ ] **Step 6: Run full suite**

```
composer test
```

Expected: PASS — full suite green. Target: previous baseline (~94) + ~15 new tests = ~109 tests, all green.

- [ ] **Step 7: Commit**

```
composer cs-fix
composer cs-check
git add src/Service/TicketPipelineService.php tests/TestCase/Service/TicketPipelineServiceTest.php
git commit -m "refactor(pipeline): emit TicketResponded/TicketCommentAdded; suppress duplicate status_change"
```

---

# Fase 6 — Limpieza

## Task 17: Remove legacy EmailService methods

Una vez que las strategies usan exclusivamente `TemplateRegistry` y `EmailService::dispatch`, los 4 métodos públicos viejos (`sendNewEntityNotification`, `sendEntityStatusChangeNotification`, `sendEntityCommentNotification`, `sendEntityResponseNotification`) y el helper privado `sendCommentBasedNotification` quedan muertos.

**Files:**
- Modify: `src/Service/EmailService.php`

- [ ] **Step 1: Confirm no consumers remain**

```
git grep -n "sendNewEntityNotification\|sendEntityStatusChangeNotification\|sendEntityCommentNotification\|sendEntityResponseNotification\|sendCommentBasedNotification" -- src tests
```

Expected: only matches inside `src/Service/EmailService.php` itself (the definitions). If anything outside, that caller must be migrated first.

- [ ] **Step 2: Delete the four legacy public methods + private helper**

In `src/Service/EmailService.php`, delete:
- `public function sendNewEntityNotification(EntityInterface $entity): bool`
- `public function sendEntityStatusChangeNotification(EntityInterface $entity, string $oldStatus, string $newStatus): bool`
- `public function sendEntityCommentNotification(EntityInterface $entity, EntityInterface $comment, array $additionalTo = [], array $additionalCc = []): bool`
- `public function sendEntityResponseNotification(EntityInterface $entity, EntityInterface $comment, string $oldStatus, string $newStatus, array $additionalTo = [], array $additionalCc = []): bool`
- `private function sendCommentBasedNotification(string $templateKey, ...): bool`
- `private function filterEmailRecipients(...)` if no longer referenced

Also remove now-unused imports if any (`TemplateContext`, `TemplateRegistry` — keep them only if `dispatch()` still needs them, which it doesn't — they were used by the deleted methods).

- [ ] **Step 3: Run full suite**

```
composer test
```

Expected: PASS, unchanged test count.

- [ ] **Step 4: Run phpstan**

```
vendor\bin\phpstan analyse src/Service/EmailService.php
```

Expected: no new errors over baseline.

- [ ] **Step 5: Commit**

```
composer cs-fix
composer cs-check
git add src/Service/EmailService.php
git commit -m "chore(email): drop legacy per-event methods (replaced by strategies)"
```

---

## Task 18: Remove the legacy notifications property from TicketPipelineService

After Task 17, the `private TicketNotificationService $notifications` field in `TicketPipelineService` is unused.

**Files:**
- Modify: `src/Service/TicketPipelineService.php`

- [ ] **Step 1: Delete the property and the constructor parameter**

In `src/Service/TicketPipelineService.php`:
- Remove `private TicketNotificationService $notifications;`.
- Remove the `?TicketNotificationService $notifications = null,` parameter from `__construct`.
- Remove the `$this->notifications = $notifications ?? new TicketNotificationService(...);` line.
- Remove the `use App\Service\TicketNotificationService;` import if it becomes unused (it does).

- [ ] **Step 2: Update TicketPipelineServiceTest helpers**

In `tests/TestCase/Service/TicketPipelineServiceTest.php`, locate `buildService(...)` and remove the `TicketNotificationService` parameter / construction usage. Drop the `$notifications` argument from every test that calls `buildService`.

- [ ] **Step 3: Run full suite**

```
composer test
```

Expected: PASS.

- [ ] **Step 4: Commit**

```
composer cs-fix
composer cs-check
git add src/Service/TicketPipelineService.php tests/TestCase/Service/TicketPipelineServiceTest.php
git commit -m "chore(pipeline): drop unused TicketNotificationService dependency"
```

---

## Task 19: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Replace the "Notifications and integrations" subsection**

Open `CLAUDE.md`. Find the heading `### Notifications and integrations` (within `## High-level architecture`). Replace the paragraph that begins "Outbound channels (email, WhatsApp, n8n webhooks)..." with:

```markdown
### Notifications and integrations

Outbound channels are wired as adapters that implement `App\Notification\Channel\NotificationChannel` (`EmailChannel`, `WhatsappChannel`). A per-event Strategy under `App\Notification\Strategy\*` builds `NotificationMessage` value objects from a domain event; `TicketNotificationService` routes each message to the channel whose `name()` matches.

To add a new ticket notification:
1. Define a domain event under `App\Domain\Event\` (extending `DomainEvent`).
2. Implement a `TicketNotificationStrategy` that `supports($event)` and `buildMessages($event)`.
3. Subscribe the event in `TicketNotificationListener::implementedEvents()`.
4. Register the strategy in `Application::registerDomainEventListeners()`.

Do NOT call `EmailService`, `WhatsappService`, or any channel directly from controllers or services — publish a domain event and let the listener dispatch.

`GmailImportService` + `TicketIngestionService` cover the inbound side; UTF-8 + markup-safe truncation lives in `TicketIngestionService`. Atomic ticket-number allocation runs through `NumberGenerationService` — don't reintroduce read-modify-write on the counter.
```

- [ ] **Step 2: Commit**

```
git add CLAUDE.md
git commit -m "docs(claude): describe Strategy + Channel notification pipeline"
```

---

## Task 20: Update audit §11

**Files:**
- Modify: `docs/audits/2026-05-14-tickets-module-audit.md`

- [ ] **Step 1: Add §11 entry**

Append to `docs/audits/2026-05-14-tickets-module-audit.md` (under §11 Bitácora de progreso, after the 2026-05-15 entries):

```markdown
### 2026-05-16 — HIGH-5 + HIGH-6 + MED-1 cerrados: capa de notificaciones refactorizada

**Hallazgos cubiertos:** HIGH-5 (Strategy ausente), HIGH-6 (sin interfaz común para canales), MED-1 (asimetría EDA — `sendResponseNotifications` directo). Cerrados como cluster por estar correlacionados.

**Bug latente adicional cerrado:** `handleResponse` enviaba el email `status_change` dos veces cuando había cambio de estado sin comentario — el listener disparaba uno y `sendResponseNotifications` lo repetía.

**Cambios:**
- Nuevo namespace `App\Notification\Channel\*`: `NotificationMessage` (VO), `NotificationChannel` (interfaz), `EmailChannel`, `WhatsappChannel` (adaptadores).
- Nuevo namespace `App\Notification\Strategy\*`: `TicketNotificationStrategy` (interfaz), `AbstractTicketStrategy` (helpers), 4 strategies concretas (`TicketCreatedStrategy`, `TicketStatusChangedStrategy`, `TicketCommentAddedStrategy`, `TicketRespondedStrategy`).
- Eventos nuevos: `TicketCommentAdded`, `TicketResponded`.
- `TicketNotificationService` reescrito como orquestador strategies+channels (constructor cambia: `array $strategies, array $channels`).
- `TicketNotificationListener` simplificado a `forward(EventInterface)` genérico.
- `TicketPipelineService::handleResponse` emite eventos nuevos al `EventManager`; regla anti-duplicación suprime `TicketStatusChanged` cuando se emite `TicketResponded`. La llamada directa a `sendResponseNotifications` se eliminó.
- `EmailService` reducido a transporte: nuevo método público `dispatch(NotificationMessage)`; los 4 métodos por-evento eliminados.
- `Application::registerDomainEventListeners()` wirea las 4 strategies y los 2 canales.

**Despliegue:** sin migraciones, sin cambios de firma pública en controllers, sin variables de entorno nuevas.

**Validaciones:**
- `composer test`: PASS — suite completa verde.
- `phpstan analyse src`: 0 errores nuevos sobre baseline.

**Hallazgos derivados pendientes:**
- CRIT-3 (Outbox) sigue abierto.
- HIGH-4 (Bulkhead vía colas) depende de CRIT-3.
- N8nService como canal de notificación: la arquitectura ahora lo permite con sólo crear `N8nChannel` + suscribirlo en la strategy que aplique.
```

- [ ] **Step 2: Update §1 indicator row**

In the table under §1 "Resumen Ejecutivo", update the "Estado actual" column header date to `2026-05-16` and update counts:
- "Hallazgos Altos (naranja)": `3` → `1` (HIGH-1/2/3/5/6 cerrados; HIGH-4 abierto)
- "Hallazgos Medios (amarillo)": `7` → `6` (MED-1 cerrado)
- Salud arquitectónica global: `78%` → `~85%`

- [ ] **Step 3: Update §2 Matriz de patrones**

In §2 "Matriz de Patrones Detectados", update these rows:
- `EDA / EventManager`: Parcial → **Sí**; "Notif. de response se llaman directo, no por bus" → "Todos los eventos de notificación viajan por bus"; Amarillo → Verde.
- `Adapter (implícito)`: Sí → **Sí**; "Sin interfaz común; viola DIP" → "Interfaz `NotificationChannel` + adapters concretos"; Naranja → Verde.
- `Strategy`: No → **Sí**; "`switch($notificationType)` viola OCP" → "Strategy por evento de dominio"; Naranja → Verde.

- [ ] **Step 4: Update §9 acciones priorizadas**

In §9 "Acciones Priorizadas", mark items #6 (NotificationChannel interface), strategy refactor, and #9 (TicketResponded event) as `**Completado 2026-05-16**`.

- [ ] **Step 5: Commit**

```
git add docs/audits/2026-05-14-tickets-module-audit.md
git commit -m "docs(audit): close HIGH-5/HIGH-6/MED-1 + duplication bug in §11"
```

---

# Validación final pre-merge

- [ ] **Run the whole gate**

```
composer cs-fix
composer cs-check
composer test
vendor\bin\phpstan analyse src
```

Expected:
- `cs-check`: only baseline errors (untouched files).
- `composer test`: PASS, ~109 tests, 0 failures.
- `phpstan`: no NEW errors over baseline (37 pre-existing errors documented in audit §11 are acceptable).

- [ ] **Smoke test manually**

1. `bin/cake server`.
2. Create a ticket via UI → confirm requester receives `ticket_created` email AND WhatsApp message arrives at configured tickets number.
3. Open the ticket as agent → add a public comment WITHOUT status change → confirm requester receives `ticket_comment_added` email exactly once.
4. Add a public comment WITH status change to `resuelto` → confirm requester receives exactly ONE `ticket_updated` email (not two — anti-duplication rule).
5. Change status WITHOUT adding a comment → confirm requester receives `ticket_status_changed` email exactly once.

- [ ] **Final review commit**

If everything passes, optionally consolidate with a tag:

```
git tag refactor/notifications-2026-05-16
```

---

## Notas de orden de ejecución

Las tasks están numeradas en orden arquitectónico, pero hay una dependencia clara:

**Orden de ejecución real:**
1. Task 1 — NotificationMessage
2. Task 2 — NotificationChannel
3. Task 3 — EmailService.dispatch()
4. Task 4 — EmailChannel
5. Task 5 — WhatsappChannel
6. Task 6 — TicketNotificationStrategy interface
7. Task 7 — AbstractTicketStrategy
8. Task 8 — TicketCreatedStrategy
9. Task 9 — TicketStatusChangedStrategy
10. **Task 11 — TicketCommentAdded event** (saltar a Fase 3)
11. **Task 12 — TicketResponded event**
12. Task 10 — TicketCommentAddedStrategy + TicketRespondedStrategy (volver a Fase 2)
13. Task 13 — TicketNotificationService refactor
14. Task 14 — Listener
15. Task 15 — Application.php wiring
16. Task 16 — TicketPipelineService emite eventos nuevos
17. Task 17 — Eliminar métodos viejos de EmailService
18. Task 18 — Eliminar property residual de TicketPipelineService
19. Task 19 — CLAUDE.md
20. Task 20 — Auditoría §11
