<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Model\Entity\Ticket;
use App\Notification\Email\TemplateContext;
use PHPUnit\Framework\TestCase;

final class TemplateContextTest extends TestCase
{
    private function ticket(): Ticket
    {
        $t = new Ticket();
        $t->set(['id' => 1, 'ticket_number' => 'TKT-1'], ['guard' => false]);

        return $t;
    }

    public function testRequiredFieldsExposed(): void
    {
        $ctx = new TemplateContext(
            ticket: $this->ticket(),
            ticketUrl: 'https://example.com/t/1',
            recipientName: 'Alex',
        );

        self::assertSame('TKT-1', $ctx->ticket->ticket_number);
        self::assertSame('https://example.com/t/1', $ctx->ticketUrl);
        self::assertSame('Alex', $ctx->recipientName);
        self::assertNull($ctx->comment);
        self::assertNull($ctx->oldStatus);
        self::assertNull($ctx->newStatus);
        self::assertNull($ctx->actor);
        self::assertSame([], $ctx->commentAttachments);
    }

    public function testOptionalFieldsAccepted(): void
    {
        $ctx = new TemplateContext(
            ticket: $this->ticket(),
            ticketUrl: 'u',
            recipientName: 'r',
            oldStatus: 'open',
            newStatus: 'resolved',
        );

        self::assertSame('open', $ctx->oldStatus);
        self::assertSame('resolved', $ctx->newStatus);
    }
}
