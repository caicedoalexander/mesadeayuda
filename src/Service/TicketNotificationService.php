<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Event\EventInterface;
use Cake\Log\Log;
use Throwable;

/**
 * Orchestrates ticket notifications: takes a domain event, asks each
 * strategy whether it supports it, lets the supporting strategies build
 * NotificationMessage instances, then routes each message to the channel
 * whose name() matches the message's channel field.
 *
 * The service has no event-type knowledge — adding a new event is two
 * file changes: a new strategy and one new line in the listener.
 */
class TicketNotificationService
{
    /**
     * @var list<\App\Notification\Strategy\TicketNotificationStrategy>
     */
    private array $strategies;

    /**
     * @var array<string, \App\Notification\Channel\NotificationChannel>
     */
    private array $channels;

    /**
     * @param list<\App\Notification\Strategy\TicketNotificationStrategy> $strategies
     * @param list<\App\Notification\Channel\NotificationChannel> $channels
     */
    public function __construct(array $strategies = [], array $channels = [])
    {
        $this->strategies = $strategies;
        $this->channels = [];
        foreach ($channels as $channel) {
            $this->channels[$channel->name()] = $channel;
        }
    }

    /**
     * Route a domain event to every supporting strategy and deliver the
     * resulting messages through their matching channels.
     *
     * @param \Cake\Event\EventInterface $event Domain event to dispatch.
     * @return void
     */
    public function dispatch(EventInterface $event): void
    {
        foreach ($this->strategies as $strategy) {
            if (!$strategy->supports($event)) {
                continue;
            }
            try {
                foreach ($strategy->buildMessages($event) as $message) {
                    $channel = $this->channels[$message->channel] ?? null;
                    if ($channel === null) {
                        Log::info('No channel registered for message; dropping', [
                            'channel' => $message->channel,
                            'event' => $event->getName(),
                        ]);
                        continue;
                    }
                    $channel->send($message);
                }
            } catch (Throwable $e) {
                Log::error('TicketNotificationService strategy failed', [
                    'strategy' => $strategy::class,
                    'event' => $event->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
