<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    /**
     * Mirror of the (private) Ticket::TRANSITIONS state machine.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'nuevo' => ['abierto', 'pendiente', 'resuelto'],
        'abierto' => ['pendiente', 'resuelto', 'nuevo'],
        'pendiente' => ['abierto', 'resuelto'],
        'resuelto' => ['abierto'],
    ];

    private function makeTicket(array $props = []): Ticket
    {
        $defaults = [
            'id' => 1,
            'status' => 'nuevo',
            'priority' => 'media',
            'requester_id' => 10,
            'assignee_id' => null,
            'gmail_message_id' => null,
        ];

        $ticket = new Ticket();
        $ticket->set(array_merge($defaults, $props), ['guard' => false]);
        $ticket->setNew(false);
        $ticket->clean();

        return $ticket;
    }

    private function makeUser(array $props = []): User
    {
        $defaults = [
            'id' => 99,
            'role' => 'agent',
            'is_active' => true,
        ];

        return new User(array_merge($defaults, $props), ['markNew' => false, 'markClean' => true]);
    }

    public function testIsStatusNew(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'nuevo'])->isStatusNew());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isStatusNew());
    }

    public function testIsOpen(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'abierto'])->isOpen());
        self::assertTrue($this->makeTicket(['status' => 'nuevo'])->isOpen());
        self::assertTrue($this->makeTicket(['status' => 'pendiente'])->isOpen());
        self::assertFalse($this->makeTicket(['status' => 'resuelto'])->isOpen());
    }

    public function testIsPending(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'pendiente'])->isPending());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isPending());
    }

    public function testIsResolved(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'resuelto'])->isResolved());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isResolved());
    }

    public function testIsLocked(): void
    {
        self::assertTrue($this->makeTicket(['status' => 'resuelto'])->isLocked());
        self::assertFalse($this->makeTicket(['status' => 'abierto'])->isLocked());
        self::assertFalse($this->makeTicket(['status' => 'nuevo'])->isLocked());
    }

    public function testHasAssignee(): void
    {
        self::assertFalse($this->makeTicket(['assignee_id' => null])->hasAssignee());
        self::assertTrue($this->makeTicket(['assignee_id' => 5])->hasAssignee());
    }

    public function testBelongsTo(): void
    {
        $ticket = $this->makeTicket(['requester_id' => 10]);
        self::assertTrue($ticket->belongsTo(10));
        self::assertFalse($ticket->belongsTo(11));
    }

    public function testIsAssignedTo(): void
    {
        $ticket = $this->makeTicket(['assignee_id' => 5]);
        self::assertTrue($ticket->isAssignedTo(5));
        self::assertFalse($ticket->isAssignedTo(6));

        $unassigned = $this->makeTicket(['assignee_id' => null]);
        self::assertFalse($unassigned->isAssignedTo(5));
    }

    public function testWasCreatedFromEmail(): void
    {
        self::assertTrue($this->makeTicket(['gmail_message_id' => 'abc123'])->wasCreatedFromEmail());
        self::assertFalse($this->makeTicket(['gmail_message_id' => null])->wasCreatedFromEmail());
    }

    public function testCanTransitionAllowed(): void
    {
        foreach (self::ALLOWED_TRANSITIONS as $from => $allowedTos) {
            $ticket = $this->makeTicket(['status' => $from]);
            foreach ($allowedTos as $to) {
                self::assertTrue(
                    $ticket->canTransitionTo($to),
                    "Expected {$from} -> {$to} to be allowed",
                );
            }
        }
    }

    public function testCanTransitionForbidden(): void
    {
        $allStatuses = ['nuevo', 'abierto', 'pendiente', 'resuelto'];
        foreach (self::ALLOWED_TRANSITIONS as $from => $allowedTos) {
            $forbidden = array_diff($allStatuses, $allowedTos, [$from]);
            $ticket = $this->makeTicket(['status' => $from]);
            foreach ($forbidden as $to) {
                self::assertFalse(
                    $ticket->canTransitionTo($to),
                    "Expected {$from} -> {$to} to be forbidden",
                );
            }
        }
    }

    public function testCanBeAssignedToActiveStaff(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'agent', 'is_active' => true]);
        self::assertTrue($ticket->canBeAssignedTo($user));
    }

    public function testCannotBeAssignedToInactiveUser(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'agent', 'is_active' => false]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testCannotBeAssignedToNonStaff(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'requester', 'is_active' => true]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testCannotAssignWhenLocked(): void
    {
        $ticket = $this->makeTicket(['status' => 'resuelto']);
        $user = $this->makeUser(['role' => 'agent', 'is_active' => true]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }
}
