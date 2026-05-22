<?php
declare(strict_types=1);

namespace App\Notification\Channel;

use InvalidArgumentException;

/**
 * Inmutable value object that represents a single notification message
 * ready to be delivered by a channel adapter. Strategies build instances
 * of this class; channels consume them.
 *
 * Use the named factory methods (email(), whatsapp()) instead of the
 * constructor — they enforce per-channel invariants.
 */
final class NotificationMessage
{
    /**
     * @param array<int, array{email: string, name?: string}> $additionalTo
     * @param array<int, array{email: string, name?: string}> $additionalCc
     * @param array<int, mixed> $attachments
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public readonly string $channel,
        public readonly string $recipient,
        public readonly ?string $subject,
        public readonly ?string $bodyHtml,
        public readonly ?string $bodyText,
        public readonly array $additionalTo,
        public readonly array $additionalCc,
        public readonly array $attachments,
        public readonly array $metadata,
        public readonly ?string $inReplyTo = null,
        public readonly ?string $referencesHeader = null,
        public readonly ?int $commentId = null,
    ) {
    }

    /**
     * Factory for an email notification.
     *
     * Threading params ($inReplyTo, $referencesHeader, $commentId) are optional and
     * only meaningful for the email channel; they implement RFC 5322 threading on
     * outbound notifications (CRIT-2 / J1+J2+J7):
     *   - $inReplyTo: most recent persisted RFC Message-ID we anchor against.
     *   - $referencesHeader: full chain `<id1> <id2>` (newest LAST per RFC 5322).
     *   - $commentId: ticket_comments.id whose rfc_message_id the transport must
     *     populate with the Message-ID Gmail returns after sending.
     *
     * @param array<int, array{email: string, name?: string}> $additionalTo
     * @param array<int, array{email: string, name?: string}> $additionalCc
     * @param array<int, mixed> $attachments
     * @param array<string, mixed> $metadata
     */
    public static function email(
        string $recipient,
        string $subject,
        string $bodyHtml,
        array $additionalTo = [],
        array $additionalCc = [],
        array $attachments = [],
        array $metadata = [],
        ?string $inReplyTo = null,
        ?string $referencesHeader = null,
        ?int $commentId = null,
    ): self {
        if ($recipient === '') {
            throw new InvalidArgumentException('Email recipient cannot be empty');
        }

        return new self(
            channel: 'email',
            recipient: $recipient,
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: null,
            additionalTo: $additionalTo,
            additionalCc: $additionalCc,
            attachments: $attachments,
            metadata: $metadata,
            inReplyTo: $inReplyTo,
            referencesHeader: $referencesHeader,
            commentId: $commentId,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function whatsapp(
        string $recipient,
        string $bodyText,
        array $metadata = [],
    ): self {
        if ($recipient === '') {
            throw new InvalidArgumentException('WhatsApp recipient cannot be empty');
        }
        if ($bodyText === '') {
            throw new InvalidArgumentException('WhatsApp body text cannot be empty');
        }

        return new self(
            channel: 'whatsapp',
            recipient: $recipient,
            subject: null,
            bodyHtml: null,
            bodyText: $bodyText,
            additionalTo: [],
            additionalCc: [],
            attachments: [],
            metadata: $metadata,
        );
    }
}
