<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\NotificationMessage;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

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
}
