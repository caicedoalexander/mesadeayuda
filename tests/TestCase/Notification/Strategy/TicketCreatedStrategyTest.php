<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketCreatedStrategy;
use Cake\Core\Configure;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

class TicketCreatedStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testSupportsTicketCreatedOnly(): void
    {
        $strategy = new TicketCreatedStrategy();
        $created = new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual');
        $statusChanged = new TicketStatusChanged(1, 'abierto', 'resuelto', 2);

        $this->assertTrue($strategy->supports($created));
        $this->assertFalse($strategy->supports($statusChanged));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketCannotBeLoaded(): void
    {
        $strategy = new TicketCreatedStrategy();
        $event = new TicketCreated(ticketId: 999999, requesterId: 0, source: 'manual');

        $messages = iterator_to_array($strategy->buildMessages($event), false);

        $this->assertSame([], $messages);
    }

    /**
     * MED-1: TicketCreated is the root of the email thread — no In-Reply-To
     * or References anchors should be emitted. The transport gets only the
     * ticketId so it can persist the outbound Message-ID onto tickets
     * .rfc_message_id (when that column is empty).
     */
    public function testEmailMessageHasNoThreadingHeadersOnCreation(): void
    {
        $strategy = $this->buildStrategyWithTicket($this->makeTicket());

        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual')),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertNull($email->inReplyTo, 'TicketCreated emails must not anchor In-Reply-To');
        $this->assertNull($email->referencesHeader, 'TicketCreated emails must not carry References');
    }

    /**
     * MED-1: the ticketId anchor lets EmailService write the outbound
     * Message-ID onto tickets.rfc_message_id when that column is empty —
     * the only place we can anchor RFC threading when no comment exists yet.
     */
    public function testEmailMessageCarriesTicketIdForRfcAnchoring(): void
    {
        $strategy = $this->buildStrategyWithTicket($this->makeTicket());

        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual')),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame(1, $email->ticketId);
    }

    /**
     * Creation has no originating comment, so commentId must be null —
     * EmailService relies on that signal to choose ticket-level anchoring
     * over comment-level anchoring.
     */
    public function testEmailMessageDoesNotCarryCommentId(): void
    {
        $strategy = $this->buildStrategyWithTicket($this->makeTicket());

        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual')),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertNull($email->commentId);
    }

    // -------------------- helpers --------------------

    private function makeTicket(): Ticket
    {
        $requester = new User();
        $requester->patch([
            'first_name' => 'Alex',
            'last_name' => 'Test',
            'email' => 'requester@example.com',
        ], ['guard' => false]);

        $ticket = new Ticket();
        $ticket->patch([
            'id' => 1,
            'subject' => 'Subj',
            'status' => 'nuevo',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
            'email_to' => null,
            'email_cc' => null,
        ], ['guard' => false]);
        $ticket->setNew(false);

        return $ticket;
    }

    private function buildStrategyWithTicket(Ticket $ticket): TicketCreatedStrategy
    {
        $strategy = new TicketCreatedStrategy();

        $ticketsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $ticketsTable->method('get')->willReturn($ticket);

        $locator = new TableLocator();
        $locator->set('Tickets', $ticketsTable);

        $strategy->setTableLocator($locator);

        return $strategy;
    }

    /**
     * @param list<\App\Notification\Channel\NotificationMessage> $messages
     */
    private function firstEmail(array $messages): NotificationMessage
    {
        foreach ($messages as $m) {
            if ($m->channel === 'email') {
                return $m;
            }
        }
        $this->fail('Expected at least one email message in strategy output');
    }
}
