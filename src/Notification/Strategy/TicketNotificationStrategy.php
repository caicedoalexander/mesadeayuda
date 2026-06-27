<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use Cake\Event\EventInterface;

/**
 * Builds NotificationMessage instances from a domain event. Each
 * implementation owns one event type (or family) and decides which
 * channels receive a message.
 *
 * Implementations MUST NOT throw — errors must be logged and an empty
 * iterable returned so the dispatcher can continue with the next
 * strategy.
 */
interface TicketNotificationStrategy
{
    /**
     * @param \Cake\Event\EventInterface $event Domain event to evaluate.
     * @return bool
     */
    public function supports(EventInterface $event): bool;

    /**
     * @return iterable<\App\Notification\Channel\NotificationMessage>
     */
    public function buildMessages(EventInterface $event): iterable;
}
