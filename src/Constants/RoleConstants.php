<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * User role constants.
 */
final class RoleConstants
{
    public const ROLE_ADMIN             = 'admin';
    public const ROLE_AGENT             = 'agent';
    public const ROLE_SERVICIO_CLIENTE  = 'servicio_cliente';
    public const ROLE_REQUESTER         = 'requester';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_AGENT,
        self::ROLE_SERVICIO_CLIENTE,
        self::ROLE_REQUESTER,
    ];

    public const STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_AGENT,
        self::ROLE_SERVICIO_CLIENTE,
    ];
}
