<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Strategy;

use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketCommentAddedStrategy;
use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

class TicketCommentAddedStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testSupportsTicketCommentAddedOnly(): void
    {
        $strategy = new TicketCommentAddedStrategy();
        $event = new TicketCommentAdded(ticketId: 1, commentId: 10, actorId: 2, isPublic: true);
        $other = new TicketResponded(1, 10, 'abierto', 'resuelto', 2);

        $this->assertTrue($strategy->supports($event));
        $this->assertFalse($strategy->supports($other));
    }

    public function testBuildMessagesReturnsEmptyWhenTicketMissing(): void
    {
        $strategy = new TicketCommentAddedStrategy();
        $event = new TicketCommentAdded(ticketId: 999999, commentId: 999999, actorId: 0, isPublic: true);

        $this->assertSame([], iterator_to_array($strategy->buildMessages($event), false));
    }

    /**
     * Private comments must not produce a notification: the requester only
     * sees public conversation. The strategy short-circuits inside doBuild()
     * before touching the ORM, so even a stubbed locator must yield no
     * messages.
     */
    public function testEmailMessageOnlyDispatchedForPublicComments(): void
    {
        $strategy = $this->buildStrategy($this->makeTicket(), $this->makeComment(99), priorComments: []);

        $messages = iterator_to_array(
            $strategy->buildMessages(
                new TicketCommentAdded(ticketId: 1, commentId: 99, actorId: 2, isPublic: false),
            ),
            false,
        );

        $this->assertSame([], $messages);
    }

    /**
     * CRIT-2 / J2+J7: a public comment notification must carry the threading
     * headers (so MUAs visually hilan the thread) and the commentId (so
     * EmailService can persist the outbound Message-ID onto this comment).
     */
    public function testEmailMessageCarriesThreadingHeadersAndCommentId(): void
    {
        $ticket = $this->makeTicket(rfcMessageId: 'orig@example.com');
        $comment = $this->makeComment(99);
        $priorComments = [
            $this->commentRow(50, 'cust-1@example.com'),
        ];

        $strategy = $this->buildStrategy($ticket, $comment, $priorComments);
        $messages = iterator_to_array(
            $strategy->buildMessages(
                new TicketCommentAdded(ticketId: 1, commentId: 99, actorId: 2, isPublic: true),
            ),
            false,
        );

        $email = $this->firstEmail($messages);
        $this->assertSame('cust-1@example.com', $email->inReplyTo);
        $this->assertSame('<orig@example.com> <cust-1@example.com>', $email->referencesHeader);
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
            'body' => 'agent comment',
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
    private function buildStrategy(
        Ticket $ticket,
        TicketComment $comment,
        array $priorComments,
    ): TicketCommentAddedStrategy {
        $strategy = new TicketCommentAddedStrategy();

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
