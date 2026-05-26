<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketCommentAddedTemplate;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
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

    public function testSubjectIsReplyOfTicketSubjectAndBodyMentionsAgent(): void
    {
        $requester = new User();
        $requester->set(['first_name' => 'Alex', 'last_name' => ''], ['guard' => false]);

        $agent = new User();
        $agent->set(['first_name' => 'Maira', 'last_name' => 'Pérez', 'role' => 'Líder'], ['guard' => false]);

        $comment = new TicketComment();
        $comment->set([
            'body' => '<p>Ya estamos revisando.</p>',
            'user' => $agent,
            'created' => new DateTime('2026-05-14 13:50:00'),
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'ticket_number' => 'TKT-1',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'media',
            'requester' => $requester,
            'tags' => [],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/t/1',
            recipientName: 'Alex',
            comment: $comment,
            actor: $agent,
        );

        $email = (new TicketCommentAddedTemplate())->render($ctx);

        // Gmail threading depends on Subject matching the original. We emit
        // 'Re: <ticket.subject>' verbatim — no agent name, no ticket number.
        self::assertSame('Re: Cafetera #14 no enciende', $email->subject);
        self::assertStringContainsString('Tienes una nueva respuesta', $email->bodyHtml);
        self::assertStringContainsString('Maira Pérez', $email->bodyHtml);
        self::assertStringContainsString('<p>Ya estamos revisando.</p>', $email->bodyHtml);
        self::assertStringContainsString('Responde desde este mismo correo', $email->bodyHtml);
    }

    /**
     * Idempotency on the Re: prefix — if ticket.subject already starts with
     * "Re: " (because it came from a customer reply to a forwarded thread),
     * SubjectFormatter must not double-stack it.
     */
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
            'tags' => [],
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
}
