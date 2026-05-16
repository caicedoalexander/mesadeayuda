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
