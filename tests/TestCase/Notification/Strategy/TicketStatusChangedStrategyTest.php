<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCreated;
use App\Domain\Event\TicketStatusChanged;
use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketStatusChangedStrategy;
use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

class TicketStatusChangedStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testSupportsTicketStatusChangedOnly(): void
    {
        $strategy = new TicketStatusChangedStrategy();
        $statusChanged = new TicketStatusChanged(1, 'abierto', 'resuelto', 2);
        $created = new TicketCreated(ticketId: 1, requesterId: 2, source: 'manual');

        $this->assertTrue($strategy->supports($statusChanged));
        $this->assertFalse($strategy->supports($created));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketCannotBeLoaded(): void
    {
        $strategy = new TicketStatusChangedStrategy();
        $event = new TicketStatusChanged(999999, 'abierto', 'resuelto', null);

        $messages = iterator_to_array($strategy->buildMessages($event), false);

        $this->assertSame([], $messages);
    }

    /**
     * MEN-1: the systemCommentId — the internal system_comment recording this
     * status transition — must propagate as the NotificationMessage's
     * commentId so EmailService anchors the outbound Message-ID against the
     * system comment (closing the RFC threading loop for the rare case where
     * the customer replies directly to a status-change email).
     */
    public function testEmailMessageCarriesSystemCommentIdAsCommentId(): void
    {
        $strategy = $this->buildStrategy($this->makeTicket(rfcMessageId: 'orig@example.com'));

        $messages = iterator_to_array(
            $strategy->buildMessages(
                new TicketStatusChanged(1, 'abierto', 'resuelto', 2, systemCommentId: 777),
            ),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame(777, $email->commentId);
    }

    /**
     * commentId remains null on legacy callers that don't capture the
     * system_comment id (the constructor default).
     */
    public function testEmailMessageOmitsCommentIdWhenNoSystemCommentProvided(): void
    {
        $strategy = $this->buildStrategy($this->makeTicket(rfcMessageId: 'orig@example.com'));

        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketStatusChanged(1, 'abierto', 'resuelto', 2)),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertNull($email->commentId);
    }

    /**
     * Gmail UI threading hinges on Message::setThreadId(), which requires the
     * ticket's gmail_thread_id to reach the transport. Reply-class strategies
     * MUST propagate it into the NotificationMessage so EmailService can pass
     * it to GmailService::sendEmail.
     */
    public function testEmailMessageCarriesGmailThreadIdFromTicket(): void
    {
        $strategy = $this->buildStrategy(
            $this->makeTicket(rfcMessageId: 'orig@example.com', gmailThreadId: '18c1abf0d2e34567'),
        );

        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketStatusChanged(1, 'abierto', 'resuelto', 2)),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame('18c1abf0d2e34567', $email->gmailThreadId);
    }

    /**
     * Tickets created manually (no Gmail thread of origin) leave
     * gmail_thread_id null. The propagated value must stay null so the
     * transport does NOT call setThreadId() with an empty argument.
     */
    public function testEmailMessageOmitsGmailThreadIdWhenTicketHasNone(): void
    {
        $strategy = $this->buildStrategy($this->makeTicket(rfcMessageId: null));

        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketStatusChanged(1, 'abierto', 'resuelto', 2)),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertNull($email->gmailThreadId);
    }

    /**
     * Threading headers must be present whenever the ticket already has any
     * RFC anchor — even though this is a status-only notification.
     */
    public function testEmailMessageCarriesThreadingHeaders(): void
    {
        $strategy = $this->buildStrategy(
            ticket: $this->makeTicket(rfcMessageId: 'orig@example.com'),
            priorComments: [
                $this->commentRow(40, 'reply-a@example.com'),
                $this->commentRow(41, 'reply-b@example.com'),
            ],
        );

        $messages = iterator_to_array(
            $strategy->buildMessages(
                new TicketStatusChanged(1, 'abierto', 'resuelto', 2, systemCommentId: 777),
            ),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame('reply-b@example.com', $email->inReplyTo);
        $this->assertSame(
            '<orig@example.com> <reply-a@example.com> <reply-b@example.com>',
            $email->referencesHeader,
        );
    }

    // -------------------- helpers --------------------

    private function makeTicket(?string $rfcMessageId = null, ?string $gmailThreadId = null): Ticket
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
            'status' => 'abierto',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
            'rfc_message_id' => $rfcMessageId,
            'gmail_thread_id' => $gmailThreadId,
            'email_to' => null,
            'email_cc' => null,
            'attachments' => [],
        ], ['guard' => false]);
        $ticket->setNew(false);

        return $ticket;
    }

    /**
     * @return object{id: int, rfc_message_id: string}
     */
    private function commentRow(int $id, string $rfc): object
    {
        return (object)['id' => $id, 'rfc_message_id' => $rfc];
    }

    /**
     * @param list<object{id: int, rfc_message_id: string}> $priorComments
     */
    private function buildStrategy(Ticket $ticket, array $priorComments = []): TicketStatusChangedStrategy
    {
        $strategy = new TicketStatusChangedStrategy();

        $ticketsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $ticketsTable->method('get')->willReturn($ticket);

        $commentsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $commentsTable->method('find')->willReturn($this->buildQueryStub($priorComments));

        $locator = new TableLocator();
        $locator->set('Tickets', $ticketsTable);
        $locator->set('TicketComments', $commentsTable);

        $strategy->setTableLocator($locator);

        return $strategy;
    }

    /**
     * @param list<object{id: int, rfc_message_id: string}> $rows
     */
    private function buildQueryStub(array $rows): SelectQuery
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'where', 'order', 'all'])
            ->getMock();

        $query->method('select')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('order')->willReturnSelf();
        $query->method('all')->willReturn($this->buildResultSet($rows));

        return $query;
    }

    /**
     * @param list<object{id: int, rfc_message_id: string}> $rows
     */
    private function buildResultSet(array $rows): ResultSetInterface
    {
        return new class (new Collection($rows)) extends Collection implements ResultSetInterface {
            public function __construct(Collection $items)
            {
                parent::__construct($items);
            }
        };
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
