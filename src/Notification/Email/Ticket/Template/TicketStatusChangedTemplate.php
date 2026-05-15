<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\Avatar;
use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\StatusTransition;
use App\Notification\Email\Ticket\Component\TicketCard;
use App\Service\Renderer\NotificationRenderer;

/**
 * Notifies the requester that the ticket status changed.
 * Theme: estado (blue). Sent to requester only.
 */
final class TicketStatusChangedTemplate implements EmailTemplate
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'ticket_status_changed';
    }

    /**
     * @inheritDoc
     */
    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::estado();
        $oldStatus = (string)($ctx->oldStatus ?? '');
        $newStatus = (string)($ctx->newStatus ?? '');

        $renderer = new NotificationRenderer();
        $newLabel = $renderer->getStatusLabel($newStatus);
        $subject = 'El estado de tu ticket #' . $ctx->ticket->ticket_number
            . ' cambió a ' . $newLabel;

        $inner =
            Greeting::render(
                headline: 'El estado de tu ticket cambió',
                intro: 'te avisamos porque hay un cambio en el seguimiento. '
                    . 'El nuevo estado refleja la acción más reciente del agente:',
                recipientName: $ctx->recipientName,
            )
            . StatusTransition::render($oldStatus, $newStatus, $theme->accent)
            . TicketCard::render($ctx->ticket)
            . $this->renderActorBanner($ctx, $theme)
            . CtaButton::render('Ver el ticket', $theme->accent, $ctx->ticketUrl);

        $body = EmailFrame::render($theme, $inner, '#' . $ctx->ticket->ticket_number);

        return new RenderedEmail($subject, $body);
    }

    /**
     * @param \App\Notification\Email\TemplateContext $ctx Context with optional actor
     * @param \App\Notification\Email\EmailTheme $theme Theme used for the banner
     * @return string Banner HTML, or empty string when no actor
     */
    private function renderActorBanner(TemplateContext $ctx, EmailTheme $theme): string
    {
        if ($ctx->actor === null) {
            return '';
        }

        $name = (string)($ctx->actor->name ?? '');
        if (trim($name) === '') {
            return '';
        }

        $initials = Avatar::initialsFromName($name);
        $avatar = Avatar::render($initials, $theme->accent, 22);

        $banner = 'display:flex;align-items:center;gap:10px;padding:10px 14px;'
            . 'margin-bottom:20px;background:' . $theme->accentSoft . ';'
            . 'border-radius:8px;font-size:12px;color:' . $theme->accentInk . ';';

        return '<div style="' . $banner . '">' . $avatar
            . '<span><strong style="font-weight:600;">'
            . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</strong> aplicó este cambio.</span></div>';
    }
}
