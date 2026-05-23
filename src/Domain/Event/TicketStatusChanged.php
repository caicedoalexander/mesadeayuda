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
     * @param int|null $systemCommentId Internal system_comment created to record the
     *   transition. Carried so TicketStatusChangedStrategy can anchor the outbound
     *   notification's Message-ID against it (MEN-1) — closes the RFC threading
     *   loop for the rare case where a customer replies directly to a status-change
     *   email.
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
        public readonly ?int $systemCommentId = null,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'actorId' => $actorId,
            'systemCommentId' => $systemCommentId,
        ]);
    }
}
