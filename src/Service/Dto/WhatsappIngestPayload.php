<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Service\Exception\InvalidWhatsappPayloadException;

/**
 * Immutable VO for POST /webhooks/whatsapp/import body.
 *
 * Builds itself via fromArray() with full validation; throws
 * InvalidWhatsappPayloadException on any rule violation. Mirrors
 * the contract documented in
 * docs/superpowers/specs/2026-05-19-n8n-whatsapp-audit-fase-1-design.md §3.1.
 */
final class WhatsappIngestPayload
{
    private const MAX_SUBJECT = 200;
    private const MAX_DESCRIPTION = 65535;
    private const MAX_CONTACT_NAME = 120;
    private const MAX_MESSAGE_ID = 120;
    private const MAX_ATTACHMENTS = 10;

    /**
     * @param list<\App\Service\Dto\WhatsappIngestPayloadAttachment> $attachments
     */
    private function __construct(
        public readonly string $messageId,
        public readonly string $phoneNumber,
        public readonly ?string $contactName,
        public readonly string $subject,
        public readonly string $description,
        public readonly array $attachments,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $messageId = self::requireString($raw, 'message_id', self::MAX_MESSAGE_ID);
        if (preg_match('/\s/', $messageId) === 1) {
            throw new InvalidWhatsappPayloadException("field 'message_id': must not contain whitespace");
        }

        $phone = self::normalizePhone(self::requireString($raw, 'phone_number', 20));
        $subject = self::requireString($raw, 'subject', self::MAX_SUBJECT, trim: true);
        $description = self::requireString($raw, 'description', self::MAX_DESCRIPTION, trim: true);

        $contactName = null;
        if (array_key_exists('contact_name', $raw) && $raw['contact_name'] !== null && $raw['contact_name'] !== '') {
            $contactName = self::requireString($raw, 'contact_name', self::MAX_CONTACT_NAME);
        }

        $attachments = self::parseAttachments($raw['attachments'] ?? []);

        return new self($messageId, $phone, $contactName, $subject, $description, $attachments);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireString(array $raw, string $key, int $maxLength, bool $trim = false): string
    {
        if (!array_key_exists($key, $raw) || !is_string($raw[$key])) {
            throw new InvalidWhatsappPayloadException("field '{$key}': required string");
        }
        $value = $trim ? trim($raw[$key]) : $raw[$key];
        if ($value === '') {
            throw new InvalidWhatsappPayloadException("field '{$key}': must not be empty");
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidWhatsappPayloadException(
                "field '{$key}': exceeds {$maxLength} chars",
            );
        }

        return $value;
    }

    /**
     * Normalizes phone to E.164 by prepending '+' when missing,
     * then enforces the E.164 pattern. Throws on violation.
     */
    private static function normalizePhone(string $raw): string
    {
        $candidate = $raw;
        if ($candidate !== '' && $candidate[0] !== '+') {
            $candidate = '+' . $candidate;
        }
        if (preg_match('/^\+[1-9]\d{6,14}$/', $candidate) !== 1) {
            throw new InvalidWhatsappPayloadException("field 'phone_number': not E.164");
        }

        return $candidate;
    }

    /**
     * @param mixed $raw
     * @return list<\App\Service\Dto\WhatsappIngestPayloadAttachment>
     */
    private static function parseAttachments(mixed $raw): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidWhatsappPayloadException("field 'attachments': must be array");
        }
        if (count($raw) > self::MAX_ATTACHMENTS) {
            throw new InvalidWhatsappPayloadException(
                "field 'attachments': exceeds " . self::MAX_ATTACHMENTS . ' items',
            );
        }

        $list = [];
        foreach (array_values($raw) as $i => $item) {
            if (!is_array($item)) {
                throw new InvalidWhatsappPayloadException("field 'attachments[{$i}]': must be object");
            }
            $list[] = WhatsappIngestPayloadAttachment::fromArray($item, $i);
        }

        return $list;
    }
}
