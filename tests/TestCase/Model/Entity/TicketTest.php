<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Service\Exception\InvalidStatusTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
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
            'role' => 'asesor_tic',
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

    /**
     * Every legal transition of the domain state machine, one row per pair.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allowedTransitionProvider(): array
    {
        return [
            'nuevo -> abierto' => ['nuevo', 'abierto'],
            'nuevo -> pendiente' => ['nuevo', 'pendiente'],
            'nuevo -> resuelto' => ['nuevo', 'resuelto'],
            'abierto -> pendiente' => ['abierto', 'pendiente'],
            'abierto -> resuelto' => ['abierto', 'resuelto'],
            'abierto -> nuevo' => ['abierto', 'nuevo'],
            'pendiente -> abierto' => ['pendiente', 'abierto'],
            'pendiente -> resuelto' => ['pendiente', 'resuelto'],
            'resuelto -> abierto' => ['resuelto', 'abierto'],
        ];
    }

    #[DataProvider('allowedTransitionProvider')]
    public function testCanTransitionToAllowedTarget(string $from, string $to): void
    {
        self::assertTrue($this->makeTicket(['status' => $from])->canTransitionTo($to));
    }

    /**
     * Forbidden transitions, covering all three rejection branches of
     * canTransitionTo(): a cross-status pair outside the allowed set, a
     * same-status no-op (Ticket.php:218), and an unknown target (Ticket.php:215).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenTransitionProvider(): array
    {
        return [
            // Valid status, but not reachable from the source.
            'pendiente -> nuevo' => ['pendiente', 'nuevo'],
            'resuelto -> nuevo' => ['resuelto', 'nuevo'],
            'resuelto -> pendiente' => ['resuelto', 'pendiente'],
            // Same-status is never a transition.
            'nuevo -> nuevo' => ['nuevo', 'nuevo'],
            'abierto -> abierto' => ['abierto', 'abierto'],
            'pendiente -> pendiente' => ['pendiente', 'pendiente'],
            'resuelto -> resuelto' => ['resuelto', 'resuelto'],
            // Unknown target status.
            'abierto -> unknown' => ['abierto', 'basura'],
            'nuevo -> unknown' => ['nuevo', 'cerrado'],
        ];
    }

    #[DataProvider('forbiddenTransitionProvider')]
    public function testCanTransitionToForbiddenTarget(string $from, string $to): void
    {
        self::assertFalse($this->makeTicket(['status' => $from])->canTransitionTo($to));
    }

    public function testTransitionToAppliesLegalTransition(): void
    {
        $ticket = $this->makeTicket(['status' => 'nuevo']);
        $ticket->transitionTo('abierto');
        self::assertSame('abierto', $ticket->status);
    }

    public function testTransitionToSameStatusIsNoOp(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $ticket->transitionTo('abierto');
        self::assertSame('abierto', $ticket->status);
    }

    public function testTransitionToIllegalTargetThrows(): void
    {
        $ticket = $this->makeTicket(['status' => 'resuelto']);
        $this->expectException(InvalidStatusTransitionException::class);
        $ticket->transitionTo('pendiente');
    }

    public function testCanBeAssignedToActiveStaff(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'asesor_tic', 'is_active' => true]);
        self::assertTrue($ticket->canBeAssignedTo($user));
    }

    public function testCannotBeAssignedToInactiveUser(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'asesor_tic', 'is_active' => false]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testCannotBeAssignedToNonStaff(): void
    {
        $ticket = $this->makeTicket(['status' => 'abierto']);
        $user = $this->makeUser(['role' => 'external', 'is_active' => true]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testCannotAssignWhenLocked(): void
    {
        $ticket = $this->makeTicket(['status' => 'resuelto']);
        $user = $this->makeUser(['role' => 'asesor_tic', 'is_active' => true]);
        self::assertFalse($ticket->canBeAssignedTo($user));
    }

    public function testFromEmailIngestSetsInitialStatusAndPriority(): void
    {
        $ticket = Ticket::fromEmailIngest(
            ticketNumber: 'T-0001',
            requesterId: 42,
            subject: 'Mi pedido',
            sanitizedDescription: '<p>cuerpo limpio</p>',
            channel: 'email',
            sourceEmail: 'cliente@example.com',
        );

        self::assertSame('nuevo', $ticket->status);
        self::assertSame('media', $ticket->priority);
        self::assertSame('T-0001', $ticket->ticket_number);
        self::assertSame(42, $ticket->requester_id);
        self::assertSame('Mi pedido', $ticket->subject);
        self::assertSame('<p>cuerpo limpio</p>', $ticket->description);
        self::assertSame('email', $ticket->channel);
        self::assertSame('cliente@example.com', $ticket->source_email);
    }

    public function testFromEmailIngestFallsBackToSinAsuntoWhenSubjectEmpty(): void
    {
        $ticket = Ticket::fromEmailIngest(
            ticketNumber: 'T-0002',
            requesterId: 1,
            subject: '',
            sanitizedDescription: '',
            channel: 'email',
            sourceEmail: 'x@y.z',
        );

        self::assertSame('(Sin asunto)', $ticket->subject);
    }

    public function testFromEmailIngestPassesThroughGmailIdsAndRecipients(): void
    {
        $emailTo = [['email' => 'a@b.com', 'name' => 'A B']];
        $emailCc = [['email' => 'c@d.com', 'name' => 'C D']];

        $ticket = Ticket::fromEmailIngest(
            ticketNumber: 'T-0003',
            requesterId: 1,
            subject: 'x',
            sanitizedDescription: '',
            channel: 'email',
            sourceEmail: 'x@y.z',
            gmailMessageId: 'gm-msg-1',
            gmailThreadId: 'gm-thr-1',
            emailTo: $emailTo,
            emailCc: $emailCc,
        );

        self::assertSame('gm-msg-1', $ticket->gmail_message_id);
        self::assertSame('gm-thr-1', $ticket->gmail_thread_id);
        // email_to/email_cc are JSON-encoded by EmailRecipientsTrait setters;
        // the canonical read-side is the *_array virtual getter.
        self::assertSame($emailTo, $ticket->email_to_array);
        self::assertSame($emailCc, $ticket->email_cc_array);
    }
}
