<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\Component\Greeting;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\EmailTheme;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\SubjectFormatter;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Component\CommentBlock;
use App\Notification\Email\Ticket\Component\TicketCard;
use DateTimeInterface;

/**
 * Notifies the requester that an agent left a new comment (without a status change).
 * Theme: comentario (green). Sent to requester only.
 *
 * SECURITY: $ctx->comment->body must arrive sanitized via HtmlSanitizerTrait.
 */
final class TicketCommentAddedTemplate implements EmailTemplate
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'ticket_comment_added';
    }

    /**
     * @inheritDoc
     */
    public function render(TemplateContext $ctx): RenderedEmail
    {
        $theme = EmailTheme::comentario();
        $agentName = $this->resolveAgentName($ctx);
        $agentRole = (string)($ctx->actor->role ?? '');
        $body = (string)($ctx->comment?->body ?? '');

        $subject = SubjectFormatter::reply(
            $agentName . ' te respondió en el ticket #' . $ctx->ticket->ticket_number,
        );

        $timestamp = '';
        $created = $ctx->comment?->get('created');
        if ($created instanceof DateTimeInterface) {
            $timestamp = $created->format('d M · H:i');
        }

        $inner =
            Greeting::render(
                headline: 'Tienes una nueva respuesta',
                intro: $agentName
                    . ' respondió a tu ticket. Puedes contestar desde la mesa de ayuda o respondiendo este correo.',
                recipientName: $ctx->recipientName,
            )
            . CommentBlock::render(
                authorName: $agentName,
                authorRole: $agentRole,
                authorColor: $theme->accent,
                bodyHtml: $body,
                accent: $theme->accent,
                accentSoft: $theme->accentSoft,
                timestamp: $timestamp,
            )
            . TicketCard::render($ctx->ticket)
            . $this->renderReplyHint($theme);

        return new RenderedEmail($subject, EmailFrame::render(
            $theme,
            $inner,
            '#' . $ctx->ticket->ticket_number,
        ));
    }

    /**
     * @param \App\Notification\Email\TemplateContext $ctx Context with optional actor
     * @return string Display name, falling back to "Mesa de Ayuda"
     */
    private function resolveAgentName(TemplateContext $ctx): string
    {
        if ($ctx->actor === null) {
            return 'Mesa de Ayuda';
        }

        $name = trim((string)($ctx->actor->name ?? ''));

        return $name === '' ? 'Mesa de Ayuda' : $name;
    }

    /**
     * @param \App\Notification\Email\EmailTheme $theme Theme used for the hint icon
     * @return string Reply-hint HTML
     */
    private function renderReplyHint(EmailTheme $theme): string
    {
        $wrap = 'display:flex;align-items:center;gap:12px;padding:12px 14px;'
            . 'margin-bottom:20px;background:#FAFAFA;border:1px solid #E5E7EB;'
            . 'border-radius:8px;font-size:12px;color:#4B5563;line-height:1.5;';

        $icon = '<span style="display:inline-flex;align-items:center;justify-content:center;'
            . 'width:34px;height:34px;border-radius:50%;flex-shrink:0;'
            . 'background:' . $theme->accentSoft . ';color:' . $theme->accentInk
            . ';font-weight:700;">↩</span>';

        $text = '<div><div style="font-weight:600;color:#111827;margin-bottom:2px;">'
            . 'Responde desde este mismo correo</div>'
            . 'Cualquier texto que envíes responderá automáticamente al hilo del ticket'
            . ' y se notificará al agente.</div>';

        return '<div style="' . $wrap . '">' . $icon . $text . '</div>';
    }
}
