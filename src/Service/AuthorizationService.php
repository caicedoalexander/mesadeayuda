<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use Authentication\IdentityInterface;

/**
 * Authorization Service
 *
 * Centralizes role-based authorization checks that were previously
 * scattered across view helpers (UserHelper).
 */
class AuthorizationService
{
    /**
     * Check if ticket assignment is disabled for the given identity.
     *
     * Callers always pass the result of $this->Authentication->getIdentity()
     * (or a stored copy), which is null when unauthenticated and an
     * IdentityInterface otherwise — no array probing needed.
     *
     * @param \Authentication\IdentityInterface|null $user Authenticated identity
     * @return bool True if assignment should be disabled
     */
    public function isAssignmentDisabled(?IdentityInterface $user): bool
    {
        if ($user === null) {
            return true;
        }

        $userRole = $user->get('role');

        return !in_array($userRole, [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT], true);
    }
}
