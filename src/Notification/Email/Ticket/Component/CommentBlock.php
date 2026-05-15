<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

use App\Notification\Email\Component\Avatar;

/**
 * Block with avatar+author header (tinted with accentSoft) and the comment
 * body. SECURITY: $bodyHtml is inserted raw — caller must sanitize via
 * HtmlSanitizerTrait before construction.
 */
final class CommentBlock
{
    public static function render(
        string $authorName,
        string $authorRole,
        string $authorColor,
        string $bodyHtml,
        string $accent,
        string $accentSoft,
        string $timestamp,
    ): string {
        $initials = Avatar::initialsFromName($authorName);
        $avatar = Avatar::render($initials, $authorColor, 32);

        $headerStyle = 'padding:12px 16px;background:' . $accentSoft . ';'
            . 'border-bottom:1px solid ' . $accent . '33;'
            . 'display:flex;align-items:center;gap:10px;';

        $name = htmlspecialchars($authorName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $role = htmlspecialchars($authorRole, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ts = htmlspecialchars($timestamp, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $meta = '<div style="flex:1;min-width:0;">'
            . '<div style="font-size:13px;font-weight:600;color:#111827;">' . $name . '</div>'
            . '<div style="font-size:11px;color:#6B7280;">' . $role . ' · respondió a tu ticket</div>'
            . '</div>'
            . '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;'
            . 'font-size:10px;color:#9CA3AF;">' . $ts . '</span>';

        $body = '<div style="padding:16px 18px;font-size:14px;color:#374151;'
            . 'line-height:1.65;background:#fff;">' . $bodyHtml . '</div>';

        return '<div style="border:1px solid #E5E7EB;border-radius:10px;'
            . 'overflow:hidden;margin-bottom:20px;">'
            . '<div style="' . $headerStyle . '">' . $avatar . $meta . '</div>'
            . $body
            . '</div>';
    }
}
