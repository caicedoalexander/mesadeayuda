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
 * Notifies the requester that the ticket status changed without a
 * public comment. Plain-text style.
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
        // Gmail threading: Subject must equal the original ticket subject.
        $subject = SubjectFormatter::reply((string)$ctx->ticket->subject);

        $renderer = new NotificationRenderer();
        $oldLabel = $renderer->getStatusLabel((string)($ctx->oldStatus ?? ''));
        $newLabel = $renderer->getStatusLabel((string)($ctx->newStatus ?? ''));

        $name = htmlspecialchars(trim((string)$ctx->recipientName), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketId = htmlspecialchars((string)$ctx->ticket->id, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ticketSubject = htmlspecialchars((string)$ctx->ticket->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $oldEsc = htmlspecialchars($oldLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $newEsc = htmlspecialchars($newLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $assignee = htmlspecialchars(self::resolveAssigneeName($ctx), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $body = '<p>Hola ' . $name . ',</p>'
            . '<p>El estado de tu ticket #' . $ticketId . ' (' . $ticketSubject . ') cambió:<br>'
            . '<strong>' . $oldEsc . ' → ' . $newEsc . '</strong></p>'
            . $this->renderActorLine($ctx)
            . '<p>Asignado: ' . $assignee . '</p>'
            . '<p>Responde a este correo si necesitas seguimiento.</p>';

        return new RenderedEmail($subject, EmailFrame::render($body));
    }

    /**
     * @param \App\Notification\Email\TemplateContext $ctx Context with optional actor
     * @return string Paragraph attributing the change, or empty string when no actor
     */
    private function renderActorLine(TemplateContext $ctx): string
    {
        if ($ctx->actor === null) {
            return '';
        }
        $name = trim((string)($ctx->actor->name ?? ''));
        if ($name === '') {
            return '';
        }

        return '<p>Aplicado por '
            . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '.</p>';
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
}
