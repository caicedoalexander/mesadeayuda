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
 * Formats values (dates, URLs, status labels) and renders WhatsApp
 * text fragments used by integration services.
 *
 * Email body rendering lives in {@see \App\Notification\Email\TemplateRegistry}
 * and the component classes under {@see \App\Notification\Email\Component}.
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
