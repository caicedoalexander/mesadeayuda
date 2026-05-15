<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\CtaButton;
use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\CommentBlock;
use App\Notification\Email\Ticket\Component\StatusTransition;
use App\Notification\Email\Ticket\Component\TicketCard;

/**
 * Combo notification: status changed AND new comment in the same operation.
 * Theme: actualizacion (purple). Sent to requester only.
 */
final class TicketUpdatedTemplate implements EmailTemplate
{
    public function key(): string
    {
        return 'ticket_updated';
    }

    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::actualizacion();
        $agentName = $this->resolveAgentName($ctx);

        $subject = $agentName . ' actualizó tu ticket #' . $ctx->ticket->ticket_number;

        $inner =
            Greeting::render(
                headline: 'Tu ticket fue actualizado',
                intro: 'hubo dos cambios en tu ticket: cambió el estado y un agente añadió un comentario. Aquí el detalle:',
                recipientName: $ctx->recipientName,
            )
            . $this->renderBadgeBanner($theme)
            . StatusTransition::render(
                (string)($ctx->oldStatus ?? ''),
                (string)($ctx->newStatus ?? ''),
                $theme->accent,
            )
            . CommentBlock::render(
                authorName: $agentName,
                authorRole: (string)($ctx->actor->role ?? ''),
                authorColor: $theme->accent,
                bodyHtml: (string)($ctx->comment?->body ?? ''),
                accent: $theme->accent,
                accentSoft: $theme->accentSoft,
                timestamp: '',
            )
            . TicketCard::render($ctx->ticket)
            . CtaButton::render('Ver actualización completa', $theme->accent, $ctx->ticketUrl);

        return new RenderedEmail($subject, EmailFrame::render(
            $theme,
            $inner,
            '#' . $ctx->ticket->ticket_number,
        ));
    }

    private function resolveAgentName(TemplateContext $ctx): string
    {
        if ($ctx->actor === null) {
            return 'Mesa de Ayuda';
        }

        $name = trim((string)($ctx->actor->name ?? ''));

        return $name === '' ? 'Mesa de Ayuda' : $name;
    }

    private function renderBadgeBanner(EmailTheme $theme): string
    {
        $wrap = 'display:flex;align-items:center;gap:8px;padding:12px 14px;'
            . 'margin-bottom:18px;background:' . $theme->accentSoft . ';border-radius:10px;';
        $pill = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
            . 'border-radius:999px;background:#fff;color:' . $theme->accentInk . ';'
            . 'font-size:11px;font-weight:600;border:1px solid ' . $theme->accent . '33;';

        return '<div style="' . $wrap . '">'
            . '<span style="' . $pill . '">↻ Cambio de estado</span>'
            . '<span style="color:' . $theme->accentInk . ';font-size:11px;font-weight:600;">+</span>'
            . '<span style="' . $pill . '">💬 Comentario del agente</span>'
            . '</div>';
    }
}
