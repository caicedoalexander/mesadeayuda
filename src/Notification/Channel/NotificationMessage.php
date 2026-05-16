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
    ) {
    }

    /**
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
