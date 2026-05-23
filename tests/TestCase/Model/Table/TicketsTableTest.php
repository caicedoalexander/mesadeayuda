<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\Ticket;
use App\Model\Table\TicketsTable;
use Cake\TestSuite\TestCase;

/**
 * Unit tests for TicketsTable::attachOutboundMessageId (MED-1).
 *
 * These tests do NOT exercise the ORM; the bootstrap explicitly forbids
 * DB connections. Instead they use partial mocks that stub the {@see
 * \Cake\ORM\Table::get()} and {@see \Cake\ORM\Table::save()} entry points
 * the method depends on, leaving the public method body under test.
 */
class TicketsTableTest extends TestCase
{
    /**
     * Happy path: when rfc_message_id is null, the method persists the
     * outbound Message-ID.
     */
    public function testAttachOutboundMessageIdPersistsWhenRfcMessageIdIsNull(): void
    {
        $ticket = new Ticket();
        $ticket->patch([
            'id' => 1,
            'ticket_number' => 'TKT-0001',
            'rfc_message_id' => null,
        ], ['guard' => false]);
        $ticket->setNew(false);

        /** @var \PHPUnit\Framework\MockObject\MockObject&\App\Model\Table\TicketsTable $table */
        $table = $this->getMockBuilder(TicketsTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $table->method('get')->willReturn($ticket);
        $table->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn($e): bool => $e === $ticket))
            ->willReturn($ticket);

        $table->attachOutboundMessageId(1, 'outbound-1@mail.gmail.com');

        $this->assertSame('outbound-1@mail.gmail.com', $ticket->get('rfc_message_id'));
    }

    /**
     * Idempotence invariant: if rfc_message_id is already set, the method must
     * NOT overwrite it. For email-created tickets this column holds the
     * customer's original Message-ID; clobbering would break reattachment of
     * future customer replies that reference the original.
     */
    public function testAttachOutboundMessageIdNoOpsWhenRfcMessageIdIsAlreadySet(): void
    {
        $ticket = new Ticket();
        $ticket->patch([
            'id' => 1,
            'ticket_number' => 'TKT-0001',
            'rfc_message_id' => 'customer-original@mail.example.com',
        ], ['guard' => false]);
        $ticket->setNew(false);

        /** @var \PHPUnit\Framework\MockObject\MockObject&\App\Model\Table\TicketsTable $table */
        $table = $this->getMockBuilder(TicketsTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $table->method('get')->willReturn($ticket);
        $table->expects($this->never())->method('save');

        $table->attachOutboundMessageId(1, 'should-be-ignored@mail.gmail.com');

        $this->assertSame(
            'customer-original@mail.example.com',
            $ticket->get('rfc_message_id'),
            'The customer-original Message-ID must never be clobbered',
        );
    }

    /**
     * Save failures must be logged, not propagated — the email already went
     * out and RFC threading degrades gracefully to gmail_thread_id matching.
     */
    public function testAttachOutboundMessageIdLogsAndDoesNotThrowOnSaveFailure(): void
    {
        $ticket = new Ticket();
        $ticket->patch([
            'id' => 1,
            'ticket_number' => 'TKT-0001',
            'rfc_message_id' => null,
        ], ['guard' => false]);
        $ticket->setNew(false);

        /** @var \PHPUnit\Framework\MockObject\MockObject&\App\Model\Table\TicketsTable $table */
        $table = $this->getMockBuilder(TicketsTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $table->method('get')->willReturn($ticket);
        $table->method('save')->willReturn(false);

        // Must not throw.
        $table->attachOutboundMessageId(1, 'outbound-1@mail.gmail.com');

        $this->assertSame(
            'outbound-1@mail.gmail.com',
            $ticket->get('rfc_message_id'),
            'The value is still set on the entity even when save fails — only persistence is silenced',
        );
    }
}
