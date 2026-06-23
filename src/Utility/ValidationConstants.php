<?php
declare(strict_types=1);

namespace App\Utility;

/**
 * Centralized validation constants for entity fields.
 *
 * Eliminates hardcoded arrays duplicated across 9+ Table classes.
 */
final class ValidationConstants
{
    // ── Roles ───────────────────────────────────────────────────────────
    public const ROLE_ADMIN = 'admin';
    public const ROLE_AGENT = 'agent';
    public const ROLE_SERVICIO_CLIENTE = 'servicio_cliente';
    public const ROLE_REQUESTER = 'requester';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_AGENT,
        self::ROLE_SERVICIO_CLIENTE,
        self::ROLE_REQUESTER,
    ];

    /**
     * Roles that can access the internal staff panel
     */
    public const STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_AGENT,
        self::ROLE_SERVICIO_CLIENTE,
    ];

    // ── Priorities ──────────────────────────────────────────────────────
    public const PRIORITIES = ['baja', 'media', 'alta', 'urgente'];

    // ── Ticket statuses ─────────────────────────────────────────────────
    public const STATUS_NUEVO = 'nuevo';
    public const STATUS_ABIERTO = 'abierto';
    public const STATUS_EN_PROGRESO = 'en_progreso';
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_RESUELTO = 'resuelto';
    public const STATUS_CERRADO = 'cerrado';

    public const TICKET_STATUSES = [
        self::STATUS_NUEVO,
        self::STATUS_ABIERTO,
        self::STATUS_EN_PROGRESO,
        self::STATUS_PENDIENTE,
        self::STATUS_RESUELTO,
        self::STATUS_CERRADO,
    ];

    // ── Comment types ───────────────────────────────────────────────────
    public const COMMENT_PUBLIC = 'public';
    public const COMMENT_INTERNAL = 'internal';
    public const COMMENT_SYSTEM = 'system';

    public const TICKET_COMMENT_TYPES = [self::COMMENT_PUBLIC, self::COMMENT_INTERNAL, self::COMMENT_SYSTEM];
    public const COMMENT_TYPES = [self::COMMENT_PUBLIC, self::COMMENT_INTERNAL];

    // ── Cache keys ──────────────────────────────────────────────────────
    public const CACHE_SETTINGS = 'system_settings';
    public const CACHE_CONFIG = '_cake_core_';

    // ── System defaults ─────────────────────────────────────────────────
    public const DEFAULT_SYSTEM_TITLE = 'Mesa de Ayuda';
}
