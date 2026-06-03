<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\NotificationMessage;
use Cake\TestSuite\TestCase;
use Error;
use InvalidArgumentException;
use ReflectionObject;
use ReflectionProperty;

class NotificationMessageTest extends TestCase
{
    public function testEmailFactoryProducesEmailChannelMessage(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Hello',
            bodyHtml: '<p>Hi</p>',
            additionalTo: [['email' => 'cc@example.com', 'name' => 'CC']],
            additionalCc: [],
            attachments: [],
            metadata: ['ticket_id' => 42],
        );

        $this->assertSame('email', $msg->channel);
        $this->assertSame('user@example.com', $msg->recipient);
        $this->assertSame('Hello', $msg->subject);
        $this->assertSame('<p>Hi</p>', $msg->bodyHtml);
        $this->assertNull($msg->bodyText);
        $this->assertSame(42, $msg->metadata['ticket_id']);
    }

    public function testWhatsappFactoryProducesWhatsappChannelMessage(): void
    {
        $msg = NotificationMessage::whatsapp(
            recipient: '+573001234567',
            bodyText: 'Nuevo ticket #T-0001',
            metadata: ['ticket_id' => 1],
        );

        $this->assertSame('whatsapp', $msg->channel);
        $this->assertSame('+573001234567', $msg->recipient);
        $this->assertNull($msg->subject);
        $this->assertNull($msg->bodyHtml);
        $this->assertSame('Nuevo ticket #T-0001', $msg->bodyText);
    }

    public function testEmailFactoryRejectsEmptyRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NotificationMessage::email(recipient: '', subject: 'x', bodyHtml: 'x');
    }

    public function testWhatsappFactoryRejectsEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NotificationMessage::whatsapp(recipient: '+57x', bodyText: '');
    }

    public function testWhatsappFactoryRejectsEmptyRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NotificationMessage::whatsapp(recipient: '', bodyText: 'hello');
    }

    /**
     * MED-1 / CRIT-2 / J7: threading anchors (inReplyTo, referencesHeader,
     * commentId, ticketId, gmailThreadId) MUST propagate from the factory to
     * the VO so the email transport can persist them after Gmail send and
     * anchor the outbound to the right Gmail conversation.
     */
    public function testEmailFactoryPropagatesThreadingAnchors(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Re: Hi',
            bodyHtml: '<p>Body</p>',
            inReplyTo: 'msg-abc@mail.example.com',
            referencesHeader: '<root@x> <msg-abc@mail.example.com>',
            commentId: 99,
            ticketId: 42,
            gmailThreadId: '18c1abf0d2e34567',
        );

        $this->assertSame('msg-abc@mail.example.com', $msg->inReplyTo);
        $this->assertSame('<root@x> <msg-abc@mail.example.com>', $msg->referencesHeader);
        $this->assertSame(99, $msg->commentId);
        $this->assertSame(42, $msg->ticketId);
        $this->assertSame('18c1abf0d2e34567', $msg->gmailThreadId);
    }

    /**
     * Threading anchors default to null when omitted. This is the contract
     * TicketCreatedStrategy relies on (no in-reply-to and no threadId on
     * creation = no threading header injected by EmailService and a fresh
     * Gmail conversation started by GmailService).
     */
    public function testEmailFactoryDefaultsThreadingAnchorsToNull(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 's',
            bodyHtml: '<p>b</p>',
        );

        $this->assertNull($msg->inReplyTo);
        $this->assertNull($msg->referencesHeader);
        $this->assertNull($msg->commentId);
        $this->assertNull($msg->ticketId);
        $this->assertNull($msg->gmailThreadId);
    }

    /**
     * commentId and ticketId are mutually independent: the VO does not enforce
     * an XOR. Strategies choose which to populate (TicketCreated → ticketId
     * only; comment-bearing strategies → both).
     */
    public function testTicketIdAndCommentIdAreIndependent(): void
    {
        $bothNull = NotificationMessage::email(
            recipient: 'a@b.c',
            subject: 's',
            bodyHtml: '<p>b</p>',
        );
        $this->assertNull($bothNull->commentId);
        $this->assertNull($bothNull->ticketId);

        $onlyTicket = NotificationMessage::email(
            recipient: 'a@b.c',
            subject: 's',
            bodyHtml: '<p>b</p>',
            ticketId: 7,
        );
        $this->assertNull($onlyTicket->commentId);
        $this->assertSame(7, $onlyTicket->ticketId);

        $onlyComment = NotificationMessage::email(
            recipient: 'a@b.c',
            subject: 's',
            bodyHtml: '<p>b</p>',
            commentId: 11,
        );
        $this->assertSame(11, $onlyComment->commentId);
        $this->assertNull($onlyComment->ticketId);

        $both = NotificationMessage::email(
            recipient: 'a@b.c',
            subject: 's',
            bodyHtml: '<p>b</p>',
            commentId: 11,
            ticketId: 7,
        );
        $this->assertSame(11, $both->commentId);
        $this->assertSame(7, $both->ticketId);
    }

    /**
     * The VO must be deeply immutable. Rather than inspecting the `readonly`
     * modifier structurally, assert the observable guarantee: every public
     * property rejects reassignment (even to its own current value) with an
     * Error. Enumerating the properties keeps current and future fields covered
     * without coupling the test to the declaration keyword.
     */
    public function testPublicPropertiesCannotBeReassigned(): void
    {
        $msg = NotificationMessage::email(recipient: 'a@b.c', subject: 's', bodyHtml: '<p>b</p>');
        $public = (new ReflectionObject($msg))->getProperties(ReflectionProperty::IS_PUBLIC);

        $this->assertNotEmpty($public);
        foreach ($public as $prop) {
            $name = $prop->getName();
            try {
                $msg->{$name} = $msg->{$name};
                self::fail("Property {$name} is mutable; readonly should reject reassignment.");
            } catch (Error $e) {
                self::assertStringContainsString('readonly', $e->getMessage());
            }
        }
    }
}
