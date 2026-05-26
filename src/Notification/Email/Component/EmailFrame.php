<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

use App\Notification\Email\EmailBrand;

/**
 * Minimal email wrap: plain-text body slot + a footer with a small logo
 * and a two-line brand block. No colored bars, no headers, no shadows,
 * no card chrome — the goal is a transactional notification that renders
 * identically across Gmail, Outlook, Apple Mail, and mobile clients.
 */
final class EmailFrame
{
    /**
     * Render the email wrap around the given inner HTML.
     *
     * @param string $innerHtml Body HTML to embed
     * @return string HTML markup
     */
    public static function render(string $innerHtml): string
    {
        $wrap = 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,'
            . 'Helvetica,Arial,sans-serif;font-size:14px;line-height:1.55;'
            . 'color:#111827;max-width:560px;margin:0 auto;padding:24px;';

        return '<div style="' . $wrap . '">'
            . $innerHtml
            . self::renderFooter()
            . '</div>';
    }

    /**
     * Footer: small logo + two-line brand block, separated from body by a
     * single hairline rule.
     */
    private static function renderFooter(): string
    {
        $wrap = 'margin-top:28px;padding-top:16px;border-top:1px solid #E5E7EB;';
        $row = 'display:table;border-collapse:collapse;';
        $logoCell = 'display:table-cell;vertical-align:middle;padding-right:12px;';
        $textCell = 'display:table-cell;vertical-align:middle;'
            . 'font-size:12px;line-height:1.4;color:#6B7280;';

        $logo = '<img src="'
            . htmlspecialchars(EmailBrand::logoUrl(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '" alt="" width="24" height="24" style="display:block;" />';

        $text = '<div style="font-weight:600;color:#374151;">'
            . htmlspecialchars(EmailBrand::TEAM_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>'
            . '<div>'
            . htmlspecialchars(EmailBrand::ORG_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>';

        return '<div style="' . $wrap . '">'
            . '<div style="' . $row . '">'
            . '<div style="' . $logoCell . '">' . $logo . '</div>'
            . '<div style="' . $textCell . '">' . $text . '</div>'
            . '</div></div>';
    }
}
