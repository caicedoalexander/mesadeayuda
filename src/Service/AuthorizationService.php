<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;

/**
 * Authorization Service
 *
 * Centralizes role-based authorization checks that were previously
 * scattered across view helpers (UserHelper).
 */
class AuthorizationService
{
    /**
     * Check if ticket assignment is disabled for a user.
     *
     * @param mixed $user User identity object or array
     * @return bool True if assignment should be disabled
     */
    public function isAssignmentDisabled(mixed $user): bool
    {
        if (!$user) {
            return true;
        }

        $userRole = is_object($user) ? ($user->role ?? $user->get('role')) : ($user['role'] ?? null);

        return !in_array($userRole, [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT], true);
    }
}
