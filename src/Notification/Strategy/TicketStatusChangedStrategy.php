<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Domain\Event\TicketStatusChanged;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Generator;

/**
 * Builds the requester-facing email when a ticket transitions between
 * statuses without a public comment. The matching template is
 * `ticket_status_changed`.
 */
final class TicketStatusChangedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketStatusChanged;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn(): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketStatusChanged) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees', 'Attachments']);

        if (empty($ticket->requester->email)) {
            return;
        }

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
            oldStatus: $event->oldStatus,
            newStatus: $event->newStatus,
            actor: $ticket->assignee ?? null,
        );
        $rendered = $this->templates()->get('ticket_status_changed')->render($ctx);

        yield NotificationMessage::email(
            recipient: (string)$ticket->requester->email,
            subject: $rendered->subject,
            bodyHtml: $rendered->bodyHtml,
            metadata: [
                'ticket_id' => $ticket->id,
                'event' => $event->getName(),
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ],
        );
    }
}
