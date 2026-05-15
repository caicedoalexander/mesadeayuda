<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Boxed section with small uppercase label and raw HTML content.
 *
 * Variants:
 *   dashed → "Próximos pasos" style (dashed border, light bg).
 *   solid  → simple bordered box.
 *   soft   → accent-tinted background (needs $accentSoft).
 */
final class InfoBox
{
    public const VARIANT_DASHED = 'dashed';
    public const VARIANT_SOLID = 'solid';
    public const VARIANT_SOFT = 'soft';

    public static function render(
        string $label,
        string $contentHtml,
        string $variant = self::VARIANT_SOLID,
        ?string $accentSoft = null,
    ): string {
        $border = $variant === self::VARIANT_DASHED ? '1px dashed #E5E7EB' : '1px solid #E5E7EB';
        $bg = $variant === self::VARIANT_SOFT && $accentSoft !== null
            ? $accentSoft
            : '#FAFAFA';

        $boxStyle = 'border:' . $border . ';border-radius:10px;'
            . 'padding:16px 18px;margin-bottom:20px;background:' . $bg . ';';
        $labelStyle = 'font-size:11px;font-weight:600;color:#6B7280;'
            . 'letter-spacing:0.5px;text-transform:uppercase;margin-bottom:10px;';

        return '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>'
            . $contentHtml
            . '</div>';
    }
}
