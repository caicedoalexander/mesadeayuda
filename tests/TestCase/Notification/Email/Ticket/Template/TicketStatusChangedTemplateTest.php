<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketStatusChangedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketStatusChangedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKey(): void
    {
        self::assertSame('ticket_status_changed', (new TicketStatusChangedTemplate())->key());
    }

    public function testSubjectIsReplyOfTicketSubjectAndBodyShowsTransition(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
        ], ['guard' => false]);

        $actor = new User();
        $actor->set(['first_name' => 'Maira', 'last_name' => 'Pérez'], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/t/1',
            recipientName: 'Alex',
            oldStatus: 'abierto',
            newStatus: 'pendiente',
            actor: $actor,
        );

        $email = (new TicketStatusChangedTemplate())->render($ctx);

        self::assertSame('Re: Cafetera #14 no enciende', $email->subject);
        self::assertStringContainsString('Hola Alex,', $email->bodyHtml);
        self::assertStringContainsString('El estado de tu ticket #TKT-1', $email->bodyHtml);
        self::assertStringContainsString('Cafetera #14 no enciende', $email->bodyHtml);
        self::assertStringContainsString('Abierto → Pendiente', $email->bodyHtml);
        self::assertStringContainsString('Aplicado por Maira Pérez', $email->bodyHtml);
        self::assertStringContainsString('Asignado: Sin asignar', $email->bodyHtml);
        self::assertStringContainsString('Responde a este correo', $email->bodyHtml);
    }

    public function testWithoutActorOmitsActorLine(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'S',
            'status' => 'resuelto',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'u',
            recipientName: 'Alex',
            oldStatus: 'pendiente',
            newStatus: 'resuelto',
        );

        $email = (new TicketStatusChangedTemplate())->render($ctx);
        self::assertStringNotContainsString('Aplicado por', $email->bodyHtml);
    }
}
