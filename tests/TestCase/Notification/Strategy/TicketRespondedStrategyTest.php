<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketRespondedStrategy;
use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

class TicketRespondedStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testSupportsTicketRespondedOnly(): void
    {
        $strategy = new TicketRespondedStrategy();
        $event = new TicketResponded(1, 10, 'abierto', 'resuelto', 2);
        $other = new TicketCommentAdded(ticketId: 1, commentId: 10, actorId: 2, isPublic: true);

        $this->assertTrue($strategy->supports($event));
        $this->assertFalse($strategy->supports($other));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketMissing(): void
    {
        $strategy = new TicketRespondedStrategy();
        $event = new TicketResponded(999999, 999999, 'abierto', 'resuelto', null);

        $this->assertSame([], iterator_to_array($strategy->buildMessages($event), false));
    }

    /**
     * CRIT-2 / J2: the outbound email anchors against the LAST persisted RFC
     * Message-ID in the thread. With prior comments carrying rfc_message_ids,
     * inReplyTo must be the newest one; References must list every id in id-ASC
     * order (newest LAST per RFC 5322 §3.6.4), ticket's original id first.
     */
    public function testEmailMessageCarriesInReplyToAndReferences(): void
    {
        $ticket = $this->makeTicket(rfcMessageId: 'orig@example.com');
        $comment = $this->makeComment(99);
        $priorComments = [
            $this->commentRow(50, 'cust-reply-1@example.com'),
            $this->commentRow(60, 'agent-outbound-1@example.com'),
        ];

        $strategy = $this->buildStrategy($ticket, $comment, $priorComments);
        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketResponded(1, 99, 'abierto', 'resuelto', 2)),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame('agent-outbound-1@example.com', $email->inReplyTo);
        $this->assertSame(
            '<orig@example.com> <cust-reply-1@example.com> <agent-outbound-1@example.com>',
            $email->referencesHeader,
        );
    }

    /**
     * CRIT-2 / J7: commentId must travel with the message so EmailService can
     * persist the outbound Message-ID Gmail returns onto ticket_comments
     * .rfc_message_id, closing the RFC threading loop for the next client reply.
     */
    public function testEmailMessageCarriesCommentId(): void
    {
        $ticket = $this->makeTicket(rfcMessageId: 'orig@example.com');
        $comment = $this->makeComment(99);

        $strategy = $this->buildStrategy($ticket, $comment, priorComments: []);
        $messages = iterator_to_array(
            $strategy->buildMessages(new TicketResponded(1, 99, 'abierto', 'resuelto', 2)),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame(99, $email->commentId);
    }

    // -------------------- helpers --------------------

    private function makeTicket(?string $rfcMessageId = null): Ticket
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
            'ticket_number' => 'TKT-0001',
            'subject' => 'Subj',
            'status' => 'abierto',
            'priority' => 'media',
            'requester' => $requester,
            'assignee' => null,
            'rfc_message_id' => $rfcMessageId,
            'email_to' => null,
            'email_cc' => null,
            'attachments' => [],
        ], ['guard' => false]);
        $ticket->setNew(false);

        return $ticket;
    }

    private function makeComment(int $id): TicketComment
    {
        $comment = new TicketComment();
        $comment->patch([
            'id' => $id,
            'body' => 'reply',
            'ticket_id' => 1,
            'user' => null,
        ], ['guard' => false]);
        $comment->setNew(false);

        return $comment;
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
    private function buildStrategy(Ticket $ticket, TicketComment $comment, array $priorComments): TicketRespondedStrategy
    {
        $strategy = new TicketRespondedStrategy();

        $ticketsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $ticketsTable->method('get')->willReturn($ticket);

        $commentsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'find'])
            ->getMock();
        $commentsTable->method('get')->willReturn($comment);
        $commentsTable->method('find')->willReturn($this->buildQueryStub($priorComments));

        $locator = new TableLocator();
        $locator->set('Tickets', $ticketsTable);
        $locator->set('TicketComments', $commentsTable);

        $strategy->setTableLocator($locator);

        return $strategy;
    }

    /**
     * Build a fluent stub for the `find()->select()->where()->order()->all()`
     * chain consumed by AbstractTicketStrategy::resolveThreading().
     *
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
     * Build a minimal ResultSetInterface fake that supports the three
     * operations resolveThreading() invokes: count(), last(), and
     * iteration via foreach. Cake\Collection\Collection implements
     * CollectionInterface so wrapping it in an anonymous class is enough
     * to satisfy the ResultSetInterface return type.
     *
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
