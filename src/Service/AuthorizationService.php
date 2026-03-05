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
     * Check if assignment is disabled for a user on a given entity type
     *
     * @param string $entityType Entity type: 'ticket', 'pqrs', or 'compra'
     * @param mixed $user User identity object or array
     * @return bool True if assignment should be disabled
     */
    public function isAssignmentDisabled(string $entityType, $user): bool
    {
        if (!$user) {
            return true;
        }

        $userRole = is_object($user) ? ($user->role ?? $user->get('role')) : ($user['role'] ?? null);

        $allowedRoles = match ($entityType) {
            'ticket' => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT],
            'compra' => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_COMPRAS],
            'pqrs' => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_SERVICIO_CLIENTE],
            default => [ValidationConstants::ROLE_ADMIN],
        };

        return !in_array($userRole, $allowedRoles);
    }
}
