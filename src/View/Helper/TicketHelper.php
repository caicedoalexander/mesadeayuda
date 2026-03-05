<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Ticket Helper
 *
 * Encapsulates presentation logic for ticket views.
 * Authorization logic moved to AuthorizationService.
 */
class TicketHelper extends Helper
{
    /**
     * Get the appropriate view URL for a ticket
     *
     * @param object $ticket Ticket entity
     * @return array URL array for Html helper
     */
    public function getViewUrl($ticket): array
    {
        return ['action' => 'view', $ticket->id];
    }
}
