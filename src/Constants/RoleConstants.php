<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * User role constants.
 */
final class RoleConstants
{
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_ASESOR_TIC  = 'asesor_tic';
    public const ROLE_EXTERNAL    = 'external';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_ASESOR_TIC,
        self::ROLE_EXTERNAL,
    ];

    public const STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_ASESOR_TIC,
    ];
}
