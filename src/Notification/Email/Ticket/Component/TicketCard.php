<?php
declare(strict_types=1);

namespace App\Notification\Email\Ticket\Component;

use App\Model\Entity\Ticket;
use App\Notification\Email\Component\Avatar;
use App\Notification\Email\Component\Card;
use App\Notification\Email\Component\Pill;
use DateTimeInterface;

/**
 * Card built from a Ticket entity. Maps domain fields into the generic
 * Card component's props.
 */
final class TicketCard
{
    /**
     * Hash a string to one of a small set of pleasant colors for avatar
     * background — keeps the rendering deterministic without needing a
     * dedicated user-color column.
     *
     * @var list<string>
     */
    private const AVATAR_PALETTE = [
        '#00A85E', '#CD6A15', '#0066cc', '#7c3aed', '#0891b2', '#dc3545',
    ];

    public static function render(Ticket $ticket): string
    {
        $number = (string)($ticket->ticket_number ?? '');
        $status = (string)($ticket->status ?? '');
        $priority = (string)($ticket->priority ?? 'media');
        $subject = (string)($ticket->subject ?? '');

        $tags = $ticket->get('tags');
        if (!is_array($tags)) {
            $tags = [];
        }

        $headerLeft = '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;'
            . 'font-size:11px;font-weight:600;color:#6B7280;margin-right:10px;">#'
            . htmlspecialchars($number, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>'
            . Pill::forStatus($status)
            . '<span style="margin-left:10px;">' . PriorityArrow::render($priority) . '</span>';

        $headerRight = '';
        $created = $ticket->get('created');
        if ($created instanceof DateTimeInterface) {
            $headerRight = '<span style="font-family:Geist Mono,Menlo,Consolas,monospace;'
                . 'font-size:10px;color:#9CA3AF;">'
                . htmlspecialchars($created->format('d M · H:i'), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</span>';
        }

        $metaColumns = [
            ['label' => 'Solicitante', 'valueHtml' => self::renderPerson($ticket->get('requester'), 'Sin solicitante')],
            ['label' => 'Asignado a', 'valueHtml' => self::renderPerson($ticket->get('assignee'), null)],
        ];

        return Card::render(
            headerLeftHtml: $headerLeft,
            headerRightHtml: $headerRight,
            title: $subject,
            tags: array_values(array_filter(array_map('strval', $tags), static fn ($t) => $t !== '')),
            metaColumns: $metaColumns,
        );
    }

    private static function renderPerson(mixed $person, ?string $fallbackLabel): string
    {
        if ($person === null) {
            return $fallbackLabel === null
                ? '<span style="display:inline-block;padding:4px 9px;border-radius:6px;'
                    . 'background:#FCEFE0;color:#6b3306;font-size:11px;font-weight:600;">Sin asignar</span>'
                : '<span style="font-size:13px;color:#6B7280;">'
                    . htmlspecialchars($fallbackLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    . '</span>';
        }

        $name = (string)($person->name ?? '');
        $role = (string)($person->role ?? '');
        $color = self::AVATAR_PALETTE[crc32($name) % count(self::AVATAR_PALETTE)];
        $initials = Avatar::initialsFromName($name);

        $textBlock = '<div style="display:inline-block;vertical-align:middle;margin-left:8px;">'
            . '<div style="font-size:13px;font-weight:500;color:#111827;">'
            . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
            . '<div style="font-size:11px;color:#6B7280;">'
            . htmlspecialchars($role, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
            . '</div>';

        return '<div style="display:inline-flex;align-items:center;">'
            . Avatar::render($initials, $color, 26) . $textBlock . '</div>';
    }
}
