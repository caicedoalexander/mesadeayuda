<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a ticket's assignee_id is changed (including clearing).
 */
final class TicketAssigned extends DomainEvent
{
    public const NAME = 'Ticket.assigned';

    /**
     * @param int $ticketId Ticket ID
     * @param int|null $assigneeId New assignee user ID (null when cleared)
     * @param int|null $previousAssigneeId Previous assignee user ID
     * @param int|null $actorId Actor user ID performing the assignment
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly ?int $assigneeId,
        public readonly ?int $previousAssigneeId,
        public readonly ?int $actorId,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'assigneeId' => $assigneeId,
            'previousAssigneeId' => $previousAssigneeId,
            'actorId' => $actorId,
        ]);
    }
}
