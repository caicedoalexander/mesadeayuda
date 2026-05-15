<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

/**
 * Compact priority indicator: unicode arrow + label, colored by level.
 * Specific to ticket priority semantics (alta/media/baja).
 */
final class PriorityArrow
{
    /**
     * @var array<string, array{color:string, glyph:string, label:string}>
     */
    private const MAP = [
        'alta' => ['color' => '#dc3545', 'glyph' => '↑', 'label' => 'Alta'],
        'media' => ['color' => '#CD6A15', 'glyph' => '→', 'label' => 'Media'],
        'baja' => ['color' => '#6B7280', 'glyph' => '↓', 'label' => 'Baja'],
    ];

    /**
     * Render the priority indicator.
     *
     * @param string $priority Priority key (alta|media|baja); unknown values fall back to a gray pill
     * @return string HTML markup
     */
    public static function render(string $priority): string
    {
        $t = self::MAP[$priority] ?? [
            'color' => '#6B7280',
            'glyph' => '→',
            'label' => ucfirst($priority),
        ];

        return '<span style="display:inline-flex;align-items:center;gap:4px;'
            . 'font-size:11px;font-weight:600;color:' . $t['color'] . ';">'
            . '<span style="font-size:12px;">' . $t['glyph'] . '</span>'
            . htmlspecialchars($t['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</span>';
    }
}
