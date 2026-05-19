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
