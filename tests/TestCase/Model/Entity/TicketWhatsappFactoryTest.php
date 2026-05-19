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
