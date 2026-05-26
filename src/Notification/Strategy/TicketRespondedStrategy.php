<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Domain\Event\TicketResponded;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Generator;

/**
 * Builds the requester-facing email when a public comment AND a status
 * change happen together (the "response" flow in handleResponse).
 * Template: `ticket_updated` — combines comment body + status transition
 * in a single message to avoid duplicate emails.
 */
final class TicketRespondedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketResponded;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn(): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketResponded) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters', 'Assignees', 'Attachments']);
        $comment = $this->fetchTable('TicketComments')->get($event->commentId, contain: ['Users']);

        if (empty($ticket->requester->email)) {
            return;
        }

        $commentAttachments = [];
        if (!empty($ticket->attachments)) {
            foreach ($ticket->attachments as $attachment) {
                if ($attachment->comment_id === $comment->id && !$attachment->is_inline) {
                    $commentAttachments[] = $attachment;
                }
            }
        }

        $excludeEmails = array_filter([
            strtolower((string)($ticket->requester->email ?? '')),
            $this->gmailUserEmail(),
        ]);
        $emailTo = $this->filterRecipients($comment->email_to ?? $ticket->email_to ?? null, $excludeEmails);
        $emailCc = $this->filterRecipients($comment->email_cc ?? $ticket->email_cc ?? null, $excludeEmails);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
            comment: $comment,
            oldStatus: $event->oldStatus,
            newStatus: $event->newStatus,
            actor: $comment->user ?? null,
            commentAttachments: $commentAttachments,
        );
        $rendered = $this->templates()->get('ticket_updated')->render($ctx);

        $threading = $this->resolveThreading($ticket);

        yield NotificationMessage::email(
            recipient: (string)$ticket->requester->email,
            subject: $rendered->subject,
            bodyHtml: $rendered->bodyHtml,
            additionalTo: $emailTo,
            additionalCc: $emailCc,
            attachments: $commentAttachments,
            metadata: [
                'ticket_id' => $ticket->id,
                'comment_id' => $comment->id,
                'event' => $event->getName(),
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
            ],
            inReplyTo: $threading['inReplyTo'],
            referencesHeader: $threading['references'],
            commentId: $comment->id,
            gmailThreadId: $ticket->gmail_thread_id,
        );
    }
}
