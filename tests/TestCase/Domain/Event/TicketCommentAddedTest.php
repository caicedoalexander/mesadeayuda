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
