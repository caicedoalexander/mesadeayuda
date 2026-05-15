<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Headline (h1) + intro paragraph with "Hola {name}," prefix.
 * Generic — used at the top of every email body.
 */
final class Greeting
{
    public static function render(string $headline, string $intro, string $recipientName): string
    {
        $h = htmlspecialchars($headline, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $i = htmlspecialchars($intro, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $n = htmlspecialchars(trim($recipientName), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $headlineStyle = 'font-size:26px;font-weight:700;letter-spacing:-0.6px;'
            . 'color:#111827;margin:0;line-height:1.2;';
        $introStyle = 'font-size:14px;color:#4B5563;line-height:1.6;'
            . 'margin:12px 0 0;max-width:520px;';

        $intro = $n === ''
            ? $i
            : 'Hola <strong style="color:#111827;font-weight:600;">' . $n . '</strong>, ' . $i;

        return '<div style="margin-bottom:22px;">'
            . '<h1 style="' . $headlineStyle . '">' . $h . '</h1>'
            . '<p style="' . $introStyle . '">' . $intro . '</p>'
            . '</div>';
    }
}
