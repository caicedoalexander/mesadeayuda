<?php
declare(strict_types=1);

namespace App\Listener;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketResponded;
use App\Domain\Event\TicketStatusChanged;
use App\Service\TicketNotificationService;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Closure;
use Throwable;

/**
 * Generic bridge between the global EventManager and the strategy/channel
 * pipeline. The listener does not know about specific events anymore —
 * it forwards every supported event to TicketNotificationService::dispatch().
 *
 * Adding a new ticket event = one new line in implementedEvents() plus a
 * matching strategy. The listener stays untouched.
 *
 * The dispatcher is built lazily via a factory closure so CLI processes
 * that never dispatch a ticket event (`bin/cake migrations migrate`)
 * don't pay the cost of building the service at bootstrap.
 *
 * Any Throwable raised during forwarding is caught and logged. The
 * listener MUST NOT propagate exceptions back to the dispatcher.
 */
final class TicketNotificationListener implements EventListenerInterface
{
    private ?TicketNotificationService $notifications = null;

    /**
     * @param \Closure(): \App\Service\TicketNotificationService $notificationsFactory
     */
    public function __construct(private readonly Closure $notificationsFactory)
    {
    }

    /**
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return [
            TicketCreated::NAME => 'forward',
            TicketStatusChanged::NAME => 'forward',
            TicketCommentAdded::NAME => 'forward',
            TicketResponded::NAME => 'forward',
        ];
    }

    public function forward(EventInterface $event): void
    {
        try {
            $this->notifications()->dispatch($event);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::forward failed', [
                'event' => $event->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifications(): TicketNotificationService
    {
        return $this->notifications ??= ($this->notificationsFactory)();
    }
}
