<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Channel;

use App\Notification\Channel\NotificationMessage;
use App\Notification\Channel\WhatsappChannel;
use App\Service\WhatsappService;
use Cake\TestSuite\TestCase;

class WhatsappChannelTest extends TestCase
{
    public function testNameReturnsWhatsapp(): void
    {
        $whatsappService = $this->createMock(WhatsappService::class);
        $channel = new WhatsappChannel($whatsappService);
        $this->assertSame('whatsapp', $channel->name());
    }

    public function testSendDelegatesToWhatsappServiceSendMessage(): void
    {
        $msg = NotificationMessage::whatsapp(
            recipient: '+573001234567',
            bodyText: 'New ticket T-0001',
        );

        $whatsappService = $this->createMock(WhatsappService::class);
        $whatsappService->expects($this->once())
            ->method('sendMessage')
            ->with('+573001234567', 'New ticket T-0001')
            ->willReturn(true);

        $channel = new WhatsappChannel($whatsappService);
        $this->assertTrue($channel->send($msg));
    }

    public function testSendRejectsNonWhatsappChannelMessages(): void
    {
        $msg = NotificationMessage::email(
            recipient: 'user@example.com',
            subject: 's',
            bodyHtml: 'b',
        );

        $whatsappService = $this->createMock(WhatsappService::class);
        $whatsappService->expects($this->never())->method('sendMessage');

        $channel = new WhatsappChannel($whatsappService);
        $this->assertFalse($channel->send($msg));
    }
}
