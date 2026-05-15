<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Template;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\Ticket\Template\TicketCreatedTemplate;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class TicketCreatedTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testKeyIsTicketCreated(): void
    {
        self::assertSame('ticket_created', (new TicketCreatedTemplate())->key());
    }

    public function testRenderProducesSubjectAndBodyWithExpectedTokens(): void
    {
        $requester = new User();
        $requester->set([
            'first_name' => 'Alexander',
            'last_name' => 'Caicedo',
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'id' => 1,
            'ticket_number' => 'TKT-1284',
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'nuevo',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => null,
            'tags' => ['Mantenimiento'],
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/tickets/view/1',
            recipientName: 'Alexander',
        );

        $email = (new TicketCreatedTemplate())->render($ctx);

        self::assertSame('Tu ticket #TKT-1284 fue creado', $email->subject);
        self::assertStringContainsString('Tu ticket fue creado', $email->bodyHtml);
        self::assertStringContainsString('Hola <strong', $email->bodyHtml);
        self::assertStringContainsString('Alexander', $email->bodyHtml);
        self::assertStringContainsString('Cafetera #14 no enciende', $email->bodyHtml);
        self::assertStringContainsString('Próximos pasos', $email->bodyHtml);
        self::assertStringContainsString('30 minutos', $email->bodyHtml);
        self::assertStringContainsString('Ver mi ticket', $email->bodyHtml);
        self::assertStringContainsString('https://mesa.example.com/tickets/view/1', $email->bodyHtml);
        self::assertStringContainsString('#CD6A15', $email->bodyHtml);
    }
}
