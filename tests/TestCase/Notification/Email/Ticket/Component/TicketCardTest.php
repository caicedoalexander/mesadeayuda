<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Email\Ticket\Component\TicketCard;
use Cake\I18n\DateTime;
use PHPUnit\Framework\TestCase;

final class TicketCardTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function ticket(array $overrides = []): Ticket
    {
        $requester = new User();
        $requester->set([
            'id' => 10,
            'first_name' => 'Alexander',
            'last_name' => 'Caicedo',
            'email' => 'alex@example.com',
        ], ['guard' => false]);

        $assignee = new User();
        $assignee->set([
            'id' => 20,
            'first_name' => 'Maira',
            'last_name' => 'Pérez',
            'email' => 'maira@example.com',
        ], ['guard' => false]);

        $t = new Ticket();
        $t->set(array_merge([
            'id' => 1284,
            'subject' => 'Cafetera #14 no enciende',
            'status' => 'pendiente',
            'priority' => 'alta',
            'requester' => $requester,
            'assignee' => $assignee,
            'tags' => ['Mantenimiento', 'Sucursal Norte'],
            'created' => new DateTime('2026-05-14 13:42:00'),
        ], $overrides), ['guard' => false]);

        return $t;
    }

    public function testRendersTicketNumberStatusSubjectAndPeople(): void
    {
        $html = TicketCard::render($this->ticket());

        self::assertStringContainsString('1284', $html);
        self::assertStringContainsString('Pendiente', $html);
        self::assertStringContainsString('Alta', $html);
        self::assertStringContainsString('Cafetera #14 no enciende', $html);
        self::assertStringContainsString('Mantenimiento', $html);
        self::assertStringContainsString('Alexander Caicedo', $html);
        self::assertStringContainsString('Maira Pérez', $html);
    }

    public function testWithoutAssigneeRendersUnassignedBadge(): void
    {
        $html = TicketCard::render($this->ticket(['assignee' => null]));
        self::assertStringContainsString('Sin asignar', $html);
        self::assertStringNotContainsString('Maira Pérez', $html);
    }

    public function testWithoutTagsOmitsTagsBlock(): void
    {
        $html = TicketCard::render($this->ticket(['tags' => []]));
        self::assertStringNotContainsString('Mantenimiento', $html);
    }
}
