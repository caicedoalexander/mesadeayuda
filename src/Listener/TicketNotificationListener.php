<?php
declare(strict_types=1);

namespace App\Listener;

use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Service\TicketNotificationService;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Closure;
use Throwable;

/**
 * Bridges domain events to TicketNotificationService.
 *
 * Each handler reloads the ticket fresh from the database (the event payload
 * carries only IDs) and delegates to the appropriate notification dispatch
 * method. Exceptions are caught and logged — they never propagate back to
 * the dispatch site, mirroring the defensive behavior the service had when
 * called directly.
 *
 * The dispatcher is constructed lazily through the factory closure passed
 * to the constructor so that CLI processes which never dispatch a domain
 * event (e.g. `bin/cake migrations migrate`) don't pay the cost of building
 * TicketNotificationService at bootstrap.
 *
 * Scope: this listener only subscribes to events whose semantic is "notify
 * users via email/WhatsApp". Other domain events (e.g., TicketAssigned,
 * future TicketPriorityChanged) keep flowing through the global EventManager
 * for separate subscribers (audit, integrations) — they are NOT this
 * listener's concern. When/if assignment becomes a notification trigger,
 * add a real handler here rather than a log-only stub.
 */
final class TicketNotificationListener implements EventListenerInterface
{
    use LocatorAwareTrait;

    private ?TicketNotificationService $notifications = null;

    /**
     * @param \Closure(): \App\Service\TicketNotificationService $notificationsFactory Factory for the dispatcher
     */
    public function __construct(
        private readonly Closure $notificationsFactory,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return [
            TicketCreated::NAME => 'onCreated',
            TicketStatusChanged::NAME => 'onStatusChanged',
        ];
    }

    /**
     * @param \App\Domain\Event\TicketCreated $event Created event
     */
    public function onCreated(TicketCreated $event): void
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters']);
            $this->notifications()->dispatchCreationNotifications($ticket);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::onCreated failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param \App\Domain\Event\TicketStatusChanged $event Status changed event
     */
    public function onStatusChanged(TicketStatusChanged $event): void
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees']);
            $this->notifications()->dispatchUpdateNotifications($ticket, 'status_change', [
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ]);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::onStatusChanged failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return \App\Service\TicketNotificationService
     */
    private function notifications(): TicketNotificationService
    {
        return $this->notifications ??= ($this->notificationsFactory)();
    }
}
