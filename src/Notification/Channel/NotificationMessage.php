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
        public readonly ?int $ticketId = null,
        public readonly ?string $gmailThreadId = null,
    ) {
    }

    /**
     * Factory for an email notification.
     *
     * Threading params ($inReplyTo, $referencesHeader, $commentId, $ticketId,
     * $gmailThreadId) are optional and only meaningful for the email channel;
     * they implement RFC 5322 threading on outbound notifications
     * (CRIT-2 / J1+J2+J7, MED-1):
     *   - $inReplyTo: most recent persisted RFC Message-ID we anchor against.
     *   - $referencesHeader: full chain `<id1> <id2>` (newest LAST per RFC 5322).
     *   - $commentId: ticket_comments.id whose rfc_message_id the transport must
     *     populate with the Message-ID Gmail returns after sending.
     *   - $ticketId: tickets.id to anchor when no comment exists yet
     *     (TicketCreated). The transport persists the outbound Message-ID onto
     *     tickets.rfc_message_id ONLY when that column is still empty — never
     *     clobbers the customer's original Message-ID on email-created tickets.
     *   - $gmailThreadId: tickets.gmail_thread_id to anchor the outbound message
     *     to the same Gmail conversation. RFC headers alone are insufficient for
     *     Gmail's UI threading; the Gmail API requires Message.setThreadId() to
     *     keep replies inside the conversation in the customer's mailbox. Only
     *     set on reply-class notifications (TicketCommentAdded, TicketResponded,
     *     TicketStatusChanged); TicketCreated leaves it null because it is the
     *     root of the conversation.
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
        ?int $ticketId = null,
        ?string $gmailThreadId = null,
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
            ticketId: $ticketId,
            gmailThreadId: $gmailThreadId,
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
