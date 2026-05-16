<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

use App\Notification\Email\EmailBrand;
use App\Notification\Email\EmailTheme;

/**
 * Full email wrap: outer canvas + white card + accent bar + logo header +
 * inner content slot + footer with brand info.
 */
final class EmailFrame
{
    /**
     * Render the full email wrap around the given inner HTML.
     *
     * @param \App\Notification\Email\EmailTheme $theme Theme that drives the accent bar/footer colors
     * @param string $innerHtml Body HTML to embed
     * @param string $ticketReference Short ticket reference shown in header/footer
     * @return string HTML markup
     */
    public static function render(EmailTheme $theme, string $innerHtml, string $ticketReference): string
    {
        $canvasStyle = 'background:#E8E6E1;padding:32px 20px;'
            . 'font-family:Geist,-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;'
            . 'color:#111827;';
        $cardStyle = 'max-width:720px;margin:0 auto;background:#fff;'
            . 'border-radius:10px;overflow:hidden;'
            . 'box-shadow:0 12px 32px -16px rgba(15,23,42,0.18);';

        $accentBar = '<div style="height:4px;background:' . $theme->accent . ';"></div>';
        $header = self::renderHeader($ticketReference);
        $body = '<div style="padding:32px 48px 28px;">' . $innerHtml . '</div>';
        $footer = self::renderFooter($theme, $ticketReference);

        return '<div style="' . $canvasStyle . '">'
            . '<div style="' . $cardStyle . '">'
            . $accentBar . $header . $body . $footer
            . '</div></div>';
    }

    /**
     * @param string $ticketReference Short ticket reference (e.g. "#1284")
     * @return string Header HTML
     */
    private static function renderHeader(string $ticketReference): string
    {
        $h = 'padding:26px 48px 20px;display:flex;align-items:center;gap:12px;'
            . 'border-bottom:1px solid #F3F4F6;';
        $title = '<div><div style="font-size:14px;font-weight:700;'
            . 'letter-spacing:-0.2px;color:#111827;">' . EmailBrand::HEADER_TITLE . '</div>'
            . '<div style="font-size:11px;color:#6B7280;margin-top:1px;">'
            . '</div></div>';
        $ref = '<div style="margin-left:auto;font-family:Geist Mono,Menlo,Consolas,monospace;'
            . 'font-size:10px;color:#9CA3AF;letter-spacing:0.5px;text-transform:uppercase;">'
            . 'Ticket ' . htmlspecialchars($ticketReference, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>';
        $logo = '<img src="' . htmlspecialchars(EmailBrand::logoUrl(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '" alt="" width="32" height="32" style="display:block;" />';

        return '<div style="' . $h . '">' . $logo . $title . $ref . '</div>';
    }

    /**
     * @param \App\Notification\Email\EmailTheme $theme Theme used for the accent link color
     * @param string $ticketReference Short ticket reference
     * @return string Footer HTML
     */
    private static function renderFooter(EmailTheme $theme, string $ticketReference): string
    {
        $wrap = 'padding:24px 48px 32px;border-top:1px solid #F3F4F6;background:#FAFAF9;';
        $brandRow = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">'
            . '<img src="' . htmlspecialchars(EmailBrand::logoUrl(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '" alt="" width="18" height="18" style="display:block;opacity:0.6;" />'
            . '<span style="font-size:11px;font-weight:600;color:#6B7280;letter-spacing:0.3px;">'
            . EmailBrand::ORG_TAG_LINE . '</span></div>';

        $ref = htmlspecialchars($ticketReference, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $context = '<p style="font-size:11px;color:#6B7280;line-height:1.6;margin:0;max-width:520px;">'
            . 'Recibiste este correo porque participas en el ticket '
            . '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;color:#374151;">'
            . $ref . '</span>. Puedes responder directamente a este correo para añadir un comentario al ticket.'
            . '</p>';

        $legal = '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #F3F4F6;'
            . 'font-size:10px;color:#9CA3AF;line-height:1.5;">'
            . '© ' . date('Y') . ' ' . EmailBrand::ORG_NAME . ' · ' . EmailBrand::ORG_ADDRESS
            . ' · NIT ' . EmailBrand::ORG_NIT . '<br/>'
            . 'Este es un mensaje automático. Para soporte humano escribe a '
            . '<span style="color:#6B7280;font-weight:500;">' . EmailBrand::SUPPORT_EMAIL . '</span>'
            . '</div>';

        return '<div style="' . $wrap . '">' . $brandRow . $context . $legal . '</div>';
    }
}
