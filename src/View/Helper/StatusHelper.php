<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Constants\TicketConstants;
use Cake\View\Helper;

/**
 * Status Helper
 *
 * Thin layer over TicketConstants. Renders inline-styled badges
 * (deprecated; replace with view elements in next cycle — Alto 4.4 audit).
 */
class StatusHelper extends Helper
{
    /**
     * @param string $priority Priority key
     * @return string Hex color
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
     * @return string Hex color
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
        $style = 'background-color: %s; color: white; border-radius: 8px; '
            . 'padding: 0.35rem 0.65rem; font-size: 0.75rem; '
            . 'font-weight: 600; text-transform: uppercase;';
        $badge = sprintf(
            '<span class="badge" style="' . $style . '">%s</span>',
            h($this->statusColor($status)),
            h($this->statusLabel($status)),
        );

        $url = $options['url'] ?? null;
        if ($url) {
            return $this->getView()->Html->link(
                $badge,
                $url,
                ['escape' => false, 'class' => 'text-decoration-none'],
            );
        }

        return $badge;
    }

    /**
     * @param string $priority Priority key
     * @param array $options Optional ['url' => mixed]
     * @return string HTML badge
     */
    public function priorityBadge(string $priority, array $options = []): string
    {
        $style = 'background-color: %s; color: white; border-radius: 8px; '
            . 'padding: 0.35rem 0.65rem; font-size: 0.75rem; '
            . 'font-weight: 600; text-transform: uppercase;';
        $badge = sprintf(
            '<span class="badge" style="' . $style . '">%s</span>',
            h($this->priorityColor($priority)),
            h($this->priorityLabel($priority)),
        );

        $url = $options['url'] ?? null;
        if ($url) {
            return $this->getView()->Html->link(
                $badge,
                $url,
                ['escape' => false, 'class' => 'text-decoration-none'],
            );
        }

        return $badge;
    }
}
