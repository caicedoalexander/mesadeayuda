<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Utility\ValidationConstants;
use Cake\View\Helper;

/**
 * Ticket Helper
 *
 * Encapsulates presentation logic for ticket views.
 */
class TicketHelper extends Helper
{
    /**
     * Determine if the current user can assign tickets
     *
     * @param object $ticket Ticket entity
     * @param object|null $user Current user
     * @return bool
     */
    public function canAssign($ticket, $user): bool
    {
        if (!$user) {
            return false;
        }

        $userRole = $user->get('role');

        // Admin and agents can assign
        return in_array($userRole, [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT]);
    }

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

    /**
     * Determine if assignment dropdown should be disabled
     *
     * @param object|null $user Current user
     * @return bool
     */
    public function isAssignmentDisabled($user): bool
    {
        if (!$user) {
            return true;
        }

        $userRole = $user->get('role');

        // Only admin and agent can change assignments
        return !in_array($userRole, [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT]);
    }
}
