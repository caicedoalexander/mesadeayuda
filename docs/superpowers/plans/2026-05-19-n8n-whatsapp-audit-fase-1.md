# n8n WhatsApp Audit · Fase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-1-design.md`

**Goal:** Reemplazar los dos cruces n8n → backend (email→Gmail Import y INSERT directo a `tickets_tags`) por dos endpoints HTTP estables y autenticados, y dejar documentada la decisión de Evolution API como integración canónica de WhatsApp.

**Architecture:** Dos nuevos métodos en `WebhooksController` (mismo patrón que `gmailImport`), un método `createFromWhatsapp` en `TicketIngestionService`, un VO `WhatsappIngestPayload` para validar el body, un factory `Ticket::fromWhatsappIngest` paralelo al de email, una migración para `tickets.whatsapp_message_id` (con unique index), y dos settings de token cifrados nuevos. La persistencia de tags reusa `TicketPipelineService::addTag()` sin modificación.

**Tech Stack:** PHP 8.5+, CakePHP 5.x, MySQL/MariaDB, PHPUnit 11, Phinx migrations. Tests son pure-unit (bootstrap NO carga BD ni fixtures).

**Testing constraint:** `tests/bootstrap.php` declara explícitamente "Tests must remain pure-unit — no DB queries, no fixtures." Por eso este plan cubre con unit tests el DTO y el entity factory; el controller + ingestion full-stack se verifica con un script smoke `curl` ejecutado manualmente contra el dev server. Wiring de fixtures para integration tests es follow-up separado.

**Decisión sobre rate-limit por phone (spec §3.7):** Se OMITE el rate-limit por `phone_number` en Fase 1. El lock por `message_id` (Task 6) ya bloquea reenvíos del mismo mensaje, y un usuario tipeando rápido genera `message_id` distintos. Si en producción se observa abuso, se añade en un follow-up dedicado.

**Decisión sobre `users.email` (riesgo §13 del spec):** Cuando no exista usuario con ese `phone`, se crea uno con `email = "<phone-sin-+>@whatsapp.local"` (placeholder). Esto preserva la regla `requirePresence('email', 'create')` sin tocar la tabla `users` ni su validador. El sufijo `whatsapp.local` es un TLD reservado (RFC 6762) — nunca enrutará correo real.

---

## File Structure

| Acción | Archivo | Responsabilidad |
|---|---|---|
| Create | `config/Migrations/<TIMESTAMP>_AddWhatsappMessageIdToTickets.php` | Columna + unique index |
| Create | `src/Service/Dto/WhatsappIngestPayload.php` | VO inmutable + validación del body |
| Modify | `src/Constants/SettingKeys.php` | 2 constantes nuevas |
| Modify | `src/Constants/CacheConstants.php` | 1 constante nueva |
| Modify | `src/Service/Traits/SettingsEncryptionTrait.php` | Añadir los 2 nuevos keys al allowlist de cifrado |
| Modify | `src/Model/Entity/Ticket.php` | Método `fromWhatsappIngest()` |
| Modify | `src/Service/TicketIngestionService.php` | Método `createFromWhatsapp()` + helper `findOrCreateUserByPhone()` |
| Modify | `src/Controller/WebhooksController.php` | `whatsappImport()` + `ticketTagsAdd()` |
| Modify | `config/routes.php` | 2 rutas POST nuevas bajo `/webhooks` |
| Create | `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php` | Unit tests DTO |
| Create | `tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php` | Unit tests factory |
| Create | `tests/smoke/whatsapp_import.sh` | Smoke test curl import |
| Create | `tests/smoke/tickets_tags.sh` | Smoke test curl tagging |
| Modify | `CLAUDE.md` | Nota sobre Evolution API canónica |
| Modify | `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md` | Marcar #2, #3, #9 como resueltos |

---

## Task 1: Migración `whatsapp_message_id` en `tickets`

**Files:**
- Create: `config/Migrations/<TIMESTAMP>_AddWhatsappMessageIdToTickets.php`

- [ ] **Step 1: Generar el esqueleto de la migración**

Run: `bin/cake bake migration AddWhatsappMessageIdToTickets`
Expected: archivo creado bajo `config/Migrations/` con timestamp actual.

- [ ] **Step 2: Reemplazar el contenido del archivo generado**

```php
<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

final class AddWhatsappMessageIdToTickets extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tickets')
            ->addColumn('whatsapp_message_id', 'string', [
                'limit' => 120,
                'null' => true,
                'default' => null,
                'after' => 'gmail_thread_id',
                'comment' => 'WhatsApp message ID (wamid) for idempotent ingest from n8n bot',
            ])
            ->addIndex(['whatsapp_message_id'], [
                'name' => 'idx_tickets_whatsapp_message_id',
                'unique' => true,
            ])
            ->update();
    }
}
```

- [ ] **Step 3: Validar que aplica y revierte limpiamente**

Run: `bin/cake migrations migrate`
Expected: salida sin errores, `migrations status` muestra `up` para la nueva fila.

Run: `bin/cake migrations rollback`
Expected: revierte a `down` sin errores.

Run: `bin/cake migrations migrate`
Expected: vuelve a aplicar (deja la BD en estado final).

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/*_AddWhatsappMessageIdToTickets.php
git commit -m "feat(db): add tickets.whatsapp_message_id with unique index"
```

---

## Task 2: Constantes nuevas (`SettingKeys`, `CacheConstants`)

**Files:**
- Modify: `src/Constants/SettingKeys.php`
- Modify: `src/Constants/CacheConstants.php`
- Modify: `src/Service/Traits/SettingsEncryptionTrait.php`

- [ ] **Step 1: Añadir constantes en `SettingKeys.php`**

Después de `WEBHOOK_GMAIL_IMPORT_TOKEN`:

```php
    public const WEBHOOK_GMAIL_IMPORT_TOKEN = 'webhook_gmail_import_token';
    public const WEBHOOK_WHATSAPP_IMPORT_TOKEN = 'webhook_whatsapp_import_token';
    public const WEBHOOK_TICKETS_TAGS_TOKEN = 'webhook_tickets_tags_token';
```

NO añadirlos a `USER_EDITABLE_KEYS` (son credenciales, deben rotarse con flujo dedicado).

- [ ] **Step 2: Añadir constante en `CacheConstants.php`**

Después de `WEBHOOK_GMAIL_PREVIOUS_TOKEN`:

```php
    public const WEBHOOK_GMAIL_PREVIOUS_TOKEN = 'webhook_gmail_previous_token';
    public const WEBHOOK_WHATSAPP_PREVIOUS_TOKEN = 'webhook_whatsapp_previous_token';
    public const WEBHOOK_TICKETS_TAGS_PREVIOUS_TOKEN = 'webhook_tickets_tags_previous_token';
```

- [ ] **Step 3: Añadir los keys al allowlist de cifrado en `SettingsEncryptionTrait.php`**

```php
    private const ENCRYPTED_SETTING_KEYS = [
        SettingKeys::GMAIL_REFRESH_TOKEN,
        SettingKeys::GMAIL_CLIENT_SECRET_JSON,
        SettingKeys::WHATSAPP_API_KEY,
        SettingKeys::N8N_API_KEY,
        SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN,
        SettingKeys::WEBHOOK_WHATSAPP_IMPORT_TOKEN,
        SettingKeys::WEBHOOK_TICKETS_TAGS_TOKEN,
    ];
```

- [ ] **Step 4: Verificar style + static analysis**

Run: `composer cs-check`
Expected: PASS

Run: `vendor/bin/phpstan analyse src/Constants src/Service/Traits/SettingsEncryptionTrait.php`
Expected: PASS (sin nuevos errores).

- [ ] **Step 5: Commit**

```bash
git add src/Constants/SettingKeys.php src/Constants/CacheConstants.php src/Service/Traits/SettingsEncryptionTrait.php
git commit -m "feat(settings): add encrypted token keys for whatsapp import + tags webhooks"
```

---

## Task 3: DTO `WhatsappIngestPayload` (TDD)

**Files:**
- Create: `tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`
- Create: `src/Service/Dto/WhatsappIngestPayload.php`

- [ ] **Step 1: Escribir test que falla con los casos felices y de error**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Dto;

use App\Service\Dto\WhatsappIngestPayload;
use App\Service\Dto\WhatsappIngestPayloadAttachment;
use App\Service\Exception\InvalidWhatsappPayloadException;
use PHPUnit\Framework\TestCase;

final class WhatsappIngestPayloadTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validRaw(): array
    {
        return [
            'message_id' => 'wamid.HBgM123',
            'phone_number' => '+573001234567',
            'contact_name' => 'Ana Pérez',
            'subject' => 'Impresora del piso 3',
            'description' => 'Desde ayer no imprime',
            'attachments' => [],
        ];
    }

    public function testHappyPath(): void
    {
        $p = WhatsappIngestPayload::fromArray($this->validRaw());

        self::assertSame('wamid.HBgM123', $p->messageId);
        self::assertSame('+573001234567', $p->phoneNumber);
        self::assertSame('Ana Pérez', $p->contactName);
        self::assertSame('Impresora del piso 3', $p->subject);
        self::assertSame('Desde ayer no imprime', $p->description);
        self::assertSame([], $p->attachments);
    }

    public function testNormalizesPhoneWithoutPlus(): void
    {
        $raw = $this->validRaw();
        $raw['phone_number'] = '573001234567';

        $p = WhatsappIngestPayload::fromArray($raw);

        self::assertSame('+573001234567', $p->phoneNumber);
    }

    public function testRejectsNonE164Phone(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);
        $this->expectExceptionMessageMatches('/phone_number/');

        $raw = $this->validRaw();
        $raw['phone_number'] = '0-not-a-number';

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testRejectsMissingMessageId(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);

        $raw = $this->validRaw();
        unset($raw['message_id']);

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testRejectsEmptySubjectAfterTrim(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);

        $raw = $this->validRaw();
        $raw['subject'] = '   ';

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testTrimsSubjectAndDescription(): void
    {
        $raw = $this->validRaw();
        $raw['subject'] = '  Hola  ';
        $raw['description'] = "\n texto \n";

        $p = WhatsappIngestPayload::fromArray($raw);

        self::assertSame('Hola', $p->subject);
        self::assertSame('texto', $p->description);
    }

    public function testRejectsSubjectOver200(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);

        $raw = $this->validRaw();
        $raw['subject'] = str_repeat('a', 201);

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testContactNameOptionalDefaultsToNull(): void
    {
        $raw = $this->validRaw();
        unset($raw['contact_name']);

        $p = WhatsappIngestPayload::fromArray($raw);

        self::assertNull($p->contactName);
    }

    public function testAttachmentParsed(): void
    {
        $raw = $this->validRaw();
        $raw['attachments'] = [[
            'url' => 'https://example.com/media/abc',
            'filename' => 'foto.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
        ]];

        $p = WhatsappIngestPayload::fromArray($raw);

        self::assertCount(1, $p->attachments);
        self::assertInstanceOf(WhatsappIngestPayloadAttachment::class, $p->attachments[0]);
        self::assertSame('foto.jpg', $p->attachments[0]->filename);
        self::assertSame(1234, $p->attachments[0]->size);
    }

    public function testRejectsAttachmentWithPathTraversalFilename(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);

        $raw = $this->validRaw();
        $raw['attachments'] = [[
            'url' => 'https://example.com/x',
            'filename' => '../../etc/passwd',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]];

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testRejectsAttachmentOver10Items(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);

        $raw = $this->validRaw();
        $att = ['url' => 'https://e.com/x', 'filename' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 1];
        $raw['attachments'] = array_fill(0, 11, $att);

        WhatsappIngestPayload::fromArray($raw);
    }

    public function testRejectsNonHttpsAttachmentUrl(): void
    {
        $this->expectException(InvalidWhatsappPayloadException::class);

        $raw = $this->validRaw();
        $raw['attachments'] = [[
            'url' => 'http://insecure.example/x',
            'filename' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 1,
        ]];

        WhatsappIngestPayload::fromArray($raw);
    }
}
```

- [ ] **Step 2: Verificar que el test falla por clases faltantes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`
Expected: FAIL — clases `WhatsappIngestPayload`, `WhatsappIngestPayloadAttachment`, `InvalidWhatsappPayloadException` no existen.

- [ ] **Step 3: Crear `InvalidWhatsappPayloadException`**

Create `src/Service/Exception/InvalidWhatsappPayloadException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when the body of POST /webhooks/whatsapp/import fails validation.
 * Caller (WebhooksController) maps this to HTTP 400.
 */
final class InvalidWhatsappPayloadException extends RuntimeException
{
}
```

- [ ] **Step 4: Crear el DTO `WhatsappIngestPayload` + sub-DTO de adjuntos**

Create `src/Service/Dto/WhatsappIngestPayload.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Service\Exception\InvalidWhatsappPayloadException;

/**
 * Immutable VO for POST /webhooks/whatsapp/import body.
 *
 * Builds itself via fromArray() with full validation; throws
 * InvalidWhatsappPayloadException on any rule violation. Mirrors
 * the contract documented in
 * docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-1-design.md §3.1.
 */
final class WhatsappIngestPayload
{
    private const MAX_SUBJECT = 200;
    private const MAX_DESCRIPTION = 65535;
    private const MAX_CONTACT_NAME = 120;
    private const MAX_MESSAGE_ID = 120;
    private const MAX_ATTACHMENTS = 10;

    /**
     * @param list<WhatsappIngestPayloadAttachment> $attachments
     */
    private function __construct(
        public readonly string $messageId,
        public readonly string $phoneNumber,
        public readonly ?string $contactName,
        public readonly string $subject,
        public readonly string $description,
        public readonly array $attachments,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $messageId = self::requireString($raw, 'message_id', self::MAX_MESSAGE_ID);
        if (preg_match('/\s/', $messageId) === 1) {
            throw new InvalidWhatsappPayloadException("field 'message_id': must not contain whitespace");
        }

        $phone = self::normalizePhone(self::requireString($raw, 'phone_number', 20));
        $subject = self::requireString($raw, 'subject', self::MAX_SUBJECT, trim: true);
        $description = self::requireString($raw, 'description', self::MAX_DESCRIPTION, trim: true);

        $contactName = null;
        if (array_key_exists('contact_name', $raw) && $raw['contact_name'] !== null && $raw['contact_name'] !== '') {
            $contactName = self::requireString($raw, 'contact_name', self::MAX_CONTACT_NAME);
        }

        $attachments = self::parseAttachments($raw['attachments'] ?? []);

        return new self($messageId, $phone, $contactName, $subject, $description, $attachments);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireString(array $raw, string $key, int $maxLength, bool $trim = false): string
    {
        if (!array_key_exists($key, $raw) || !is_string($raw[$key])) {
            throw new InvalidWhatsappPayloadException("field '{$key}': required string");
        }
        $value = $trim ? trim($raw[$key]) : $raw[$key];
        if ($value === '') {
            throw new InvalidWhatsappPayloadException("field '{$key}': must not be empty");
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidWhatsappPayloadException(
                "field '{$key}': exceeds {$maxLength} chars"
            );
        }

        return $value;
    }

    private static function normalizePhone(string $raw): string
    {
        $candidate = $raw;
        if ($candidate !== '' && $candidate[0] !== '+') {
            $candidate = '+' . $candidate;
        }
        if (preg_match('/^\+[1-9]\d{6,14}$/', $candidate) !== 1) {
            throw new InvalidWhatsappPayloadException("field 'phone_number': not E.164");
        }

        return $candidate;
    }

    /**
     * @param mixed $raw
     * @return list<WhatsappIngestPayloadAttachment>
     */
    private static function parseAttachments(mixed $raw): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidWhatsappPayloadException("field 'attachments': must be array");
        }
        if (count($raw) > self::MAX_ATTACHMENTS) {
            throw new InvalidWhatsappPayloadException(
                "field 'attachments': exceeds " . self::MAX_ATTACHMENTS . ' items'
            );
        }

        $list = [];
        foreach (array_values($raw) as $i => $item) {
            if (!is_array($item)) {
                throw new InvalidWhatsappPayloadException("field 'attachments[{$i}]': must be object");
            }
            $list[] = WhatsappIngestPayloadAttachment::fromArray($item, $i);
        }

        return $list;
    }
}
```

Create `src/Service/Dto/WhatsappIngestPayloadAttachment.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Service\Exception\InvalidWhatsappPayloadException;

final class WhatsappIngestPayloadAttachment
{
    private const MAX_SIZE_BYTES = 10485760; // mirrors GenericAttachmentTrait::MAX_FILE_SIZE
    private const MAX_FILENAME = 255;

    private function __construct(
        public readonly string $url,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $size,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, int $index): self
    {
        $field = static fn (string $name): string => "field 'attachments[{$index}].{$name}'";

        foreach (['url', 'filename', 'mime'] as $required) {
            if (!isset($raw[$required]) || !is_string($raw[$required]) || $raw[$required] === '') {
                throw new InvalidWhatsappPayloadException($field($required) . ': required string');
            }
        }

        $url = $raw['url'];
        if (!str_starts_with($url, 'https://')) {
            throw new InvalidWhatsappPayloadException($field('url') . ': must be https://');
        }

        $filename = $raw['filename'];
        if ($filename !== basename($filename) || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new InvalidWhatsappPayloadException($field('filename') . ': path traversal not allowed');
        }
        if (mb_strlen($filename) > self::MAX_FILENAME) {
            throw new InvalidWhatsappPayloadException($field('filename') . ': exceeds ' . self::MAX_FILENAME . ' chars');
        }

        if (!isset($raw['size']) || !is_int($raw['size']) || $raw['size'] < 1) {
            throw new InvalidWhatsappPayloadException($field('size') . ': required positive int');
        }
        if ($raw['size'] > self::MAX_SIZE_BYTES) {
            throw new InvalidWhatsappPayloadException($field('size') . ': exceeds ' . self::MAX_SIZE_BYTES . ' bytes');
        }

        return new self($url, $filename, $raw['mime'], $raw['size']);
    }
}
```

- [ ] **Step 5: Correr los tests hasta verde**

Run: `vendor/bin/phpunit tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php`
Expected: PASS (12 tests).

- [ ] **Step 6: Style + static analysis**

Run: `composer cs-fix && composer cs-check`
Expected: PASS

Run: `vendor/bin/phpstan analyse src/Service/Dto src/Service/Exception/InvalidWhatsappPayloadException.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Service/Dto/WhatsappIngestPayload.php src/Service/Dto/WhatsappIngestPayloadAttachment.php src/Service/Exception/InvalidWhatsappPayloadException.php tests/TestCase/Service/Dto/WhatsappIngestPayloadTest.php
git commit -m "feat(dto): add WhatsappIngestPayload VO with full validation"
```

---

## Task 4: Factory `Ticket::fromWhatsappIngest` (TDD)

**Files:**
- Create: `tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php`
- Modify: `src/Model/Entity/Ticket.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\TicketConstants;
use App\Model\Entity\Ticket;
use PHPUnit\Framework\TestCase;

final class TicketWhatsappFactoryTest extends TestCase
{
    public function testBuildsTicketWithChannelWhatsapp(): void
    {
        $ticket = Ticket::fromWhatsappIngest(
            ticketNumber: 'T-2025-000123',
            requesterId: 42,
            subject: 'Impresora',
            sanitizedDescription: '<p>desde ayer</p>',
            sourcePhone: '+573001234567',
            whatsappMessageId: 'wamid.abc',
        );

        self::assertSame('T-2025-000123', $ticket->ticket_number);
        self::assertSame(42, $ticket->requester_id);
        self::assertSame('Impresora', $ticket->subject);
        self::assertSame('<p>desde ayer</p>', $ticket->description);
        self::assertSame(TicketConstants::CHANNEL_WHATSAPP, $ticket->channel);
        self::assertSame(TicketConstants::STATUS_NUEVO, $ticket->status);
        self::assertSame(TicketConstants::PRIORITY_MEDIA, $ticket->priority);
        self::assertSame('+573001234567', $ticket->source_phone);
        self::assertSame('wamid.abc', $ticket->whatsapp_message_id);
    }

    public function testReplacesEmptySubjectWithFallback(): void
    {
        $ticket = Ticket::fromWhatsappIngest(
            ticketNumber: 'T-2025-000124',
            requesterId: 1,
            subject: '',
            sanitizedDescription: 'x',
            sourcePhone: '+573001234567',
            whatsappMessageId: 'wamid.def',
        );

        self::assertSame('(Sin asunto)', $ticket->subject);
    }
}
```

NB: el test asume que `Ticket` tiene un atributo `source_phone`. Si no existe en la entidad, añadir su `@property` y entrada en `_accessible` igual que `source_email`. Verificarlo en Step 2.

- [ ] **Step 2: Inspeccionar `Ticket` para conocer atributos accesibles existentes**

Read `src/Model/Entity/Ticket.php` para confirmar:
- Existencia de `source_email` en `_accessible` (sí, viene del factory de email).
- Si `source_phone` existe → reusarlo. Si no, añadirlo igual que `source_email`.

Si `source_phone` no existe, añadir en el bloque `_accessible`:
```php
        'source_phone' => false,
```
Y la `@property string|null $source_phone` en el docblock de la clase.

Si la columna `source_phone` tampoco existe en `tickets`, **renombrar el campo** del factory: usar `source_email = null` y persistir el teléfono en `email_to` no aplica — en su lugar usar `source_email = "<phone>@whatsapp.local"` (el placeholder ya usado para el `User`). Verificar en migrations existentes:

Run: `Grep -n "source_phone" config/Migrations/ src/Model/Entity/Ticket.php`

Si no existe `source_phone`, ajustar Step 1 (test) y Step 3 (factory) para usar `source_email` con el placeholder. Documentar la decisión en el commit message.

- [ ] **Step 3: Añadir el factory en `Ticket.php`**

Después de `fromEmailIngest()`:

```php
    /**
     * Factory para tickets ingresados desde el bot WhatsApp (POST /webhooks/whatsapp/import).
     *
     * Paralelo a fromEmailIngest: defaults de status/priority y fallback de
     * asunto vacío viven aquí, no en la capa IO.
     *
     * @param string $ticketNumber Number generated by NumberGenerationService
     * @param int $requesterId Resolved or just-created requester user id
     * @param string $subject Subject already trimmed by caller; (Sin asunto) si vacío
     * @param string $sanitizedDescription Body ya pasado por HtmlSanitizerTrait
     * @param string $sourcePhone E.164 phone number from the WhatsApp Cloud API payload
     * @param string $whatsappMessageId Idempotency key (wamid)
     */
    public static function fromWhatsappIngest(
        string $ticketNumber,
        int $requesterId,
        string $subject,
        string $sanitizedDescription,
        string $sourcePhone,
        string $whatsappMessageId,
    ): self {
        $ticket = new self();
        $ticket->ticket_number = $ticketNumber;
        $ticket->subject = $subject !== '' ? $subject : '(Sin asunto)';
        $ticket->description = $sanitizedDescription;
        $ticket->status = \App\Constants\TicketConstants::STATUS_NUEVO;
        $ticket->priority = \App\Constants\TicketConstants::PRIORITY_MEDIA;
        $ticket->requester_id = $requesterId;
        $ticket->channel = \App\Constants\TicketConstants::CHANNEL_WHATSAPP;
        $ticket->source_phone = $sourcePhone;
        $ticket->whatsapp_message_id = $whatsappMessageId;

        return $ticket;
    }
```

Añadir las `@property` en el docblock de la clase si no existen:

```php
 * @property string|null $source_phone
 * @property string|null $whatsapp_message_id
```

Si `source_phone` no existe en la tabla `tickets` (verificado en Step 2), extender la migración del Task 1 para añadirlo:

```php
->addColumn('source_phone', 'string', [
    'limit' => 32,
    'null' => true,
    'default' => null,
    'after' => 'source_email',
])
```

Y aplicar `bin/cake migrations rollback && bin/cake migrations migrate`.

- [ ] **Step 4: Correr el test hasta verde**

Run: `vendor/bin/phpunit tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Style + static analysis**

Run: `composer cs-fix && composer cs-check`
Run: `vendor/bin/phpstan analyse src/Model/Entity/Ticket.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Model/Entity/Ticket.php tests/TestCase/Model/Entity/TicketWhatsappFactoryTest.php config/Migrations/*_AddWhatsappMessageIdToTickets.php
git commit -m "feat(domain): Ticket::fromWhatsappIngest factory + source_phone column"
```

---

## Task 5: Método `TicketIngestionService::createFromWhatsapp`

**Files:**
- Modify: `src/Service/TicketIngestionService.php`

NB: no se añaden tests unitarios para este método (depende del Table layer, fuera del scope del bootstrap pure-unit). Se verificará vía smoke test en Task 8.

- [ ] **Step 1: Añadir el import del DTO al inicio del archivo**

```php
use App\Service\Dto\WhatsappIngestPayload;
```

- [ ] **Step 2: Añadir el método `createFromWhatsapp` después de `createFromEmail`**

```php
    /**
     * Create ticket from a validated WhatsApp ingest payload.
     *
     * Idempotente por whatsapp_message_id: si el message ya fue importado,
     * retorna el ticket existente sin recrear.
     *
     * @param \App\Service\Dto\WhatsappIngestPayload $payload Validated payload
     * @return array{ticket: \App\Model\Entity\Ticket|null, created: bool}
     */
    public function createFromWhatsapp(WhatsappIngestPayload $payload): array
    {
        $ticketsTable = $this->fetchTable('Tickets');

        // Idempotency: dedupe by whatsapp_message_id (unique index in BD).
        $existing = $ticketsTable->find()
            ->where(['whatsapp_message_id' => $payload->messageId])
            ->first();

        if ($existing) {
            Log::info('WhatsApp message already imported', [
                'message_id' => $payload->messageId,
                'ticket_id' => $existing->id,
            ]);

            return ['ticket' => $existing, 'created' => false];
        }

        $user = $this->findOrCreateUserByPhone($payload->phoneNumber, $payload->contactName);
        if (!$user) {
            Log::error('Failed to resolve user from WhatsApp phone', [
                'phone' => $payload->phoneNumber,
            ]);

            return ['ticket' => null, 'created' => false];
        }

        // Sanitize description (treat as untrusted free text from user).
        $description = $this->sanitizeHtml($payload->description);

        $ticketNumber = $ticketsTable->generateTicketNumber();

        $ticket = Ticket::fromWhatsappIngest(
            ticketNumber: $ticketNumber,
            requesterId: (int)$user->id,
            subject: $payload->subject,
            sanitizedDescription: $description,
            sourcePhone: $payload->phoneNumber,
            whatsappMessageId: $payload->messageId,
        );

        if (!$ticketsTable->save($ticket)) {
            Log::error('Failed to save WhatsApp ticket', [
                'errors' => $ticket->getErrors(),
                'message_id' => $payload->messageId,
            ]);

            return ['ticket' => null, 'created' => false];
        }

        // Best-effort attachments: failures logged as warning, ticket still created.
        foreach ($payload->attachments as $attachment) {
            $this->downloadAndStoreWhatsappAttachment($ticket, $attachment, (int)$user->id);
        }

        $this->eventManager->dispatch(new TicketCreated(
            ticketId: (int)$ticket->id,
            requesterId: (int)$ticket->requester_id,
            source: TicketConstants::CHANNEL_WHATSAPP,
        ));

        Log::info('Created ticket from WhatsApp', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'phone' => $payload->phoneNumber,
        ]);

        return ['ticket' => $ticket, 'created' => true];
    }
```

- [ ] **Step 3: Añadir helper `findOrCreateUserByPhone` (privado)**

Después de `findOrCreateUser`:

```php
    /**
     * Find a user by phone number; create a placeholder requester if absent.
     *
     * Placeholder email follows the convention "<digits>@whatsapp.local" so
     * the requirePresence('email') rule and unique-email constraint stay
     * satisfied without changing UsersTable validation.
     *
     * @param string $phone E.164 phone number
     * @param string|null $contactName Optional display name from WhatsApp
     */
    private function findOrCreateUserByPhone(string $phone, ?string $contactName): ?User
    {
        $usersTable = $this->fetchTable('Users');

        $user = $usersTable->find()
            ->where(['phone' => $phone])
            ->first();
        if ($user) {
            return $user;
        }

        $placeholderEmail = ltrim($phone, '+') . '@whatsapp.local';

        // Defensive: a previous WhatsApp ingest may have created the placeholder
        // email under a different phone normalization. Reuse if it exists.
        $byEmail = $usersTable->find()
            ->where(['email' => $placeholderEmail])
            ->first();
        if ($byEmail) {
            return $byEmail;
        }

        $name = $contactName !== null && $contactName !== '' ? $contactName : $phone;
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? $firstName;

        $user = $usersTable->newEntity([
            'email' => $placeholderEmail,
            'phone' => $phone,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => RoleConstants::ROLE_EXTERNAL,
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
        assert($user instanceof User);

        if ($usersTable->save($user)) {
            Log::info('Auto-created user from WhatsApp phone', [
                'phone' => $phone,
                'email' => $placeholderEmail,
            ]);

            return $user;
        }

        Log::error('Failed to create WhatsApp user', [
            'phone' => $phone,
            'errors' => $user->getErrors(),
        ]);

        return null;
    }
```

- [ ] **Step 4: Añadir helper `downloadAndStoreWhatsappAttachment` (privado)**

```php
    /**
     * Download a WhatsApp attachment via secure HTTP and persist via the
     * existing TicketAttachmentService (binary path). On failure logs warning
     * and continues — does NOT abort the ticket.
     */
    private function downloadAndStoreWhatsappAttachment(
        Ticket $ticket,
        \App\Service\Dto\WhatsappIngestPayloadAttachment $attachment,
        int $userId,
    ): void {
        try {
            // SecureHttpTrait expone hoy solo secureCurlPost(); para GET binario
            // usamos file_get_contents con stream_context restringido a https.
            // Si en el futuro SecureHttpTrait gana secureCurlGet(), reemplazar.
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'follow_location' => 0,
                    'header' => "User-Agent: MesaDeAyuda-WhatsAppIngest/1.0\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $binary = @file_get_contents($attachment->url, false, $context);
            if ($binary === false) {
                Log::warning('WhatsApp attachment download failed', [
                    'url' => $attachment->url,
                    'ticket_id' => $ticket->id,
                ]);

                return;
            }
            if (strlen($binary) !== $attachment->size) {
                Log::warning('WhatsApp attachment size mismatch', [
                    'declared' => $attachment->size,
                    'actual' => strlen($binary),
                    'ticket_id' => $ticket->id,
                ]);
            }

            $this->attachments->saveAttachmentFromBinary(
                entity: $ticket,
                filename: $attachment->filename,
                binaryContent: $binary,
                mimeType: $attachment->mime,
                commentId: null,
                userId: $userId,
            );
        } catch (Exception $e) {
            Log::warning('WhatsApp attachment processing failed', [
                'url' => $attachment->url,
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

NB: revisar si `SecureHttpTrait` ya tiene un helper `secureGetBinary()` — si sí, reemplazar el `file_get_contents` por ese helper. Verificarlo:

Run: `Grep -n "secureGet\|secureCurlGet" src/Service/Traits/SecureHttpTrait.php`

Si existe, usar el método del trait y mover el `use Traits\SecureHttpTrait;` al header de la clase si no está.

- [ ] **Step 5: Verificar el constructor regression test sigue verde**

Run: `vendor/bin/phpunit tests/TestCase/Service/TicketIngestionServiceTest.php`
Expected: PASS (2 tests). Si falla por TypeError del constructor, revisar que no introdujimos una dependencia obligatoria.

- [ ] **Step 6: Style + static analysis**

Run: `composer cs-fix && composer cs-check`
Run: `vendor/bin/phpstan analyse src/Service/TicketIngestionService.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Service/TicketIngestionService.php
git commit -m "feat(ingest): TicketIngestionService::createFromWhatsapp with phone-keyed user lookup"
```

---

## Task 6: `WebhooksController::whatsappImport()` + ruta

**Files:**
- Modify: `src/Controller/WebhooksController.php`
- Modify: `config/routes.php`

NB: tests integración del controller requieren fixtures wiring — fuera del scope pure-unit. Cobertura vía smoke test en Task 8.

- [ ] **Step 1: Añadir la ruta**

Editar `config/routes.php` dentro del scope `/webhooks`:

```php
$routes->scope('/webhooks', function (RouteBuilder $builder): void {
    $builder->setExtensions(['json']);
    $builder->post(
        '/gmail/import',
        ['controller' => 'Webhooks', 'action' => 'gmailImport'],
        'webhook_gmail_import'
    );
    $builder->post(
        '/whatsapp/import',
        ['controller' => 'Webhooks', 'action' => 'whatsappImport'],
        'webhook_whatsapp_import'
    );
});
```

- [ ] **Step 2: Añadir el método `whatsappImport()` al controller**

Después de `gmailImport()`:

```php
    /**
     * Crea un ticket a partir del payload del bot WhatsApp (n8n).
     *
     * Idempotente por `message_id`: dos POSTs con el mismo id retornan el
     * mismo ticket (segundo con created:false).
     */
    public function whatsappImport(): Response
    {
        $this->request->allowMethod(['POST']);

        if (!$this->verifyToken(
            SettingKeys::WEBHOOK_WHATSAPP_IMPORT_TOKEN,
            CacheConstants::WEBHOOK_WHATSAPP_PREVIOUS_TOKEN,
        )) {
            return $this->jsonError(401, 'invalid_token');
        }

        // Parse + validate body BEFORE locking — invalid requests don't consume locks.
        try {
            $payload = WhatsappIngestPayload::fromArray((array)$this->request->getData());
        } catch (InvalidWhatsappPayloadException $e) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => $e->getMessage()]);
        }

        // Cross-request idempotency lock by message_id (60s window).
        $lockKey = 'whatsapp_import:' . $payload->messageId;
        if (Cache::read($lockKey, self::RATE_LIMIT_CACHE) !== null) {
            return $this->jsonError(409, 'already_running');
        }
        Cache::write($lockKey, time(), self::RATE_LIMIT_CACHE);

        @set_time_limit(self::REQUEST_TIME_LIMIT);
        ignore_user_abort(true);

        try {
            $config = SystemConfig::fromSettings((new SettingsService())->loadAll());
            if (!$config->whatsapp->enabled) {
                return $this->jsonError(503, 'not_configured');
            }

            $service = new TicketIngestionService($config);
            $result = $service->createFromWhatsapp($payload);

            if ($result['ticket'] === null) {
                return $this->jsonError(500, 'ingest_failed');
            }

            return $this->jsonOk([
                'ticket_id' => (int)$result['ticket']->id,
                'ticket_number' => $result['ticket']->ticket_number,
                'created' => $result['created'],
            ]);
        } catch (Throwable $e) {
            Log::error('WhatsApp webhook import failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
                'message_id' => $payload->messageId,
            ]);

            return $this->jsonError(500, 'ingest_failed');
        } finally {
            Cache::delete($lockKey, self::RATE_LIMIT_CACHE);
        }
    }
```

- [ ] **Step 3: Refactorizar `verifyToken()` para aceptar setting/cache key**

El `verifyToken()` actual tiene los keys harcoded para Gmail. Generalizar la firma:

```php
    private function verifyToken(string $settingKey, string $previousTokenCacheKey): bool
    {
        $provided = (string)$this->request->getHeaderLine('X-Webhook-Token');
        if ($provided === '') {
            return false;
        }

        $settings = (new SettingsService())->loadAll();
        $expected = $settings[$settingKey] ?? null;

        if (is_string($expected) && $expected !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        return $this->matchesPreviousToken($provided, $previousTokenCacheKey);
    }

    private function matchesPreviousToken(string $provided, string $cacheKey): bool
    {
        $previous = Cache::read($cacheKey, 'default');
        if (!is_array($previous)) {
            return false;
        }
        $token = $previous['token'] ?? null;
        $expiresAt = (int)($previous['expires_at'] ?? 0);
        if (!is_string($token) || $token === '' || $expiresAt <= time()) {
            Cache::delete($cacheKey, 'default');

            return false;
        }

        return hash_equals($token, $provided);
    }
```

Y actualizar `gmailImport()` para pasar sus dos keys explícitamente:

```php
        if (!$this->verifyToken(
            SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN,
            CacheConstants::WEBHOOK_GMAIL_PREVIOUS_TOKEN,
        )) {
            return $this->jsonError(401, 'invalid_token');
        }
```

- [ ] **Step 4: Añadir los nuevos `use` statements al controller**

```php
use App\Service\Dto\SystemConfig;
use App\Service\Dto\WhatsappIngestPayload;
use App\Service\Exception\InvalidWhatsappPayloadException;
use App\Service\TicketIngestionService;
```

- [ ] **Step 5: Verificar que no rompimos `gmailImport`**

Run: `vendor/bin/phpunit`
Expected: PASS (la suite entera, sin regresiones).

- [ ] **Step 6: Style + static analysis**

Run: `composer cs-fix && composer cs-check`
Run: `vendor/bin/phpstan analyse src/Controller/WebhooksController.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/WebhooksController.php config/routes.php
git commit -m "feat(webhook): POST /webhooks/whatsapp/import for n8n bot ingest"
```

---

## Task 7: `WebhooksController::ticketTagsAdd()` + ruta

**Files:**
- Modify: `src/Controller/WebhooksController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Añadir la ruta**

```php
$builder->post(
    '/tickets/{id}/tags',
    ['controller' => 'Webhooks', 'action' => 'ticketTagsAdd'],
    'webhook_tickets_tags_add'
)
    ->setPatterns(['id' => '\d+'])
    ->setPass(['id']);
```

- [ ] **Step 2: Añadir el método al controller**

```php
    /**
     * Asigna tags a un ticket (idempotente, sin SQL crudo). Diseñado para
     * el sub-flujo de Auto Tagging del workflow n8n.
     */
    public function ticketTagsAdd(string $id): Response
    {
        $this->request->allowMethod(['POST']);

        if (!$this->verifyToken(
            SettingKeys::WEBHOOK_TICKETS_TAGS_TOKEN,
            CacheConstants::WEBHOOK_TICKETS_TAGS_PREVIOUS_TOKEN,
        )) {
            return $this->jsonError(401, 'invalid_token');
        }

        $ticketId = (int)$id;
        if ($ticketId <= 0) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => 'ticket id must be positive']);
        }

        $body = (array)$this->request->getData();
        $tagIdsRaw = $body['tag_ids'] ?? null;
        if (!is_array($tagIdsRaw) || $tagIdsRaw === []) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => "field 'tag_ids': non-empty array required"]);
        }
        if (count($tagIdsRaw) > 20) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => "field 'tag_ids': exceeds 20 items"]);
        }
        $tagIds = [];
        foreach ($tagIdsRaw as $candidate) {
            if (!is_int($candidate) || $candidate <= 0) {
                return $this->jsonError(400, 'invalid_payload', ['detail' => "field 'tag_ids': each item must be a positive int"]);
            }
            $tagIds[] = $candidate;
        }
        $tagIds = array_values(array_unique($tagIds));

        $source = $body['source'] ?? 'auto';
        if (!in_array($source, ['auto', 'manual'], true)) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => "field 'source': must be 'auto' or 'manual'"]);
        }

        $ticketsTable = $this->fetchTable('Tickets');
        $ticket = $ticketsTable->find()->where(['id' => $ticketId])->first();
        if ($ticket === null) {
            return $this->jsonError(404, 'ticket_not_found');
        }

        $tagsTable = $this->fetchTable('Tags');
        $knownIds = $tagsTable->find()
            ->where(['id IN' => $tagIds])
            ->all()
            ->extract('id')
            ->toList();
        $knownIds = array_map('intval', $knownIds);

        $unknown = array_values(array_diff($tagIds, $knownIds));

        $pipeline = new TicketPipelineService();
        $added = [];
        $skippedExisting = [];

        foreach ($knownIds as $tagId) {
            $result = $pipeline->addTag($ticketId, $tagId);
            if ($result['success']) {
                $added[] = $tagId;
            } elseif (str_contains($result['message'], 'ya está agregada')) {
                $skippedExisting[] = $tagId;
            } else {
                Log::error('Tags webhook addTag failed', [
                    'ticket_id' => $ticketId,
                    'tag_id' => $tagId,
                    'message' => $result['message'],
                ]);
            }
        }

        if ($unknown !== []) {
            Log::warning('Tags webhook unknown tag_ids (LLM hallucination?)', [
                'ticket_id' => $ticketId,
                'unknown' => $unknown,
                'source' => $source,
            ]);
        } else {
            Log::info('Tags webhook applied', [
                'ticket_id' => $ticketId,
                'added' => $added,
                'skipped_existing' => $skippedExisting,
                'source' => $source,
            ]);
        }

        return $this->jsonOk([
            'added' => $added,
            'skipped_existing' => $skippedExisting,
            'skipped_unknown' => $unknown,
        ]);
    }
```

- [ ] **Step 3: Añadir `use TicketPipelineService` al controller**

```php
use App\Service\TicketPipelineService;
```

`fetchTable` requiere `LocatorAwareTrait`. Si el controller (que extiende `Controller`, no `AppController`) no la tiene aún, añadir:

```php
use Cake\ORM\Locator\LocatorAwareTrait;

final class WebhooksController extends Controller
{
    use LocatorAwareTrait;
    // ...
}
```

Verificarlo antes de añadir para no duplicar el `use`.

- [ ] **Step 4: Correr la suite**

Run: `vendor/bin/phpunit`
Expected: PASS (toda la suite, sin regresiones del refactor de `verifyToken`).

- [ ] **Step 5: Style + static analysis**

Run: `composer cs-fix && composer cs-check`
Run: `vendor/bin/phpstan analyse src/Controller/WebhooksController.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/WebhooksController.php config/routes.php
git commit -m "feat(webhook): POST /webhooks/tickets/{id}/tags via TicketPipelineService"
```

---

## Task 8: Smoke tests + actualización de docs

**Files:**
- Create: `tests/smoke/whatsapp_import.sh`
- Create: `tests/smoke/tickets_tags.sh`
- Create: `tests/smoke/README.md`
- Modify: `CLAUDE.md`
- Modify: `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md`

- [ ] **Step 1: Crear `tests/smoke/README.md`**

```markdown
# Smoke tests para webhooks

Estos scripts NO corren en `composer test` (la suite es pure-unit). Se ejecutan
manualmente contra el dev server tras `bin/cake server` y antes de mergear cambios
en el endpoint correspondiente.

## Prerrequisitos

1. Dev server corriendo: `bin/cake server` (puerto 8765)
2. Los tokens correspondientes seteados en `system_settings`:
   - `webhook_whatsapp_import_token`
   - `webhook_tickets_tags_token`
3. WhatsApp habilitado: `whatsapp_enabled = '1'`

## Uso

```bash
WHATSAPP_TOKEN=<your-token> ./tests/smoke/whatsapp_import.sh
TAGS_TOKEN=<your-token> TICKET_ID=42 ./tests/smoke/tickets_tags.sh
```

Verificar manualmente:
- HTTP 200 con `created:true` la primera vez.
- HTTP 200 con `created:false` al repetir (idempotencia).
- HTTP 401 sin header.
- HTTP 400 con payload inválido.
```

- [ ] **Step 2: Crear `tests/smoke/whatsapp_import.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

: "${WHATSAPP_TOKEN:?WHATSAPP_TOKEN is required}"
HOST="${HOST:-http://localhost:8765}"
MSG_ID="${MSG_ID:-wamid.smoke.$(date +%s)}"

echo "→ POST /webhooks/whatsapp/import (first call, expect 200 created:true)"
curl -sS -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"message_id\": \"$MSG_ID\",
        \"phone_number\": \"+573001234567\",
        \"contact_name\": \"Smoke Test\",
        \"subject\": \"Smoke test ticket\",
        \"description\": \"Generado por whatsapp_import.sh\"
    }" | tee /tmp/whatsapp_smoke_1.json
echo

echo "→ POST repeat (expect 200 created:false)"
curl -sS -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"message_id\": \"$MSG_ID\",
        \"phone_number\": \"+573001234567\",
        \"subject\": \"Smoke test ticket\",
        \"description\": \"Generado por whatsapp_import.sh\"
    }" | tee /tmp/whatsapp_smoke_2.json
echo

echo "→ POST without token (expect 401)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/whatsapp/import" \
    -H "Content-Type: application/json" -d '{}'

echo "→ POST with invalid payload (expect 400)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/whatsapp/import" \
    -H "X-Webhook-Token: $WHATSAPP_TOKEN" \
    -H "Content-Type: application/json" -d '{"message_id":"x"}'
```

- [ ] **Step 3: Crear `tests/smoke/tickets_tags.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

: "${TAGS_TOKEN:?TAGS_TOKEN is required}"
: "${TICKET_ID:?TICKET_ID is required}"
HOST="${HOST:-http://localhost:8765}"

echo "→ POST /webhooks/tickets/$TICKET_ID/tags (expect 200)"
curl -sS -X POST "$HOST/webhooks/tickets/$TICKET_ID/tags" \
    -H "X-Webhook-Token: $TAGS_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"tag_ids":[1,2,99999],"source":"auto"}' | tee /tmp/tags_smoke_1.json
echo

echo "→ POST repeat same tags (expect skipped_existing)"
curl -sS -X POST "$HOST/webhooks/tickets/$TICKET_ID/tags" \
    -H "X-Webhook-Token: $TAGS_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"tag_ids":[1,2],"source":"auto"}' | tee /tmp/tags_smoke_2.json
echo

echo "→ POST against nonexistent ticket (expect 404)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/tickets/99999999/tags" \
    -H "X-Webhook-Token: $TAGS_TOKEN" \
    -H "Content-Type: application/json" -d '{"tag_ids":[1]}'

echo "→ POST without token (expect 401)"
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$HOST/webhooks/tickets/$TICKET_ID/tags" \
    -H "Content-Type: application/json" -d '{"tag_ids":[1]}'
```

- [ ] **Step 4: Hacer los scripts ejecutables**

Run: `chmod +x tests/smoke/whatsapp_import.sh tests/smoke/tickets_tags.sh`
Expected: sin output, permisos cambiados.

- [ ] **Step 5: Editar `CLAUDE.md`** — añadir nota bajo "Notifications and integrations"

Después del párrafo que termina con "...let the listener dispatch.", añadir:

```markdown
**WhatsApp = Evolution API (canónica).** Cualquier referencia a Meta Cloud
API (`graph.facebook.com`) en n8n es deuda heredada y está agendada para
migrar en la Fase 2 del audit 2026-05-18 (ver
`docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-1-design.md` §5).
```

- [ ] **Step 6: Actualizar el audit con la resolución**

Editar `docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md`:

En la cabecera, añadir tras `**Versión auditada**`:

```markdown
- **Estado de resolución**: #2, #3, #9 — **resueltos en Fase 1** (ver `docs/superpowers/plans/2026-05-19-n8n-whatsapp-audit-fase-1.md`). #1, #4-#8, #10 pendientes (Fase 2/3).
```

En la tabla §6, añadir una columna "Estado" o anteponer `✅` a las filas 2, 3 y 9.

- [ ] **Step 7: Verificar la suite completa una última vez**

Run: `composer cs-fix && composer cs-check && composer test`
Expected: PASS, PASS, PASS.

Run: `vendor/bin/phpstan analyse src`
Expected: sin nuevos errores respecto a la baseline previa.

- [ ] **Step 8: Smoke test manual contra dev server**

Pre-requisito: tokens generados y aplicados a `system_settings` mediante UI admin o consola.

Run: `bin/cake server` (en otra terminal).
Run: `WHATSAPP_TOKEN=<token> ./tests/smoke/whatsapp_import.sh`
Verificar las 4 aserciones del script.

Run: `TAGS_TOKEN=<token> TICKET_ID=<un-id-real> ./tests/smoke/tickets_tags.sh`
Verificar las 4 aserciones del script.

Documentar resultado en el commit final.

- [ ] **Step 9: Commit**

```bash
git add tests/smoke/ CLAUDE.md docs/audits/2026-05-18-n8n-whatsapp-workflow-audit.md
git commit -m "docs+smoke: close audit #2, #3, #9; smoke tests for webhooks"
```

---

## Criterios de éxito (verificar al final)

- [ ] `composer cs-check` → PASS
- [ ] `composer test` → PASS (suite entera, sin regresiones)
- [ ] `vendor/bin/phpstan analyse src` → sin nuevos errores
- [ ] `bin/cake migrations migrate` + `rollback` + `migrate` → limpio
- [ ] Smoke `whatsapp_import.sh` → 200 created:true, 200 created:false, 401, 400
- [ ] Smoke `tickets_tags.sh` → 200 con `skipped_unknown`, 200 con `skipped_existing`, 404, 401
- [ ] `CLAUDE.md` declara Evolution API como integración canónica
- [ ] Audit marca #2, #3, #9 como resueltos con link al plan
