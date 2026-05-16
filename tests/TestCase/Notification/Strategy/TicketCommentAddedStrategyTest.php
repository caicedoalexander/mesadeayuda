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
