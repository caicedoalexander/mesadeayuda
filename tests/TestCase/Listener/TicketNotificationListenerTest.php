<?php
declare(strict_types=1);

namespace App\Test\TestCase\Listener;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketResponded;
use App\Domain\Event\TicketStatusChanged;
use App\Listener\TicketNotificationListener;
use App\Service\TicketNotificationService;
use Cake\Event\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TicketNotificationListenerTest extends TestCase
{
    public function testImplementedEventsMapsEveryTicketEventToForward(): void
    {
        $listener = new TicketNotificationListener(fn(): TicketNotificationService => $this->createMock(TicketNotificationService::class));

        $events = $listener->implementedEvents();

        self::assertSame(
            [
                TicketCreated::NAME => 'forward',
                TicketStatusChanged::NAME => 'forward',
                TicketCommentAdded::NAME => 'forward',
                TicketResponded::NAME => 'forward',
            ],
            $events,
        );
    }

    public function testForwardDelegatesEventToTheNotificationService(): void
    {
        $event = new Event('Ticket.created');

        $service = $this->createMock(TicketNotificationService::class);
        $service->expects($this->once())->method('dispatch')->with($event);

        $listener = new TicketNotificationListener(fn(): TicketNotificationService => $service);

        $listener->forward($event);
    }

    public function testForwardSwallowsThrowableAndDoesNotPropagate(): void
    {
        // Contract: the listener MUST NOT let exceptions bubble back to the
        // dispatcher, or a single failing strategy would abort the whole flow.
        $service = $this->createStub(TicketNotificationService::class);
        $service->method('dispatch')->willThrowException(new RuntimeException('strategy blew up'));

        $listener = new TicketNotificationListener(fn(): TicketNotificationService => $service);

        $this->expectNotToPerformAssertions();
        $listener->forward(new Event('Ticket.created'));
    }

    public function testNotificationServiceIsBuiltLazilyAndOnlyOnce(): void
    {
        $callCount = 0;
        $service = $this->createStub(TicketNotificationService::class);
        $factory = function () use (&$callCount, $service): TicketNotificationService {
            $callCount++;

            return $service;
        };

        $listener = new TicketNotificationListener($factory);
        // Not built at construction time (CLI processes that never dispatch pay nothing).
        self::assertSame(0, $callCount);

        $listener->forward(new Event('Ticket.created'));
        $listener->forward(new Event('Ticket.status_changed'));

        // Built on first use and memoised across subsequent forwards.
        self::assertSame(1, $callCount);
    }
}
