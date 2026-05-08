<?php
declare(strict_types=1);

namespace App\Domain\Event;

use Cake\Event\Event;
use DateTimeImmutable;

/**
 * Abstract base for domain events.
 *
 * Extends Cake\Event\Event so events can be dispatched through
 * EventManager::instance() and handled by EventListenerInterface
 * implementations registered in Application::bootstrap.
 *
 * @template TSubject of object|null
 * @extends \Cake\Event\Event<TSubject>
 */
abstract class DomainEvent extends Event
{
    public readonly DateTimeImmutable $occurredAt;

    /**
     * @param string $name Event name (e.g. 'Ticket.created')
     * @param object|null $subject Optional subject (entity)
     * @param array $data Optional payload
     */
    public function __construct(string $name, ?object $subject = null, array $data = [])
    {
        parent::__construct($name, $subject, $data);
        $this->occurredAt = new DateTimeImmutable();
    }
}
