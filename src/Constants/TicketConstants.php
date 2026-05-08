<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Ticket domain constants — single source of truth for status, priority,
 * comment types, and their presentation (labels, colors, icons).
 */
final class TicketConstants
{
    public const STATUS_NUEVO     = 'nuevo';
    public const STATUS_ABIERTO   = 'abierto';
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_RESUELTO  = 'resuelto';

    public const STATUSES = [
        self::STATUS_NUEVO,
        self::STATUS_ABIERTO,
        self::STATUS_PENDIENTE,
        self::STATUS_RESUELTO,
    ];

    public const RESOLVED_STATUSES = [
        self::STATUS_RESUELTO,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_NUEVO,
        self::STATUS_ABIERTO,
        self::STATUS_PENDIENTE,
    ];

    public const STATUS_LABELS = [
        self::STATUS_NUEVO     => 'Nuevo',
        self::STATUS_ABIERTO   => 'Abierto',
        self::STATUS_PENDIENTE => 'Pendiente',
        self::STATUS_RESUELTO  => 'Resuelto',
    ];

    public const STATUS_COLORS = [
        self::STATUS_NUEVO     => '#ffc107',
        self::STATUS_ABIERTO   => '#dc3545',
        self::STATUS_PENDIENTE => '#0d6efd',
        self::STATUS_RESUELTO  => '#198754',
    ];

    public const STATUS_ICONS = [
        self::STATUS_NUEVO     => 'bi-circle-fill',
        self::STATUS_ABIERTO   => 'bi-circle-fill',
        self::STATUS_PENDIENTE => 'bi-circle-fill',
        self::STATUS_RESUELTO  => 'bi-circle-fill',
    ];

    public const PRIORITY_BAJA    = 'baja';
    public const PRIORITY_MEDIA   = 'media';
    public const PRIORITY_ALTA    = 'alta';
    public const PRIORITY_URGENTE = 'urgente';

    public const PRIORITIES = [
        self::PRIORITY_BAJA,
        self::PRIORITY_MEDIA,
        self::PRIORITY_ALTA,
        self::PRIORITY_URGENTE,
    ];

    public const PRIORITY_LABELS = [
        self::PRIORITY_BAJA    => 'Baja',
        self::PRIORITY_MEDIA   => 'Media',
        self::PRIORITY_ALTA    => 'Alta',
        self::PRIORITY_URGENTE => 'Urgente',
    ];

    public const PRIORITY_COLORS = [
        self::PRIORITY_BAJA    => '#6c757d',
        self::PRIORITY_MEDIA   => '#0dcaf0',
        self::PRIORITY_ALTA    => '#ffc107',
        self::PRIORITY_URGENTE => '#dc3545',
    ];

    public const COMMENT_PUBLIC   = 'public';
    public const COMMENT_INTERNAL = 'internal';
    public const COMMENT_SYSTEM   = 'system';

    public const COMMENT_TYPES = [self::COMMENT_PUBLIC, self::COMMENT_INTERNAL];

    public const ALL_COMMENT_TYPES = [self::COMMENT_PUBLIC, self::COMMENT_INTERNAL, self::COMMENT_SYSTEM];
}
