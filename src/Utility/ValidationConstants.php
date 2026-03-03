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
    /**
     * Shared priority values across all modules
     */
    public const PRIORITIES = ['baja', 'media', 'alta', 'urgente'];

    /**
     * Ticket statuses
     */
    public const TICKET_STATUSES = ['nuevo', 'abierto', 'en_progreso', 'pendiente', 'resuelto', 'cerrado'];

    /**
     * Compra statuses
     */
    public const COMPRA_STATUSES = ['nuevo', 'en_revision', 'aprobado', 'en_proceso', 'completado', 'rechazado'];

    /**
     * PQRS statuses
     */
    public const PQRS_STATUSES = ['nuevo', 'en_revision', 'en_proceso', 'resuelto', 'cerrado'];

    /**
     * PQRS types
     */
    public const PQRS_TYPES = ['peticion', 'queja', 'reclamo', 'sugerencia'];

    /**
     * Ticket comment types (includes 'system')
     */
    public const TICKET_COMMENT_TYPES = ['public', 'internal', 'system'];

    /**
     * Standard comment types (compras and PQRS)
     */
    public const COMMENT_TYPES = ['public', 'internal'];
}
