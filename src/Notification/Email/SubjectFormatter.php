<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * Subject line formatting helpers for outbound notification emails.
 *
 * Centralises the "Re:" prefix convention so reply-class templates (response,
 * comment added, status changed) hint MUAs to thread the message with the
 * original conversation, while creation templates remain prefix-free.
 */
final class SubjectFormatter
{
    /**
     * Add "Re: " prefix unless one is already present (avoid duplication when a
     * caller manually prefixed, or when a re-rendered subject already carries it).
     *
     * @param string $subject Raw subject built by a reply-class template
     * @return string Subject guaranteed to start with "Re: "
     */
    public static function reply(string $subject): string
    {
        $trimmed = ltrim($subject);
        if (stripos($trimmed, 'Re:') === 0) {
            return $trimmed;
        }

        return 'Re: ' . $trimmed;
    }
}
