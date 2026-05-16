<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a public comment is added to a ticket WITHOUT a
 * status change. The matching status-change-only event is
 * TicketStatusChanged; the combined response is TicketResponded.
 */
final class TicketCommentAdded extends DomainEvent
{
    public const NAME = 'Ticket.commentAdded';

    public function __construct(
        public readonly int $ticketId,
        public readonly int $commentId,
        public readonly int $actorId,
        public readonly bool $isPublic,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'commentId' => $commentId,
            'actorId' => $actorId,
            'isPublic' => $isPublic,
        ]);
    }
}
