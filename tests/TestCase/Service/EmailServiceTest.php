<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Table\TicketsTable;
use App\Notification\Channel\NotificationMessage;
use App\Service\Dto\SystemConfig;
use App\Service\EmailService;
use App\Service\GmailService;
use App\Service\TicketCommentService;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use ReflectionClass;

/**
 * Unit tests for EmailService::dispatch — the email transport layer that
 * bridges NotificationMessage VOs into Gmail API sends and persists the
 * RFC Message-ID Gmail returns (CRIT-2 / J7, MED-1).
 *
 * The bootstrap forbids DB connections, so GmailService is injected via
 * reflection on the private $gmailService property; the Tickets table is
 * swapped via setTableLocator(); TicketCommentService is injected by
 * constructor.
 */
class EmailServiceTest extends TestCase
{
    /**
     * CRIT-2 / J7: a comment-bearing message must invoke
     * TicketCommentService::attachOutboundMessageId after a successful send.
     */
    public function testDispatchInvokesAttachOutboundMessageIdOnCommentWhenCommentIdProvided(): void
    {
        $gmail = $this->makeGmailService(returnedMessageId: 'gmail-xyz@mail.gmail.com');

        $comments = $this->createMock(TicketCommentService::class);
        $comments->expects($this->once())
            ->method('attachOutboundMessageId')
            ->with(99, 'gmail-xyz@mail.gmail.com', '<root@x> <prev@x>');

        $service = $this->buildService($comments, $gmail);

        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Re: hello',
            bodyHtml: '<p>body</p>',
            inReplyTo: 'prev@x',
            referencesHeader: '<root@x> <prev@x>',
            commentId: 99,
            ticketId: 1,
        );

        $this->assertTrue($service->dispatch($msg));
    }

    /**
     * MED-1: when only ticketId is provided (TicketCreated), the outbound
     * Message-ID must be persisted onto tickets.rfc_message_id and the
     * comment-level persistence path must NOT be invoked.
     */
    public function testDispatchInvokesAttachOutboundMessageIdOnTicketWhenOnlyTicketIdProvided(): void
    {
        $gmail = $this->makeGmailService(returnedMessageId: 'welcome@mail.gmail.com');

        $comments = $this->createMock(TicketCommentService::class);
        $comments->expects($this->never())->method('attachOutboundMessageId');

        $ticketsTable = $this->getMockBuilder(TicketsTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attachOutboundMessageId'])
            ->getMock();
        $ticketsTable->expects($this->once())
            ->method('attachOutboundMessageId')
            ->with(42, 'welcome@mail.gmail.com');

        $service = $this->buildService($comments, $gmail, $ticketsTable);

        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Welcome',
            bodyHtml: '<p>body</p>',
            ticketId: 42,
        );

        $this->assertTrue($service->dispatch($msg));
    }

    /**
     * When Gmail returns null (send failure), neither persistence path may run.
     */
    public function testDispatchDoesNotPersistAnywhereWhenSendFails(): void
    {
        $gmail = $this->makeGmailService(returnedMessageId: null);

        $comments = $this->createMock(TicketCommentService::class);
        $comments->expects($this->never())->method('attachOutboundMessageId');

        $ticketsTable = $this->getMockBuilder(TicketsTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attachOutboundMessageId'])
            ->getMock();
        $ticketsTable->expects($this->never())->method('attachOutboundMessageId');

        $service = $this->buildService($comments, $gmail, $ticketsTable);

        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Re: x',
            bodyHtml: '<p>b</p>',
            commentId: 99,
            ticketId: 1,
        );

        $this->assertFalse($service->dispatch($msg));
    }

    /**
     * Threading headers must be injected into the Gmail send options. We
     * capture the options arg via a willReturnCallback closure and assert
     * In-Reply-To / References reach the transport intact.
     */
    public function testDispatchInjectsInReplyToAndReferencesHeaders(): void
    {
        $capturedOptions = null;
        $gmail = $this->getMockBuilder(GmailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendEmail'])
            ->getMock();
        $gmail->method('sendEmail')->willReturnCallback(
            static function ($to, $subject, $body, $attachments, $options) use (&$capturedOptions): ?string {
                $capturedOptions = $options;

                return 'sent@mail.gmail.com';
            },
        );

        $comments = $this->createMock(TicketCommentService::class);
        $service = $this->buildService($comments, $gmail);

        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Re: hi',
            bodyHtml: '<p>b</p>',
            inReplyTo: 'parent@x',
            referencesHeader: '<root@x> <parent@x>',
            commentId: 99,
        );

        $service->dispatch($msg);

        $this->assertIsArray($capturedOptions);
        $this->assertSame('<parent@x>', $capturedOptions['headers']['In-Reply-To']);
        $this->assertSame('<root@x> <parent@x>', $capturedOptions['headers']['References']);
    }

    /**
     * Without threading anchors, the headers map must NOT contain
     * In-Reply-To or References — only the system notification marker.
     */
    public function testDispatchDoesNotInjectThreadingHeadersWhenNull(): void
    {
        $capturedOptions = null;
        $gmail = $this->getMockBuilder(GmailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendEmail'])
            ->getMock();
        $gmail->method('sendEmail')->willReturnCallback(
            static function ($to, $subject, $body, $attachments, $options) use (&$capturedOptions): ?string {
                $capturedOptions = $options;

                return 'sent@mail.gmail.com';
            },
        );

        $comments = $this->createMock(TicketCommentService::class);
        $service = $this->buildService($comments, $gmail);

        // No ticketId or commentId means no persistence callback runs either.
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'New ticket',
            bodyHtml: '<p>b</p>',
        );

        $service->dispatch($msg);

        $this->assertIsArray($capturedOptions);
        $this->assertArrayNotHasKey('In-Reply-To', $capturedOptions['headers']);
        $this->assertArrayNotHasKey('References', $capturedOptions['headers']);
    }

    /**
     * Gmail-specific threading: when a reply-class strategy populates
     * gmailThreadId, the transport MUST forward it via $options['threadId']
     * so GmailService::sendEmail can call Message::setThreadId(). Without
     * this, the outbound notification arrives as a new conversation in
     * the customer's Gmail UI even with correct RFC 5322 headers.
     */
    public function testDispatchForwardsGmailThreadIdToTransportOptions(): void
    {
        $capturedOptions = null;
        $gmail = $this->getMockBuilder(GmailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendEmail'])
            ->getMock();
        $gmail->method('sendEmail')->willReturnCallback(
            static function ($to, $subject, $body, $attachments, $options) use (&$capturedOptions): ?string {
                $capturedOptions = $options;

                return 'sent@mail.gmail.com';
            },
        );

        $service = $this->buildService($this->createMock(TicketCommentService::class), $gmail);

        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Re: hi',
            bodyHtml: '<p>b</p>',
            gmailThreadId: '18c1abf0d2e34567',
        );

        $service->dispatch($msg);

        $this->assertIsArray($capturedOptions);
        $this->assertSame('18c1abf0d2e34567', $capturedOptions['threadId']);
    }

    /**
     * TicketCreated must NOT carry a threadId — it starts the conversation.
     * Without a threadId in the options map, Gmail opens a new thread.
     */
    public function testDispatchDoesNotForwardThreadIdWhenAbsent(): void
    {
        $capturedOptions = null;
        $gmail = $this->getMockBuilder(GmailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendEmail'])
            ->getMock();
        $gmail->method('sendEmail')->willReturnCallback(
            static function ($to, $subject, $body, $attachments, $options) use (&$capturedOptions): ?string {
                $capturedOptions = $options;

                return 'sent@mail.gmail.com';
            },
        );

        $service = $this->buildService($this->createMock(TicketCommentService::class), $gmail);

        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'New ticket',
            bodyHtml: '<p>b</p>',
        );

        $service->dispatch($msg);

        $this->assertIsArray($capturedOptions);
        $this->assertArrayNotHasKey('threadId', $capturedOptions);
    }

    /**
     * Dispatch must reject non-email messages — the WhatsApp transport
     * is a sibling, not an alias.
     */
    public function testDispatchReturnsFalseForNonEmailChannel(): void
    {
        $service = $this->buildService(
            $this->createMock(TicketCommentService::class),
            $this->makeGmailService('x@y.z'),
        );

        $msg = NotificationMessage::whatsapp('+57x', 'hello');
        $this->assertFalse($service->dispatch($msg));
    }

    // -------------------- helpers --------------------

    /**
     * @return \App\Service\GmailService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeGmailService(?string $returnedMessageId): GmailService
    {
        $gmail = $this->getMockBuilder(GmailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendEmail'])
            ->getMock();
        $gmail->method('sendEmail')->willReturn($returnedMessageId);

        return $gmail;
    }

    /**
     * Wire EmailService with mocked collaborators. The private
     * $gmailService property is set via reflection so the lazy
     * `getGmailService()` resolver never reaches `loadConfigFromDatabase()`.
     */
    private function buildService(
        TicketCommentService $comments,
        GmailService $gmail,
        ?Table $ticketsTable = null,
    ): EmailService {
        $service = new EmailService(SystemConfig::empty(), $comments);

        $ref = new ReflectionClass(EmailService::class);
        $prop = $ref->getProperty('gmailService');
        $prop->setValue($service, $gmail);

        if ($ticketsTable !== null) {
            $locator = new TableLocator();
            $locator->set('Tickets', $ticketsTable);
            $service->setTableLocator($locator);
        }

        return $service;
    }
}
