<?php
declare(strict_types=1);

namespace App\Notification\Channel;

use App\Service\WhatsappService;
use Cake\Log\Log;

/**
 * WhatsApp transport adapter. Wraps WhatsappService::sendMessage(),
 * extracting recipient and body from the NotificationMessage.
 */
final class WhatsappChannel implements NotificationChannel
{
    public function __construct(private readonly WhatsappService $whatsappService)
    {
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function send(NotificationMessage $message): bool
    {
        if ($message->channel !== 'whatsapp') {
            Log::warning('WhatsappChannel received a non-whatsapp message; dropping', [
                'channel' => $message->channel,
            ]);

            return false;
        }

        return $this->whatsappService->sendMessage(
            $message->recipient,
            (string)$message->bodyText,
        );
    }
}
