<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Notification\Channel\NotificationChannel;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Strategy\TicketNotificationStrategy;
use App\Service\TicketNotificationService;
use Cake\Event\Event;
use Cake\TestSuite\TestCase;

class TicketNotificationServiceTest extends TestCase
{
    public function testDispatchRoutesMessagesToMatchingChannels(): void
    {
        $emailMsg = NotificationMessage::email('a@b.c', 's', '<p>b</p>');
        $waMsg = NotificationMessage::whatsapp('+57x', 'hello');

        $strategy = $this->createMock(TicketNotificationStrategy::class);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('buildMessages')->willReturn([$emailMsg, $waMsg]);

        $emailChannel = $this->createMock(NotificationChannel::class);
        $emailChannel->method('name')->willReturn('email');
        $emailChannel->expects($this->once())->method('send')->with($emailMsg)->willReturn(true);

        $waChannel = $this->createMock(NotificationChannel::class);
        $waChannel->method('name')->willReturn('whatsapp');
        $waChannel->expects($this->once())->method('send')->with($waMsg)->willReturn(true);

        $service = new TicketNotificationService(
            strategies: [$strategy],
            channels: [$emailChannel, $waChannel],
        );

        $service->dispatch(new Event('Ticket.created'));
    }

    public function testDispatchSkipsStrategiesThatDoNotSupportTheEvent(): void
    {
        $strategy = $this->createMock(TicketNotificationStrategy::class);
        $strategy->method('supports')->willReturn(false);
        $strategy->expects($this->never())->method('buildMessages');

        $channel = $this->createMock(NotificationChannel::class);
        $channel->method('name')->willReturn('email');
        $channel->expects($this->never())->method('send');

        $service = new TicketNotificationService(
            strategies: [$strategy],
            channels: [$channel],
        );

        $service->dispatch(new Event('Ticket.created'));
    }

    public function testDispatchSilentlyDropsMessageWhenNoChannelMatches(): void
    {
        $msg = NotificationMessage::whatsapp('+57x', 'hello');

        $strategy = $this->createMock(TicketNotificationStrategy::class);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('buildMessages')->willReturn([$msg]);

        $emailOnly = $this->createMock(NotificationChannel::class);
        $emailOnly->method('name')->willReturn('email');
        $emailOnly->expects($this->never())->method('send');

        $service = new TicketNotificationService(
            strategies: [$strategy],
            channels: [$emailOnly],
        );

        $service->dispatch(new Event('Ticket.created'));
    }
}
