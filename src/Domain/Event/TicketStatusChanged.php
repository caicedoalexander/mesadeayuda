<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a ticket's status transition succeeds.
 */
final class TicketStatusChanged extends DomainEvent
{
    public const NAME = 'Ticket.statusChanged';

    /**
     * @param int $ticketId Ticket ID
     * @param string $oldStatus Previous status
     * @param string $newStatus New status
     * @param int|null $actorId Actor user ID performing the transition
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'actorId' => $actorId,
        ]);
    }
}
