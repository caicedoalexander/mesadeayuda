<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketUpdatedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketUpdatedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKey(): void
    {
        self::assertSame('ticket_updated', (new TicketUpdatedTemplate())->key());
    }

    public function testRenderShowsCommentQuoteAndTransition(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $agent = new User();
        $agent->set(['first_name' => 'Maira', 'last_name' => 'Pérez'], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set(['body' => '<p>Comentario.</p>'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/t/1',
            recipientName: 'Alex',
            comment: $comment,
            oldStatus: 'abierto',
            newStatus: 'pendiente',
            actor: $agent,
        );

        $email = (new TicketUpdatedTemplate())->render($ctx);

        self::assertSame('Re: Cafetera #14 no enciende', $email->subject);
        self::assertStringContainsString('Hola Alex,', $email->bodyHtml);
        self::assertStringContainsString('Maira Pérez actualizó', $email->bodyHtml);
        self::assertStringContainsString('#TKT-1', $email->bodyHtml);
        self::assertStringContainsString('<p>Comentario.</p>', $email->bodyHtml);
        self::assertStringContainsString('border-left:3px solid', $email->bodyHtml);
        self::assertStringContainsString('Abierto → Pendiente', $email->bodyHtml);
        self::assertStringContainsString('Asignado: Sin asignar', $email->bodyHtml);
        self::assertStringContainsString('Responde a este correo', $email->bodyHtml);
    }
}
