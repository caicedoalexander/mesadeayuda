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
