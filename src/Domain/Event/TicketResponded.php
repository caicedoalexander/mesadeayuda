<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a public comment AND a status change are committed
 * together (the "response" flow in TicketPipelineService::handleResponse).
 *
 * When this event is emitted, the caller MUST NOT also dispatch
 * TicketStatusChanged for the same transition — the matching strategy
 * sends one combined email covering both effects.
 */
final class TicketResponded extends DomainEvent
{
    public const NAME = 'Ticket.responded';

    public function __construct(
        public readonly int $ticketId,
        public readonly int $commentId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'commentId' => $commentId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'actorId' => $actorId,
        ]);
    }
}
