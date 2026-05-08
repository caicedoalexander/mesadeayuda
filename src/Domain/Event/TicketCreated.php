<?php
declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Dispatched after a ticket is persisted from any source (email, manual).
 */
final class TicketCreated extends DomainEvent
{
    public const NAME = 'Ticket.created';

    /**
     * @param int $ticketId Ticket ID
     * @param int $requesterId Requester user ID
     * @param string $source Origin source (e.g. 'email', 'manual')
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly int $requesterId,
        public readonly string $source,
    ) {
        parent::__construct(self::NAME, null, [
            'ticketId' => $ticketId,
            'requesterId' => $requesterId,
            'source' => $source,
        ]);
    }
}
