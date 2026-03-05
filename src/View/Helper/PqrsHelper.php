<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * PQRS Helper
 *
 * Encapsulates presentation logic specific to PQRS views.
 * SLA display logic is now in SlaHelper.
 * Status/type colors and labels are in StatusHelper.
 */
class PqrsHelper extends Helper
{
    /**
     * Render type badge
     *
     * @param string $type PQRS type
     * @return string HTML badge
     */
    public function typeBadge(string $type): string
    {
        $colors = [
            'peticion' => 'primary',
            'queja' => 'warning',
            'reclamo' => 'danger',
            'sugerencia' => 'success',
        ];
        $labels = [
            'peticion' => 'Petición',
            'queja' => 'Queja',
            'reclamo' => 'Reclamo',
            'sugerencia' => 'Sugerencia',
        ];

        $color = $colors[$type] ?? 'secondary';
        $label = $labels[$type] ?? ucfirst($type);

        return sprintf(
            '<span class="fw-bold text-dark text-uppercase %s">%s</span>',
            h($color),
            h($label)
        );
    }

    /**
     * Render status badge
     *
     * @param string $status PQRS status
     * @return string HTML badge
     */
    public function statusBadge(string $status): string
    {
        $colors = [
            'nuevo' => 'warning',
            'en_revision' => 'info',
            'en_proceso' => 'primary',
            'resuelto' => 'success',
            'cerrado' => 'secondary',
        ];
        $labels = [
            'nuevo' => 'Nuevo',
            'en_revision' => 'En Revisión',
            'en_proceso' => 'En Proceso',
            'resuelto' => 'Resuelto',
            'cerrado' => 'Cerrado',
        ];

        $color = $colors[$status] ?? 'secondary';
        $label = $labels[$status] ?? ucfirst($status);

        return sprintf(
            '<span style="border-radius: 8px;" class="small px-2 py-1 text-white fw-bold text-uppercase bg-%s">%s</span>',
            h($color),
            h($label)
        );
    }
}
