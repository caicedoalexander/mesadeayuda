<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\TicketConstants;
use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
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

        $dispatched = [];
        $this->eventManager->on(TicketStatusChanged::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });
        $this->eventManager->on(TicketResponded::NAME, function ($event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
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

    public function testHandleResponseEmitsTicketRespondedWhenPublicCommentAndStatusChange(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        foreach ([TicketStatusChanged::NAME, TicketResponded::NAME, TicketCommentAdded::NAME] as $name) {
            $this->eventManager->on($name, function ($event) use (&$dispatched): void {
                $dispatched[] = $event;
            });
        }

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
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
        $this->assertCount(1, $dispatched, 'Exactly one event should be dispatched (anti-duplication)');
        $this->assertInstanceOf(TicketResponded::class, $dispatched[0]);
    }

    public function testHandleResponseEmitsTicketCommentAddedWhenOnlyPublicComment(): void
    {
        $comment = new TicketComment(['id' => 99]);
        $comment->setNew(false);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->method('addComment')->willReturn($comment);

        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        foreach ([TicketStatusChanged::NAME, TicketResponded::NAME, TicketCommentAdded::NAME] as $name) {
            $this->eventManager->on($name, function ($event) use (&$dispatched): void {
                $dispatched[] = $event;
            });
        }

        $service = $this->buildService($comments, $attachments, $notifications);
        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => 'hello', 'status' => null],
            files: [],
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(TicketCommentAdded::class, $dispatched[0]);
    }

    public function testHandleResponseEmitsTicketStatusChangedWhenOnlyStatusChange(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $attachments = $this->createMock(TicketAttachmentService::class);
        $notifications = $this->createMock(TicketNotificationService::class);

        $dispatched = [];
        foreach ([TicketStatusChanged::NAME, TicketResponded::NAME, TicketCommentAdded::NAME] as $name) {
            $this->eventManager->on($name, function ($event) use (&$dispatched): void {
                $dispatched[] = $event;
            });
        }

        $service = $this->getMockBuilder(TicketPipelineService::class)
            ->setConstructorArgs([
                SystemConfig::empty(),
                $comments,
                $attachments,
                new AuthorizationService(),
                $this->eventManager,
            ])
            ->onlyMethods(['changeStatus'])
            ->getMock();

        $service->method('changeStatus')->willReturn(true);
        $this->stubTicketsTable($service);

        $result = $service->handleResponse(
            entityId: 1,
            userId: 42,
            data: ['comment_body' => '', 'status' => TicketConstants::STATUS_PENDIENTE],
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
            'status' => TicketConstants::STATUS_ABIERTO,
        ]);
        $ticket->setNew(false);

        $ok = $service->changeStatus($ticket, TicketConstants::STATUS_PENDIENTE, 42);

        $this->assertTrue($ok);
        $this->assertCount(1, $dispatched, 'Default behavior must dispatch the event inline');
    }

    /**
     * Cobertura de historial — addTag/removeTag/addFollower deben escribir
     * una fila en TicketHistory. Capturamos el newEntity() del stub genérico
     * para inspeccionar el payload sin acoplarnos al schema de la tabla real.
     */
    public function testAddTagWritesHistoryEntry(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $attachments = $this->createMock(TicketAttachmentService::class);
        $service = $this->buildService($comments, $attachments);

        $payloads = $this->captureHistoryPayloads($service);

        $service->addTag(1, 7, 42);

        $this->assertGreaterThan(0, $payloads->count(), 'addTag must write to TicketHistory');
        $row = $payloads[$payloads->count() - 1];
        $this->assertSame('tag_added', $row['field_name']);
        $this->assertNull($row['old_value']);
        $this->assertSame('7', $row['new_value']);
        $this->assertSame(42, $row['changed_by']);
    }

    public function testRemoveTagWritesHistoryEntry(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $attachments = $this->createMock(TicketAttachmentService::class);
        $service = $this->buildService($comments, $attachments);

        $payloads = $this->captureHistoryPayloads($service);

        $service->removeTag(1, 7, 42);

        $this->assertGreaterThan(0, $payloads->count(), 'removeTag must write to TicketHistory');
        $row = $payloads[$payloads->count() - 1];
        $this->assertSame('tag_removed', $row['field_name']);
        $this->assertSame('7', $row['old_value']);
        $this->assertNull($row['new_value']);
    }

    public function testAddFollowerWritesHistoryEntry(): void
    {
        $comments = $this->createMock(TicketCommentService::class);
        $attachments = $this->createMock(TicketAttachmentService::class);
        $service = $this->buildService($comments, $attachments);

        $payloads = $this->captureHistoryPayloads($service);

        $service->addFollower(1, 99, 42);

        $this->assertGreaterThan(0, $payloads->count(), 'addFollower must write to TicketHistory');
        $row = $payloads[$payloads->count() - 1];
        $this->assertSame('follower_added', $row['field_name']);
        $this->assertSame('99', $row['new_value']);
        $this->assertSame(42, $row['changed_by']);
    }

    // -------------------- helpers --------------------

    private function buildService(
        TicketCommentService $comments,
        TicketAttachmentService $attachments,
        ?TicketNotificationService $notifications = null,
    ): TicketPipelineService {
        unset($notifications);

        return new TicketPipelineService(
            SystemConfig::empty(),
            $comments,
            $attachments,
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
    /**
     * Configures the table locator with the stubs needed by addTag/removeTag/
     * addFollower and returns an ArrayObject that captures every newEntity
     * payload landing in TicketHistory. ArrayObject is used (instead of a raw
     * array) so the capture container is shared by reference between the test
     * and the closure inside the locator without `&` gymnastics.
     */
    private function captureHistoryPayloads(TicketPipelineService $service): \ArrayObject
    {
        $payloads = new \ArrayObject();

        $tickets = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $tickets->method('get')->willReturn($this->ticket);

        $ticketTags = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'newEntity', 'save', 'delete'])
            ->getMock();
        $emptyQuery = $this->getMockBuilder(\Cake\ORM\Query\SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'count', 'first'])
            ->getMock();
        $emptyQuery->method('where')->willReturnSelf();
        $emptyQuery->method('count')->willReturn(0);
        // For removeTag: find()->where()->first() must return a row to delete.
        $existingRow = new Entity(['id' => 1]);
        $emptyQuery->method('first')->willReturn($existingRow);
        $ticketTags->method('find')->willReturn($emptyQuery);
        $ticketTags->method('newEntity')->willReturn(new Entity());
        $ticketTags->method('save')->willReturnArgument(0);
        $ticketTags->method('delete')->willReturn(true);

        $followers = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'newEntity', 'save'])
            ->getMock();
        $followers->method('find')->willReturn($emptyQuery);
        $followers->method('newEntity')->willReturn(new Entity());
        $followers->method('save')->willReturnArgument(0);

        $tags = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $tags->method('get')->willReturn(new Entity(['name' => 'Soporte']));

        $users = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $users->method('get')->willReturn(new Entity(['name' => 'Maira']));

        $history = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'newEntity'])
            ->getMock();
        $history->method('newEntity')->willReturnCallback(
            function (array $data) use ($payloads): Entity {
                $payloads->append($data);

                return new Entity($data);
            },
        );
        $history->method('save')->willReturnArgument(0);

        $locator = new TableLocator();
        $locator->set('Tickets', $tickets);
        $locator->set('TicketTags', $ticketTags);
        $locator->set('TicketFollowers', $followers);
        $locator->set('Tags', $tags);
        $locator->set('Users', $users);
        $locator->set('TicketHistory', $history);
        $service->setTableLocator($locator);

        return $payloads;
    }

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
