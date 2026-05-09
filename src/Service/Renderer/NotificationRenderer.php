<?php
declare(strict_types=1);

namespace App\Service\Renderer;

use App\Constants\TicketConstants;
use App\Model\Entity\Ticket;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use DateTimeInterface;

/**
 * Notification Renderer
 *
 * **Layer:** domain formatter for notifications.
 *
 * Formats values (dates, URLs, status labels) and renders HTML/text
 * fragments (attachment lists, status-change blocks, WhatsApp messages)
 * used to fill template variables.
 *
 * Does NOT load or render full email templates — for that, use
 * {@see \App\Service\EmailTemplateRenderer}.
 */
class NotificationRenderer
{
    /**
     * Format date for display
     *
     * @param \DateTimeInterface|string|null $date Date to format
     * @return string Formatted date
     */
    public function formatDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        if (!($date instanceof DateTime)) {
            $date = new DateTime($date);
        }

        return $date->i18nFormat('d MMMM, h:mm a', null, 'es_US');
    }

    /**
     * Get Ticket URL
     *
     * @param int $id Ticket ID
     * @return string Full URL
     */
    public function getTicketUrl(int $id): string
    {
        $baseUrl = Configure::read('App.fullBaseUrl', 'http://localhost:8765');

        return $baseUrl . '/tickets/view/' . $id;
    }

    /**
     * Get Status Label
     *
     * @param string $status Status key
     * @return string Human readable label
     */
    public function getStatusLabel(string $status): string
    {
        return TicketConstants::STATUS_LABELS[$status] ?? ucfirst($status);
    }

    /**
     * Render attachments list as HTML
     *
     * @param array $attachments Array of attachment entities
     * @return string HTML list
     */
    public function renderAttachmentsHtml(array $attachments): string
    {
        if (empty($attachments)) {
            return '';
        }

        $html = '<td>';
        $html .= '<p><strong>Archivos Adjuntos</strong></p><ul>';

        foreach ($attachments as $attachment) {
            $sizeKB = number_format($attachment->file_size / 1024, 1);
            $html .= "<li>{$attachment->original_filename} ({$sizeKB} KB)</li>";
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Render status change section as HTML
     *
     * @param string $oldStatus Old status key
     * @param string $newStatus New status key
     * @param string $assigneeName Assignee name
     * @return string HTML section
     */
    public function renderStatusChangeHtml(string $oldStatus, string $newStatus, string $assigneeName): string
    {
        $oldLabel = $this->getStatusLabel($oldStatus);
        $newLabel = $this->getStatusLabel($newStatus);

        return '<td>' .
            '<p><strong>Cambio de Estado</strong></p>' .
            '<p>' .
            '<span class="status-badge status-' . $oldStatus . '">' . $oldLabel . '</span>' .
            '<span style="margin: 0 10px;">→</span>' .
            '<span class="status-badge status-' . $newStatus . '">' . $newLabel . '</span>' .
            '</p>' .
            '<p>Asignado a: <strong>' . $assigneeName . '</strong></p>' .
            '</td>';
    }

    /**
     * Render WhatsApp message for new ticket
     *
     * @param \App\Model\Entity\Ticket $ticket Ticket entity
     * @return string Message text
     */
    public function renderWhatsappNewTicket(Ticket $ticket): string
    {
        $priorityLabel = TicketConstants::PRIORITY_LABELS[$ticket->priority] ?? ucfirst($ticket->priority);
        $channelLabel = $ticket->channel === TicketConstants::CHANNEL_EMAIL
            ? 'Correo electrónico'
            : ucfirst($ticket->channel);

        return "━━━━━━━━━━━━━━━━━━━━\n" .
            "*NUEVO TICKET DE SOPORTE*\n" .
            "━━━━━━━━━━━━━━━━━━━━\n\n" .
            "*{$ticket->ticket_number}*\n" .
            "{$ticket->subject}\n\n" .
            "*Solicitante:* {$ticket->requester->name}\n" .
            "*Correo:* {$ticket->requester->email}\n" .
            "*Prioridad:* {$priorityLabel}\n" .
            "*Canal:* {$channelLabel}\n" .
            "*Fecha:* {$this->formatDate($ticket->created)}\n\n" .
            '— _Mesa de Ayuda_';
    }
}
