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
     */
    private function __construct(
        public readonly string $url,
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

        foreach (['url', 'filename', 'mime'] as $required) {
            if (!isset($raw[$required]) || !is_string($raw[$required]) || $raw[$required] === '') {
                throw new InvalidWhatsappPayloadException($field($required) . ': required string');
            }
        }

        $url = $raw['url'];
        if (!str_starts_with($url, 'https://')) {
            throw new InvalidWhatsappPayloadException($field('url') . ': must be https://');
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

        return new self($url, $filename, $raw['mime'], $raw['size']);
    }
}
