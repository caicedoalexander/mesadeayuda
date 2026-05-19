<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Service\Exception\InvalidWhatsappPayloadException;

final class WhatsappIngestPayloadAttachment
{
    private const MAX_SIZE_BYTES = 10485760; // mirrors GenericAttachmentTrait::MAX_FILE_SIZE
    private const MAX_FILENAME = 255;

    /**
     * Use fromArray() — direct construction is reserved for the factory.
     *
     * Exactly one of $url / $contentBase64 is non-null; the other is null.
     * Enforced in fromArray().
     */
    private function __construct(
        public readonly ?string $url,
        public readonly ?string $contentBase64,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $size,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, int $index): self
    {
        $field = static fn(string $name): string => "field 'attachments[{$index}].{$name}'";

        foreach (['filename', 'mime'] as $required) {
            if (!isset($raw[$required]) || !is_string($raw[$required]) || $raw[$required] === '') {
                throw new InvalidWhatsappPayloadException($field($required) . ': required string');
            }
        }

        $hasUrl = isset($raw['url']) && is_string($raw['url']) && $raw['url'] !== '';
        $hasB64 = isset($raw['content_base64']) && is_string($raw['content_base64']) && $raw['content_base64'] !== '';

        if ($hasUrl === $hasB64) {
            throw new InvalidWhatsappPayloadException(
                "field 'attachments[{$index}]': exactly one of 'url' or 'content_base64' is required",
            );
        }

        $url = null;
        $contentBase64 = null;

        if ($hasUrl) {
            $url = $raw['url'];
            if (!str_starts_with($url, 'https://')) {
                throw new InvalidWhatsappPayloadException($field('url') . ': must be https://');
            }
        } else {
            $contentBase64 = $raw['content_base64'];
            if (base64_decode($contentBase64, true) === false) {
                throw new InvalidWhatsappPayloadException($field('content_base64') . ': not valid base64');
            }
        }

        $filename = $raw['filename'];
        if (
            $filename !== basename($filename)
            || str_contains($filename, '..')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, "\0")
        ) {
            throw new InvalidWhatsappPayloadException($field('filename') . ': path traversal not allowed');
        }
        if (mb_strlen($filename) > self::MAX_FILENAME) {
            throw new InvalidWhatsappPayloadException(
                $field('filename') . ': exceeds ' . self::MAX_FILENAME . ' chars',
            );
        }

        if (!isset($raw['size']) || !is_int($raw['size']) || $raw['size'] < 1) {
            throw new InvalidWhatsappPayloadException($field('size') . ': required positive int');
        }
        if ($raw['size'] > self::MAX_SIZE_BYTES) {
            throw new InvalidWhatsappPayloadException($field('size') . ': exceeds ' . self::MAX_SIZE_BYTES . ' bytes');
        }

        return new self($url, $contentBase64, $filename, $raw['mime'], $raw['size']);
    }
}
