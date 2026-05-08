<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Constants\TicketConstants;
use Cake\View\Helper;

/**
 * Status Helper
 *
 * Thin layer over TicketConstants. Defers HTML rendering to
 * templates/element/tickets/{status,priority}_badge.php with CSS classes
 * defined in webroot/css/badges.css.
 */
class StatusHelper extends Helper
{
    /**
     * @param string $priority Priority key
     * @return string Hex color (kept for non-badge consumers)
     */
    public function priorityColor(string $priority): string
    {
        return TicketConstants::PRIORITY_COLORS[strtolower($priority)] ?? '#6c757d';
    }

    /**
     * @param string $priority Priority key
     * @return string Human-readable label
     */
    public function priorityLabel(string $priority): string
    {
        return TicketConstants::PRIORITY_LABELS[strtolower($priority)] ?? ucfirst($priority);
    }

    /**
     * @param string $status Status key
     * @return string Hex color (kept for non-badge consumers)
     */
    public function statusColor(string $status): string
    {
        return TicketConstants::STATUS_COLORS[strtolower($status)] ?? '#6c757d';
    }

    /**
     * @param string $status Status key
     * @return string Human-readable label
     */
    public function statusLabel(string $status): string
    {
        return TicketConstants::STATUS_LABELS[strtolower($status)]
            ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * @param string $status Status key
     * @param array $options Optional ['url' => mixed]
     * @return string HTML badge
     */
    public function statusBadge(string $status, array $options = []): string
    {
        $key = strtolower($status);

        return $this->getView()->element('tickets/status_badge', [
            'status' => $key,
            'label' => $this->statusLabel($key),
            'url' => $options['url'] ?? null,
        ]);
    }

    /**
     * @param string $priority Priority key
     * @param array $options Optional ['url' => mixed]
     * @return string HTML badge
     */
    public function priorityBadge(string $priority, array $options = []): string
    {
        $key = strtolower($priority);

        return $this->getView()->element('tickets/priority_badge', [
            'priority' => $key,
            'label' => $this->priorityLabel($key),
            'url' => $options['url'] ?? null,
        ]);
    }
}
