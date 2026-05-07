<?php
declare(strict_types=1);

namespace App\Service;

use App\Utility\ValidationConstants;

/**
 * Authorization Service
 *
 * Centralizes role-based authorization checks that were previously
 * scattered across view helpers (TicketHelper, UserHelper).
 */
class AuthorizationService
{
    /**
     * Check if ticket assignment is disabled for a user.
     *
     * @param mixed $user User identity object or array
     * @return bool True if assignment should be disabled
     */
    public function isAssignmentDisabled($user): bool
    {
        if (!$user) {
            return true;
        }

        $userRole = is_object($user) ? ($user->role ?? $user->get('role')) : ($user['role'] ?? null);

        return !in_array($userRole, [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT], true);
    }
}
