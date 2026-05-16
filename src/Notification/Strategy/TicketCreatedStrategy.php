<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Constants\SettingKeys;
use App\Domain\Event\TicketCreated;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Generator;

/**
 * Builds notifications for TicketCreated:
 *  - Email to the requester (template: ticket_created)
 *  - WhatsApp to the tickets team number (text rendered via NotificationRenderer)
 *
 * WhatsApp recipient (config WHATSAPP_TICKETS_NUMBER) is resolved at
 * dispatch time by WhatsappChannel; if missing or disabled, the channel
 * logs and skips. The strategy still emits the message — channel-level
 * gating is the channel's responsibility.
 */
final class TicketCreatedStrategy extends AbstractTicketStrategy
{
    public function supports(EventInterface $event): bool
    {
        return $event instanceof TicketCreated;
    }

    public function buildMessages(EventInterface $event): iterable
    {
        return $this->safeBuild(fn(): Generator => $this->doBuild($event), $event);
    }

    private function doBuild(EventInterface $event): Generator
    {
        if (!$event instanceof TicketCreated) {
            return;
        }

        $ticket = $this->fetchTable('Tickets')->get($event->ticketId, contain: ['Requesters']);

        $excludeEmails = array_filter([
            strtolower((string)($ticket->requester->email ?? '')),
            $this->gmailUserEmail(),
        ]);

        $emailTo = $this->filterRecipients($ticket->email_to ?? null, $excludeEmails);
        $emailCc = $this->filterRecipients($ticket->email_cc ?? null, $excludeEmails);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: $this->renderer()->getTicketUrl($ticket->id),
            recipientName: (string)($ticket->requester->name ?? ''),
        );
        $rendered = $this->templates()->get('ticket_created')->render($ctx);

        if (!empty($ticket->requester->email)) {
            yield NotificationMessage::email(
                recipient: (string)$ticket->requester->email,
                subject: $rendered->subject,
                bodyHtml: $rendered->bodyHtml,
                additionalTo: $emailTo,
                additionalCc: $emailCc,
                metadata: ['ticket_id' => $ticket->id, 'event' => $event->getName()],
            );
        }

        $waNumber = (string)($this->config?->toSettingsArray()[SettingKeys::WHATSAPP_TICKETS_NUMBER] ?? '');
        if ($waNumber !== '') {
            $text = $this->renderer()->renderWhatsappNewTicket($ticket);
            yield NotificationMessage::whatsapp(
                recipient: $waNumber,
                bodyText: $text,
                metadata: ['ticket_id' => $ticket->id, 'event' => $event->getName()],
            );
        } else {
            Log::info('WhatsApp tickets number not configured, skipping WhatsApp message');
        }
    }
}
