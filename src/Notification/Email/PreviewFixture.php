<?php
declare(strict_types=1);

namespace App\Notification\Email;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use Cake\I18n\DateTime;

/**
 * Builds an in-memory TemplateContext for admin previews.
 *
 * No DB access; entities are constructed via mass-assignment guarded off.
 * Variant flags let admin previews show each template with relevant context.
 */
final class PreviewFixture
{
    public const VARIANT_CREATED = 'created';
    public const VARIANT_STATUS = 'status';
    public const VARIANT_COMMENT = 'comment';
    public const VARIANT_UPDATED = 'updated';

    public static function context(string $variant): TemplateContext
    {
        $requester = new User();
        $requester->set([
            'id' => 10,
            'first_name' => 'Alexander',
            'last_name' => 'Caicedo',
            'email' => 'alex@operadoracafetera.com',
            'role' => 'Auxiliar de sistemas',
        ], ['guard' => false]);

        $assignee = new User();
        $assignee->set([
            'id' => 20,
            'first_name' => 'Maira',
            'last_name' => 'Pérez',
            'email' => 'maira@operadoracafetera.com',
            'role' => 'Líder de soporte',
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'id' => 1,
            'ticket_number' => 'TKT-1284',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => $assignee,
            'tags' => ['Mantenimiento', 'Sucursal Norte'],
            'created' => new DateTime('2026-05-14 13:42:00'),
        ], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set([
            'id' => 99,
            'body' => '<p>Hola Alexander, ya estamos revisando. El equipo lanza un código E07 '
                . 'que típicamente está asociado al motor. Mañana a las 8:00 a.m. pasa Daniel '
                . 'del taller a hacer diagnóstico in situ. ¿Puedes confirmar disponibilidad '
                . 'en la sucursal?</p>',
            'user' => $assignee,
            'created' => new DateTime('2026-05-14 13:50:00'),
        ], ['guard' => false]);

        $ctx = static fn (array $extra): TemplateContext => new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://example.com/tickets/view/1',
            recipientName: 'Alexander',
            ...$extra,
        );

        return match ($variant) {
            self::VARIANT_STATUS => $ctx([
                'oldStatus' => 'abierto',
                'newStatus' => 'pendiente',
                'actor' => $assignee,
            ]),
            self::VARIANT_COMMENT => $ctx([
                'comment' => $comment,
                'actor' => $assignee,
            ]),
            self::VARIANT_UPDATED => $ctx([
                'comment' => $comment,
                'oldStatus' => 'abierto',
                'newStatus' => 'pendiente',
                'actor' => $assignee,
            ]),
            default => $ctx([]),
        };
    }

    /**
     * Map a template key to its preview variant.
     */
    public static function variantForKey(string $key): string
    {
        return match ($key) {
            'ticket_status_changed' => self::VARIANT_STATUS,
            'ticket_comment_added' => self::VARIANT_COMMENT,
            'ticket_updated' => self::VARIANT_UPDATED,
            default => self::VARIANT_CREATED,
        };
    }
}
