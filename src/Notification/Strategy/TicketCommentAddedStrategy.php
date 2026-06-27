<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Domain\Event\TicketCommentAdded;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Generator;

/**
 * Builds the requester-facing email when a public comment is added to a
 * ticket WITHOUT a status change. Template: `ticket_comment_added`.
 *
 * The strategy reloads ticket + comment so it can render the actor name
 * and attachment list independent of the dispatcher state.
 */
final class TicketCommentAddedStrategy extends AbstractTicketStrategy
{
    /**
     * @inheritDoc
     */
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketCommentAdded;
    }

    /**
     * @inheritDoc
     */
    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn(): Generator => $this->doBuild($event), $event);
    }

    /**
     * @param \Cake\Event\EventInterface $event Domain event to render messages for.
     * @return \Generator
     */
    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketCommentAdded) {
            return;
        }

        if (!$event->isPublic) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get(
            $event->ticketId,
            contain: ['Requesters', 'Assignees', 'Attachments'],
        );
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
            actor: $comment->user ?? null,
            commentAttachments: $commentAttachments,
        );
        $rendered = $this->templates()->get('ticket_comment_added')->render($ctx);

        $threading = $this->resolveThreading($ticket);

        yield NotificationMessage::email(
            recipient: (string)$ticket->requester->email,
            subject: $rendered->subject,
            bodyHtml: $rendered->bodyHtml,
            additionalTo: $emailTo,
            additionalCc: $emailCc,
            attachments: $commentAttachments,
            metadata: ['ticket_id' => $ticket->id, 'comment_id' => $comment->id, 'event' => $event->getName()],
            inReplyTo: $threading['inReplyTo'],
            referencesHeader: $threading['references'],
            commentId: $comment->id,
            gmailThreadId: $ticket->gmail_thread_id,
        );
    }
}
