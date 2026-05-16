<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Notification\Strategy\TicketCreatedStrategy;
use Cake\TestSuite\TestCase;

class TicketCreatedStrategyTest extends TestCase
{
    public function testSupportsTicketCreatedOnly(): void
    {
        $strategy = new TicketCreatedStrategy();
        $created = new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual');
        $statusChanged = new TicketStatusChanged(1, 'abierto', 'resuelto', 2);

        $this->assertTrue($strategy->supports($created));
        $this->assertFalse($strategy->supports($statusChanged));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketCannotBeLoaded(): void
    {
        $strategy = new TicketCreatedStrategy();
        $event = new TicketCreated(ticketId: 999999, requesterId: 0, source: 'manual');

        $messages = iterator_to_array($strategy->buildMessages($event), false);

        $this->assertSame([], $messages);
    }
}
