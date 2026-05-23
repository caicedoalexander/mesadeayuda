<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\TicketComment;
use App\Service\TicketCommentService;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * Unit tests for TicketCommentService::attachOutboundMessageId (CRIT-2 / J7).
 *
 * Avoids the ORM per the test bootstrap. The TicketComments table is replaced
 * via setTableLocator(); the comment entity it returns is mutated directly so
 * we can inspect the values the service writes onto it.
 */
class TicketCommentServiceTest extends TestCase
{
    /**
     * The service persists both rfc_message_id and references_header onto
     * the originating comment so the next inbound reply can be re-anchored.
     */
    public function testAttachOutboundMessageIdPersistsRfcAndReferences(): void
    {
        $comment = $this->makeComment(99);
        $table = $this->stubCommentsTable($comment, saveReturns: $comment);

        $service = $this->buildService($table);

        $service->attachOutboundMessageId(
            commentId: 99,
            rfcMessageId: 'outbound@mail.gmail.com',
            referencesHeader: '<root@x> <outbound@mail.gmail.com>',
        );

        $this->assertSame('outbound@mail.gmail.com', $comment->get('rfc_message_id'));
        $this->assertSame('<root@x> <outbound@mail.gmail.com>', $comment->get('references_header'));
    }

    /**
     * A null referencesHeader (legacy callers) must not be persisted as the
     * literal string "null" — the column simply stays unset by this call.
     */
    public function testAttachOutboundMessageIdSkipsReferencesHeaderWhenNull(): void
    {
        $comment = $this->makeComment(99);
        $comment->set('references_header', 'preexisting', ['guard' => false]);

        $table = $this->stubCommentsTable($comment, saveReturns: $comment);
        $service = $this->buildService($table);

        $service->attachOutboundMessageId(
            commentId: 99,
            rfcMessageId: 'outbound@mail.gmail.com',
            referencesHeader: null,
        );

        $this->assertSame('outbound@mail.gmail.com', $comment->get('rfc_message_id'));
        $this->assertSame('preexisting', $comment->get('references_header'));
    }

    /**
     * Save failures must be logged but not propagated — the email already
     * went out, RFC reattachment degrades gracefully.
     */
    public function testAttachOutboundMessageIdLogsAndDoesNotThrowOnSaveFailure(): void
    {
        $comment = $this->makeComment(99);
        $table = $this->stubCommentsTable($comment, saveReturns: false);
        $service = $this->buildService($table);

        // Must not throw.
        $service->attachOutboundMessageId(
            commentId: 99,
            rfcMessageId: 'outbound@mail.gmail.com',
            referencesHeader: null,
        );

        $this->assertSame('outbound@mail.gmail.com', $comment->get('rfc_message_id'));
    }

    // -------------------- helpers --------------------

    private function makeComment(int $id): TicketComment
    {
        $comment = new TicketComment();
        $comment->patch([
            'id' => $id,
            'ticket_id' => 1,
            'body' => 'reply',
        ], ['guard' => false]);
        $comment->setNew(false);

        return $comment;
    }

    /**
     * @return \Cake\ORM\Table&\PHPUnit\Framework\MockObject\MockObject
     */
    private function stubCommentsTable(TicketComment $comment, mixed $saveReturns): Table
    {
        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save'])
            ->getMock();
        $table->method('get')->willReturn($comment);
        $table->method('save')->willReturn($saveReturns);

        return $table;
    }

    private function buildService(Table $commentsTable): TicketCommentService
    {
        $service = new TicketCommentService();
        $locator = new TableLocator();
        $locator->set('TicketComments', $commentsTable);
        $service->setTableLocator($locator);

        return $service;
    }
}
