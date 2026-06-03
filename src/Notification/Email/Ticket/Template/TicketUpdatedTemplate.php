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
 * Combo notification: status changed AND a new public comment in the same
 * operation. Plain-text style: comment quoted, single line with the
 * transition and assignee.
 *
 * SECURITY: $ctx->comment->body must arrive sanitized via HtmlSanitizerTrait.
 */
final class TicketUpdatedTemplate implements EmailTemplate
{
    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'ticket_updated';
    }

    /**
     * @inheritDoc
     */
    public function render(TemplateContext $ctx): RenderedEmail
    {
        // Gmail threading: Subject must equal the original ticket subject.
        $subject = SubjectFormatter::reply((string)$ctx->ticket->subject);

        $renderer = new NotificationRenderer();
        $oldLabel = $renderer->getStatusLabel((string)($ctx->oldStatus ?? ''));
        $newLabel = $renderer->getStatusLabel((string)($ctx->newStatus ?? ''));

        $name = htmlspecialchars(trim((string)$ctx->recipientName), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $agent = htmlspecialchars($this->resolveAgentName($ctx), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $assignee = htmlspecialchars(self::resolveAssigneeName($ctx), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketNumber = htmlspecialchars((string)$ctx->ticket->id, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketSubject = htmlspecialchars((string)$ctx->ticket->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $oldEsc = htmlspecialchars($oldLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $newEsc = htmlspecialchars($newLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $commentBody = (string)($ctx->comment?->body ?? '');

        $body = '<p>Hola ' . $name . ',</p>'
            . '<p>' . $agent . ' actualizó tu ticket #' . $ticketNumber
            . ' (' . $ticketSubject . '):</p>'
            . self::renderQuote($commentBody)
            . '<p>Estado: <strong>' . $oldEsc . ' → ' . $newEsc . '</strong>'
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
     * Comment body wrap: simple gray left border (blockquote-ish). The
     * bodyHtml is inserted raw (sanitized upstream).
     */
    private static function renderQuote(string $bodyHtml): string
    {
        $style = 'border-left:3px solid #D1D5DB;padding:4px 0 4px 16px;'
            . 'margin:8px 0 16px;color:#374151;';

        return '<div style="' . $style . '">' . $bodyHtml . '</div>';
    }
}
