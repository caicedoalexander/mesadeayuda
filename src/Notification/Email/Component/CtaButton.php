<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Large accent-colored call-to-action button + plain-text fallback URL line.
 * Generic — receives label, color and URL.
 */
final class CtaButton
{
    public static function render(string $label, string $accent, string $url): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $buttonStyle = 'display:inline-block;padding:12px 22px;border-radius:9px;'
            . 'background:' . $accent . ';color:#fff;font-size:14px;font-weight:600;'
            . 'text-decoration:none;line-height:1;'
            . 'box-shadow:0 4px 12px -3px ' . $accent . '66, inset 0 1px 0 rgba(255,255,255,0.2);';

        $arrow = ' <span style="display:inline-block;margin-left:6px;">&rarr;</span>';

        $fallback = '<div style="font-size:11px;color:#9CA3AF;margin-top:10px;'
            . 'font-family:Geist Mono,Menlo,Consolas,monospace;">'
            . 'o pega este enlace en tu navegador: ' . $safeUrl
            . '</div>';

        return '<div style="margin:8px 0 6px;">'
            . '<a href="' . $safeUrl . '" style="' . $buttonStyle . '">' . $safeLabel . $arrow . '</a>'
            . $fallback
            . '</div>';
    }
}
