<?php
declare(strict_types=1);

namespace App\Notification\Email\Component;

/**
 * Generic card: header strip (left/right slots) + title + optional tags + optional meta grid.
 * No knowledge of Ticket — callers (e.g. TicketCard) compose it with domain data.
 */
final class Card
{
    /**
     * @param list<string> $tags Plain text tags rendered as gray pills.
     * @param list<array{label:string, valueHtml:string}> $metaColumns 0, 1 or 2 columns.
     */
    public static function render(
        string $headerLeftHtml,
        string $headerRightHtml,
        string $title,
        array $tags = [],
        array $metaColumns = [],
    ): string {
        $headerStyle = 'display:flex;align-items:center;gap:10px;padding:10px 16px;'
            . 'background:#FAFAF9;border-bottom:1px solid #F3F4F6;';
        $titleStyle = 'font-size:16px;font-weight:600;color:#111827;'
            . 'line-height:1.3;letter-spacing:-0.1px;';

        $tagsHtml = '';
        if (!empty($tags)) {
            $tagsHtml = '<div style="margin-top:10px;">';
            foreach ($tags as $tag) {
                $tagsHtml .= '<span style="display:inline-block;margin-right:6px;'
                    . 'padding:3px 9px;border-radius:6px;background:#F3F4F6;'
                    . 'color:#374151;font-size:11px;font-weight:500;">'
                    . htmlspecialchars($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</span>';
            }
            $tagsHtml .= '</div>';
        }

        $metaHtml = '';
        if (!empty($metaColumns)) {
            $cellLabel = 'font-size:10px;font-weight:600;color:#9CA3AF;'
                . 'letter-spacing:0.6px;text-transform:uppercase;margin-bottom:6px;';
            $metaHtml = '<table role="presentation" cellspacing="0" cellpadding="0" '
                . 'style="width:100%;border-top:1px solid #F3F4F6;border-collapse:collapse;">'
                . '<tr>';
            $count = count($metaColumns);
            foreach ($metaColumns as $i => $col) {
                $border = $i < $count - 1 ? 'border-right:1px solid #F3F4F6;' : '';
                $metaHtml .= '<td style="padding:12px 16px;vertical-align:top;width:50%;' . $border . '">'
                    . '<div style="' . $cellLabel . '">'
                    . htmlspecialchars($col['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</div>'
                    . $col['valueHtml']
                    . '</td>';
            }
            $metaHtml .= '</tr></table>';
        }

        return '<div style="border:1px solid #E5E7EB;border-radius:10px;'
            . 'overflow:hidden;margin-bottom:20px;">'
            . '<div style="' . $headerStyle . '">'
            . '<div style="flex:1;">' . $headerLeftHtml . '</div>'
            . '<div>' . $headerRightHtml . '</div>'
            . '</div>'
            . '<div style="padding:16px 16px 14px;">'
            . '<div style="' . $titleStyle . '">'
            . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</div>'
            . $tagsHtml
            . '</div>'
            . $metaHtml
            . '</div>';
    }
}
