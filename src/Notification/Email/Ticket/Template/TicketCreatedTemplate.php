<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\Component\InfoBox;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\TicketCard;

/**
 * Notifies the requester that their ticket was created.
 * Theme: creacion (orange). Sent to requester only.
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
        $theme = EmailTheme::creacion();
        $subject = 'Tu ticket #' . $ctx->ticket->ticket_number . ' fue creado';

        $nextSteps =
            '<ol style="margin:0;padding-left:18px;font-size:13px;'
            . 'color:#374151;line-height:1.7;">'
            . '<li>Un agente tomará el ticket en los próximos <strong style="color:#111827;">30 minutos</strong>.</li>'
            . '<li>Recibirás un correo cuando el ticket sea asignado o cambie de estado.</li>'
            . '<li>Puedes añadir información respondiendo este correo o desde la mesa de ayuda.</li>'
            . '</ol>';

        $inner =
            Greeting::render(
                headline: 'Tu ticket fue creado',
                intro: 'hemos recibido tu solicitud y la asignaremos pronto a un agente. '
                    . 'Mientras tanto, este es el resumen:',
                recipientName: $ctx->recipientName,
            )
            . TicketCard::render($ctx->ticket)
            . InfoBox::render('Próximos pasos', $nextSteps, InfoBox::VARIANT_DASHED)
            . CtaButton::render('Ver mi ticket', $theme->accent, $ctx->ticketUrl);

        $body = EmailFrame::render($theme, $inner, '#' . $ctx->ticket->ticket_number);

        return new RenderedEmail($subject, $body);
    }
}
