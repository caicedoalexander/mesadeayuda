<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Template;

use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\EmailTemplate;
use App\Notification\Email\RenderedEmail;
use App\Notification\Email\SubjectFormatter;
use App\Notification\Email\TemplateContext;
use App\Service\Renderer\NotificationRenderer;

/**
 * Notifies the requester that an agent added a public comment without a
 * status change. Plain-text style: short paragraph, comment quoted, single
 * line of metadata.
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
        // Gmail API requires the outbound Subject to match the original
        // thread's Subject (after stripping Re:/Fwd:) for setThreadId() to
        // group the message into the same conversation.
        $subject = SubjectFormatter::reply((string)$ctx->ticket->subject);

        $renderer = new NotificationRenderer();
        $statusLabel = $renderer->getStatusLabel((string)$ctx->ticket->status);

        $name = htmlspecialchars(trim((string)$ctx->recipientName), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $agent = htmlspecialchars($this->resolveAgentName($ctx), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $assignee = htmlspecialchars(self::resolveAssigneeName($ctx), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketNumber = htmlspecialchars((string)$ctx->ticket->id, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketSubject = htmlspecialchars((string)$ctx->ticket->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $commentBody = (string)($ctx->comment?->body ?? '');

        $body = '<p>Hola ' . $name . ',</p>'
            . '<p>' . $agent . ' respondió a tu ticket #' . $ticketNumber
            . ' (' . $ticketSubject . '):</p>'
            . self::renderQuote($commentBody)
            . '<p>Estado: ' . htmlspecialchars($statusLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . ' &nbsp; Asignado: ' . $assignee . '</p>'
            . '<p>Responde a este correo para continuar.</p>';

        return new RenderedEmail($subject, EmailFrame::render($body));
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
     * @param \App\Notification\Email\TemplateContext $ctx Context with optional ticket assignee
     * @return string Assignee display name, falling back to "Sin asignar"
     */
    private static function resolveAssigneeName(TemplateContext $ctx): string
    {
        $assignee = $ctx->ticket->assignee ?? null;
        if ($assignee === null) {
            return 'Sin asignar';
        }
        $name = trim((string)($assignee->name ?? ''));

        return $name === '' ? 'Sin asignar' : $name;
    }

    /**
     * Comment body wrap: a simple gray left border to mimic an email
     * blockquote. The bodyHtml is inserted raw (sanitized upstream).
     */
    private static function renderQuote(string $bodyHtml): string
    {
        $style = 'border-left:3px solid #D1D5DB;padding:4px 0 4px 16px;'
            . 'margin:8px 0 16px;color:#374151;';

        return '<div style="' . $style . '">' . $bodyHtml . '</div>';
    }
}
