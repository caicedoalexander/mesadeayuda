<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Rounded badge with optional dot. Used for status, priority and tag labels.
 * Generic — does not depend on any domain entity.
 */
final class Pill
{
    /**
     * Status key → palette + label (mirrors the design's STATUS_THEME).
     *
     * @var array<string, array{bg:string,fg:string,dot:string,label:string}>
     */
    private const STATUS_THEME = [
        'nuevo' => ['bg' => '#FCEFE0', 'fg' => '#6b3306', 'dot' => '#CD6A15', 'label' => 'Nuevo'],
        'abierto' => ['bg' => '#FCE4E6', 'fg' => '#7a1a25', 'dot' => '#dc3545', 'label' => 'Abierto'],
        'pendiente' => ['bg' => '#E3EFFC', 'fg' => '#0a3a78', 'dot' => '#0066cc', 'label' => 'Pendiente'],
        'resuelto' => ['bg' => '#E6F7EE', 'fg' => '#00432a', 'dot' => '#00A85E', 'label' => 'Resuelto'],
    ];

    /**
     * Render a pill badge.
     *
     * @param string $label Visible text label (escaped)
     * @param string $bg Background hex color
     * @param string $fg Foreground hex color
     * @param string|null $dotColor Optional left-side dot hex color
     * @return string HTML markup
     */
    public static function render(
        string $label,
        string $bg,
        string $fg,
        ?string $dotColor = null,
    ): string {
        $style = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
            . 'border-radius:999px;background:' . $bg . ';color:' . $fg . ';'
            . 'font-size:11px;font-weight:600;line-height:1;letter-spacing:0.1px;white-space:nowrap;';

        $dot = '';
        if ($dotColor !== null) {
            $dot = '<span style="width:6px;height:6px;border-radius:50%;background:' . $dotColor . ';"></span>';
        }

        return '<span style="' . $style . '">'
            . $dot
            . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</span>';
    }

    /**
     * Convenience: render a pill for a known status key, falling back to
     * a capitalized form of the key when unknown.
     */
    public static function forStatus(string $statusKey): string
    {
        if (isset(self::STATUS_THEME[$statusKey])) {
            $t = self::STATUS_THEME[$statusKey];

            return self::render($t['label'], $t['bg'], $t['fg'], $t['dot']);
        }

        return self::render(ucfirst($statusKey), '#F3F4F6', '#374151');
    }
}
