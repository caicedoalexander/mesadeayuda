<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Status Helper
 *
 * Centralizes ticket status, priority and color definitions.
 */
class StatusHelper extends Helper
{
    private const PRIORITY_COLORS = [
        'baja' => '#6c757d',
        'media' => '#0dcaf0',
        'alta' => '#ffc107',
        'urgente' => '#dc3545',
    ];

    private const PRIORITY_LABELS = [
        'baja' => 'Baja',
        'media' => 'Media',
        'alta' => 'Alta',
        'urgente' => 'Urgente',
    ];

    private const TICKET_STATUS_COLORS = [
        'nuevo' => '#ffc107',
        'abierto' => '#dc3545',
        'pendiente' => '#0d6efd',
        'resuelto' => '#198754',
        'convertido' => '#6c757d',
    ];

    private const TICKET_STATUS_LABELS = [
        'nuevo' => 'Nuevo',
        'abierto' => 'Abierto',
        'pendiente' => 'Pendiente',
        'resuelto' => 'Resuelto',
        'convertido' => 'Convertido',
    ];

    public function priorityColor(string $priority): string
    {
        return self::PRIORITY_COLORS[strtolower($priority)] ?? '#6c757d';
    }

    public function priorityLabel(string $priority): string
    {
        return self::PRIORITY_LABELS[strtolower($priority)] ?? ucfirst($priority);
    }

    public function statusColor(string $status): string
    {
        return self::TICKET_STATUS_COLORS[strtolower($status)] ?? '#6c757d';
    }

    public function statusLabel(string $status): string
    {
        return self::TICKET_STATUS_LABELS[strtolower($status)] ?? ucfirst($status);
    }

    public function statusBadge(string $status, array $options = []): string
    {
        $badge = sprintf(
            '<span class="badge" style="background-color: %s; color: white; border-radius: 8px; padding: 0.35rem 0.65rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">%s</span>',
            h($this->statusColor($status)),
            h($this->statusLabel($status)),
        );

        $url = $options['url'] ?? null;
        if ($url) {
            return $this->getView()->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
        }

        return $badge;
    }

    public function priorityBadge(string $priority, array $options = []): string
    {
        $badge = sprintf(
            '<span class="badge" style="background-color: %s; color: white; border-radius: 8px; padding: 0.35rem 0.65rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">%s</span>',
            h($this->priorityColor($priority)),
            h($this->priorityLabel($priority)),
        );

        $url = $options['url'] ?? null;
        if ($url) {
            return $this->getView()->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
        }

        return $badge;
    }
}
