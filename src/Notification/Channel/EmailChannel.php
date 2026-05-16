<?php
declare(strict_types=1);

namespace App\Notification\Channel;

use App\Service\EmailService;
use Cake\Log\Log;

/**
 * Email transport adapter. Wraps EmailService::dispatch() so the rest of
 * the notification pipeline only sees the NotificationChannel contract.
 */
final class EmailChannel implements NotificationChannel
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function name(): string
    {
        return 'email';
    }

    public function send(NotificationMessage $message): bool
    {
        if ($message->channel !== 'email') {
            Log::warning('EmailChannel received a non-email message; dropping', [
                'channel' => $message->channel,
            ]);

            return false;
        }

        return $this->emailService->dispatch($message);
    }
}
