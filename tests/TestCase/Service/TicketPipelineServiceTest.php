<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\TicketConstants;
use App\Domain\Event\TicketStatusChanged;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Service\AuthorizationService;
use App\Service\Dto\SystemConfig;
use App\Service\Exception\InvalidStatusTransitionException;
use App\Service\TicketAttachmentService;
use App\Service\TicketCommentService;
use App\Service\TicketNotificationService;
use App\Service\TicketPipelineService;
use Cake\Database\Connection;
use Cake\Event\EventManager;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * Tests focus on the transactional choreography of handleResponse():
 * rollback paths, post-commit dispatch ordering, and the preserved
 * "comment survives status failure" semantics.
 *
 * Collaborators are mocked. The Connection mock invokes the transactional
 * callback inline — we are not testing SQL semantics, only the pipeline's
 * coordination logic.
 */
class TicketPipelineServiceTest extends TestCase
{
    private Ticket $ticket;
    private EventManager $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticket = new Ticket([
            'id' => 1,
            'ticket_number' => 'T-0001',
            'status' => TicketConstants::STATUS_ABIERTO,
        ]);
        $this->ticket->setNew(false);

        $this->eventManager = new EventManager();
    }

    public function testHandleResponseRollsBackUploadsWhenCommentFails(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn(null);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $attachments->expects($this->never())->method('saveUploadedFile');

        $notifications = $this->createMock(TicketNotificationService::class);
        $notifications->expects($this->never())->method('sendResponseNotifications');

        $service = $this->buildService($comments, $attachments, $notifications);
        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => null],
            files: [],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Error al agregar el comentario.', $result['message']);
    }

    public function testHandleResponsePreservesCommentOnInvalidStatusTransition(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);
        $notifications->expects($this->never())->method('sendResponseNotifications');

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                $notifications,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->expects($this->once())
            ->method('changeStatus')
            ->willThrowException(new InvalidStatusTransitionException(
                'Transición no permitida: abierto → cerrado',
            ));

        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => TicketConstants::STATUS_RESUELTO],
            files: [],
        );

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('Comentario guardado, pero no se pudo cambiar el estado', $result['message']);
        $this->assertSame([], $dispatched);
    }

    public function testHandleResponseDispatchesStatusEventAfterCommit(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                $notifications,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->expects($this->once())
            ->method('changeStatus')
            ->with(
                $this->isInstanceOf(Ticket::class),
                TicketConstants::STATUS_PENDIENTE,
                42,
                null,
                true,
                true,
            )
            ->willReturn(true);

        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => TicketConstants::STATUS_PENDIENTE],
            files: [],
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(TicketStatusChanged::class, $dispatched[0]);
    }

    public function testHandleResponseDoesNotDispatchWhenChangeStatusReturnsFalse(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                $notifications,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->method('changeStatus')->willReturn(false);
        $this->stubTicketsTable($service);

        $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => TicketConstants::STATUS_PENDIENTE],
            files: [],
        );

        $this->assertSame([], $dispatched, 'No event must be dispatched when TX2 rolls back silently');
    }

    public function testChangeStatusDispatchesByDefault(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn(new TicketComment(['id' => 1]));

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->buildService($comments, $attachments, $notifications);
        $this->stubTicketsTable($service, saveReturnsEntity: true);

        $ticket = new Ticket([
            'id' => 1,
            'ticket_number' => 'T-0002',
            'status' => TicketConstants::STATUS_ABIERTO,
        ]);
        $ticket->setNew(false);

        $ok = $service->changeStatus($ticket, TicketConstants::STATUS_PENDIENTE, 42);

        $this->assertTrue($ok);
        $this->assertCount(1, $dispatched, 'Default behavior must dispatch the event inline');
    }

    // -------------------- helpers --------------------

    private function buildService(
        TicketCommentService $comments,
        TicketAttachmentService $attachments,
        TicketNotificationService $notifications,
    ): TicketPipelineService {
        return new TicketPipelineService(
            SystemConfig::empty(),
            $comments,
            $attachments,
            $notifications,
            new AuthorizationService(),
            $this->eventManager,
        );
    }

    /**
     * Replaces the locator so $service->fetchTable() returns stubs:
     * - 'Tickets': get() yields $this->ticket; getConnection() returns a FakeConnection
     *   that runs callbacks inline; save() optionally returns the entity (for changeStatus).
     * - any other table (e.g. 'TicketHistory'): a no-op stub whose save() returns the entity.
     */
    private function stubTicketsTable(TicketPipelineService $service, bool $saveReturnsEntity = false): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')->willReturnCallback(
            fn(callable $cb): mixed => $cb($connection),
        );

        $tickets = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'getConnection', 'save'])
            ->getMock();

        $tickets->method('get')->willReturn($this->ticket);
        $tickets->method('getConnection')->willReturn($connection);
        if ($saveReturnsEntity) {
            $tickets->method('save')->willReturnArgument(0);
        }

        $genericTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'newEntity'])
            ->getMock();
        $genericTable->method('save')->willReturnArgument(0);
        $genericTable->method('newEntity')->willReturn(new Entity());

        $locator = new TableLocator();
        $locator->set('Tickets', $tickets);
        $locator->set('TicketHistory', $genericTable);

        $service->setTableLocator($locator);
    }
}
