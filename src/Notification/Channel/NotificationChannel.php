<?php
declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * Transport-layer contract: receives a fully-rendered NotificationMessage
 * and delivers it. Implementations must be side-effect-only — they MUST
 * NOT propagate exceptions; errors must be logged and the call return false.
 */
interface NotificationChannel
{
    /**
     * Stable name used by strategies to target this channel (e.g. 'email').
     */
    public function name(): string;

    /**
     * Deliver the message. Returns true if accepted by the underlying
     * transport, false on any failure (already logged by the adapter).
     */
    public function send(NotificationMessage $message): bool;
}
