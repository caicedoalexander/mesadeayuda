<?php
declare(strict_types=1);

namespace App\Listener;

use App\Domain\Event\TicketAssigned;
use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Service\TicketNotificationService;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Bridges domain events to TicketNotificationService.
 *
 * Each handler reloads the ticket fresh from the database (the event payload
 * carries only IDs) and delegates to the appropriate notification dispatch
 * method. Exceptions are caught and logged — they never propagate back to
 * the dispatch site, mirroring the defensive behavior the service had when
 * called directly.
 */
final class TicketNotificationListener implements EventListenerInterface
{
    use LocatorAwareTrait;

    /**
     * @param \App\Service\TicketNotificationService $notifications Notification dispatcher
     */
    public function __construct(
        private readonly TicketNotificationService $notifications,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return [
            TicketCreated::NAME => 'onCreated',
            TicketAssigned::NAME => 'onAssigned',
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
            $this->notifications->dispatchCreationNotifications($ticket);
        } catch (Throwable $e) {
            Log::error('TicketNotificationListener::onCreated failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param \App\Domain\Event\TicketAssigned $event Assigned event
     *
     * Note: TicketNotificationService::dispatchUpdateNotifications does not yet
     * implement an 'assignment' branch. The event is still emitted so other
     * listeners (audit, future integrations) can react; notification side is a
     * follow-up scope.
     */
    public function onAssigned(TicketAssigned $event): void
    {
        Log::debug('TicketAssigned event received (no notification side)', [
            'ticket_id' => $event->ticketId,
            'assignee_id' => $event->assigneeId,
        ]);
    }

    /**
     * @param \App\Domain\Event\TicketStatusChanged $event Status changed event
     */
    public function onStatusChanged(TicketStatusChanged $event): void
    {
        try {
            $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees']);
            $this->notifications->dispatchUpdateNotifications($ticket, 'status_change', [
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
}
