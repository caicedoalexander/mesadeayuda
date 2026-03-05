<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Model\Entity\Compra;
use Cake\View\Helper;

/**
 * Compras Helper
 *
 * Encapsulates presentation logic specific to purchase order views.
 * SLA display logic is now in SlaHelper.
 * Status colors and labels are in StatusHelper.
 */
class ComprasHelper extends Helper
{
    /**
     * Get badge color for priority
     *
     * @param string $priority Priority level
     * @return string Bootstrap color class
     */
    public function getPriorityColor(string $priority): string
    {
        $colors = [
            'baja' => 'secondary',
            'media' => 'primary',
            'alta' => 'warning',
            'urgente' => 'danger',
        ];

        return $colors[$priority] ?? 'secondary';
    }

    /**
     * Get label for priority
     *
     * @param string $priority Priority level
     * @return string Human-readable label
     */
    public function getPriorityLabel(string $priority): string
    {
        $labels = [
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'urgente' => 'Urgente',
        ];

        return $labels[$priority] ?? ucfirst($priority);
    }

    /**
     * Render status badge
     *
     * @param string $status Compra status
     * @return string HTML badge
     */
    public function statusBadge(string $status): string
    {
        $colors = [
            'nuevo' => 'info',
            'en_revision' => 'warning',
            'aprobado' => 'success',
            'en_proceso' => 'primary',
            'completado' => 'success',
            'rechazado' => 'danger',
        ];
        $labels = [
            'nuevo' => 'Nuevo',
            'en_revision' => 'En Revisión',
            'aprobado' => 'Aprobado',
            'en_proceso' => 'En Proceso',
            'completado' => 'Completado',
            'rechazado' => 'Rechazado',
        ];

        $color = $colors[$status] ?? 'secondary';
        $label = $labels[$status] ?? ucfirst($status);

        return sprintf(
            '<span style="border-radius: 8px;" class="small px-2 py-1 text-white fw-bold text-uppercase bg-%s">%s</span>',
            h($color),
            h($label)
        );
    }

    /**
     * Render priority badge
     *
     * @param string $priority Priority level
     * @return string HTML badge
     */
    public function priorityBadge(string $priority): string
    {
        $color = $this->getPriorityColor($priority);
        $label = $this->getPriorityLabel($priority);

        return sprintf(
            '<span class="badge bg-%s">%s</span>',
            h($color),
            h($label)
        );
    }

    /**
     * Get view URL for a compra
     *
     * @param \App\Model\Entity\Compra $compra Compra entity
     * @return array URL array for Router
     */
    public function getViewUrl(Compra $compra): array
    {
        return [
            'controller' => 'Compras',
            'action' => 'view',
            $compra->id,
        ];
    }
}
