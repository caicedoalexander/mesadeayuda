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
