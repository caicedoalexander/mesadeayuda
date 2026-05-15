<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

use App\Notification\Email\Component\Pill;

/**
 * Visual "before → after" status block: two boxes with the status pills
 * and an arrow between them; the "after" box gets the accent border.
 */
final class StatusTransition
{
    public static function render(string $from, string $to, string $accent): string
    {
        $boxStyle = 'border:1px solid #E5E7EB;border-radius:10px;'
            . 'padding:16px 18px;margin-bottom:20px;background:#FAFAFA;';
        $labelStyle = 'font-size:11px;font-weight:600;color:#6B7280;'
            . 'letter-spacing:0.5px;text-transform:uppercase;margin-bottom:12px;';

        $beforeMicro = 'font-size:10px;color:#9CA3AF;margin-bottom:4px;font-weight:600;'
            . 'letter-spacing:0.4px;text-transform:uppercase;';
        $afterMicro = 'font-size:10px;color:' . $accent . ';margin-bottom:4px;font-weight:600;'
            . 'letter-spacing:0.4px;text-transform:uppercase;';

        $before = '<td style="width:45%;padding:10px 12px;background:#fff;'
            . 'border:1px solid #E5E7EB;border-radius:8px;vertical-align:top;">'
            . '<div style="' . $beforeMicro . '">Antes</div>' . Pill::forStatus($from)
            . '</td>';

        $arrow = '<td style="width:10%;text-align:center;font-size:18px;color:' . $accent . ';">→</td>';

        $after = '<td style="width:45%;padding:10px 12px;background:#fff;'
            . 'border:1px solid ' . $accent . ';border-radius:8px;'
            . 'box-shadow:0 0 0 3px ' . $accent . '1a;vertical-align:top;">'
            . '<div style="' . $afterMicro . '">Ahora</div>' . Pill::forStatus($to)
            . '</td>';

        $table = '<table role="presentation" cellspacing="0" cellpadding="0" '
            . 'style="width:100%;border-collapse:separate;border-spacing:14px 0;">'
            . '<tr>' . $before . $arrow . $after . '</tr></table>';

        return '<div style="' . $boxStyle . '">'
            . '<div style="' . $labelStyle . '">Cambio aplicado</div>'
            . $table
            . '</div>';
    }
}
