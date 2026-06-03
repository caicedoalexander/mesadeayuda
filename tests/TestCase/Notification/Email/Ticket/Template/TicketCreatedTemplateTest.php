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
    private mixed $previousFullBaseUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFullBaseUrl = Configure::read('App.fullBaseUrl');
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    protected function tearDown(): void
    {
        if ($this->previousFullBaseUrl === null) {
            Configure::delete('App.fullBaseUrl');
        } else {
            Configure::write('App.fullBaseUrl', $this->previousFullBaseUrl);
        }
        parent::tearDown();
    }

    public function testKeyIsTicketCreated(): void
    {
        self::assertSame('ticket_created', (new TicketCreatedTemplate())->key());
    }

    public function testRenderProducesPlainTextStyleBody(): void
    {
        $requester = new User();
        $requester->set([
            'first_name' => 'Alexander',
            'last_name' => 'Caicedo',
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->set([
            'id' => 1284,
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'nuevo',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => null,
        ], ['guard' => false]);

        $ctx = new TemplateContext(
            ticket: $ticket,
            ticketUrl: 'https://mesa.example.com/tickets/view/1',
            recipientName: 'Alexander',
        );

        $email = (new TicketCreatedTemplate())->render($ctx);

        // TicketCreated is the root of the thread; subject is NOT "Re:"-prefixed.
        self::assertSame('Tu ticket #1284 fue creado', $email->subject);
        self::assertStringContainsString('Hola Alexander,', $email->bodyHtml);
        self::assertStringContainsString('Recibimos tu solicitud', $email->bodyHtml);
        self::assertStringContainsString('#1284', $email->bodyHtml);
        self::assertStringContainsString('Cafetera #14 no enciende', $email->bodyHtml);
        self::assertStringContainsString('30 minutos', $email->bodyHtml);
        self::assertStringContainsString('Estado: Nuevo', $email->bodyHtml);
        self::assertStringContainsString('Prioridad: Alta', $email->bodyHtml);
        self::assertStringContainsString('Responde a este correo', $email->bodyHtml);
        // Visual chrome removed: no link button, no "Próximos pasos" section.
        self::assertStringNotContainsString('Ver mi ticket', $email->bodyHtml);
        self::assertStringNotContainsString('Próximos pasos', $email->bodyHtml);
    }
}
