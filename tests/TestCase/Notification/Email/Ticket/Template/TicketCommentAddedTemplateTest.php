<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketCommentAddedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketCommentAddedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKey(): void
    {
        self::assertSame('ticket_comment_added', (new TicketCommentAddedTemplate())->key());
    }

    public function testSubjectIsReplyOfTicketSubjectAndBodyQuotesComment(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $agent = new User();
        $agent->set(['first_name' => 'Maira', 'last_name' => 'Pérez'], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set(['body' => '<p>Ya estamos revisando.</p>'], ['guard' => false]);

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
            actor: $agent,
        );

        $email = (new TicketCommentAddedTemplate())->render($ctx);

        // Gmail threading: subject is "Re: " + ticket.subject verbatim.
        self::assertSame('Re: Cafetera #14 no enciende', $email->subject);
        self::assertStringContainsString('Hola Alex,', $email->bodyHtml);
        self::assertStringContainsString('Maira Pérez respondió', $email->bodyHtml);
        self::assertStringContainsString('#TKT-1', $email->bodyHtml);
        self::assertStringContainsString('Cafetera #14 no enciende', $email->bodyHtml);
        // Comment body is wrapped raw inside the blockquote.
        self::assertStringContainsString('<p>Ya estamos revisando.</p>', $email->bodyHtml);
        self::assertStringContainsString('border-left:3px solid', $email->bodyHtml);
        self::assertStringContainsString('Estado: Pendiente', $email->bodyHtml);
        self::assertStringContainsString('Asignado: Sin asignar', $email->bodyHtml);
        self::assertStringContainsString('Responde a este correo', $email->bodyHtml);
    }

    public function testSubjectDoesNotDoubleReplyPrefix(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set(['body' => '<p>x</p>'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-9',
            'subject' => 'Re: Cafetera #14 no enciende',
            'status' => 'abierto',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'u',
            recipientName: 'Alex',
            comment: $comment,
        );

        $email = (new TicketCommentAddedTemplate())->render($ctx);
        self::assertSame('Re: Cafetera #14 no enciende', $email->subject);
    }

    public function testFallsBackToMesaDeAyudaWhenNoActor(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set(['body' => '<p>x</p>'], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-9',
            'subject' => 'X',
            'status' => 'abierto',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'u',
            recipientName: 'Alex',
            comment: $comment,
        );

        $email = (new TicketCommentAddedTemplate())->render($ctx);
        self::assertStringContainsString('Mesa de Ayuda respondió', $email->bodyHtml);
    }
}
