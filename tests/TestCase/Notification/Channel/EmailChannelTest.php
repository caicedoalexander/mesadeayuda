<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\EmailChannel;
use App\Notification\Channel\NotificationMessage;
use App\Service\EmailService;
use Cake\TestSuite\TestCase;

class EmailChannelTest extends TestCase
{
    public function testNameReturnsEmail(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $channel = new EmailChannel($emailService);
        $this->assertSame('email', $channel->name());
    }

    public function testSendDelegatesToEmailServiceDispatch(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 'Hello',
            bodyHtml: '<p>Hi</p>',
        );

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('dispatch')
            ->with($msg)
            ->willReturn(true);

        $channel = new EmailChannel($emailService);
        $this->assertTrue($channel->send($msg));
    }

    public function testSendRejectsNonEmailChannelMessages(): void
    {
        $msg = NotificationMessage::whatsapp(recipient: '+57x', bodyText: 'hi');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('dispatch');

        $channel = new EmailChannel($emailService);
        $this->assertFalse($channel->send($msg));
    }
}
