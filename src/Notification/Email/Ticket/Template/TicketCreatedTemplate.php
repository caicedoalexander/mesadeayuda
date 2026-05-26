<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Service\Renderer\NotificationRenderer;

/**
 * Notifies the requester that their ticket was created.
 * Plain-text style: a short paragraph + status/priority line. Subject is
 * not "Re:" prefixed — this is the root of the conversation.
 */
final class TicketCreatedTemplate implements EmailTemplate
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'ticket_created';
    }

    /**
     * @inheritDoc
     */
    public function render(TemplateContext $ctx): RenderedEmail
    {
        $subject = 'Tu ticket #' . $ctx->ticket->ticket_number . ' fue creado';

        $renderer = new NotificationRenderer();
        $statusLabel = $renderer->getStatusLabel((string)$ctx->ticket->status);
        $priorityLabel = ucfirst((string)$ctx->ticket->priority);

        $name = htmlspecialchars(trim((string)$ctx->recipientName), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketNumber = htmlspecialchars((string)$ctx->ticket->ticket_number, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketSubject = htmlspecialchars((string)$ctx->ticket->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $body = '<p>Hola ' . ($name === '' ? '' : $name) . ',</p>'
            . '<p>Recibimos tu solicitud y creamos el ticket #' . $ticketNumber
            . ' (' . $ticketSubject . ').<br>'
            . 'Un agente la tomará en los próximos 30 minutos.</p>'
            . '<p>Estado: ' . htmlspecialchars($statusLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . ' &nbsp; Prioridad: ' . htmlspecialchars($priorityLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</p>'
            . '<p>Responde a este correo para añadir información.</p>';

        return new RenderedEmail($subject, EmailFrame::render($body));
    }
}
