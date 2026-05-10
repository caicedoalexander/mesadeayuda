<?php
declare(strict_types=1);

namespace App\Service\Util;

/**
 * Pure-function helpers for parsing RFC 5322 address headers.
 *
 * Extracted from GmailService so callers that only need to dissect
 * "Name <email@example.com>" strings don't pay the cost of constructing
 * a GoogleClient / OAuth pipeline (was the hot path during email batch
 * ingestion — see CR-010).
 */
final class EmailHeaderParser
{
    /**
     * Extract the email address from a "Name <email@example.com>" string.
     *
     * @param string $emailString Raw address header value
     * @return string The email address, or the trimmed input if no angle brackets
     */
    public static function extractEmailAddress(string $emailString): string
    {
        if (preg_match('/<(.+?)>/', $emailString, $matches)) {
            return $matches[1];
        }

        return trim($emailString);
    }

    /**
     * Extract the display name from a "Name <email@example.com>" string.
     *
     * Falls back to the email address when no display name is present.
     *
     * @param string $emailString Raw address header value
     * @return string Display name, or email address as fallback
     */
    public static function extractName(string $emailString): string
    {
        if (preg_match('/^(.+?)\s*</', $emailString, $matches)) {
            return trim($matches[1], '" ');
        }

        return self::extractEmailAddress($emailString);
    }

    /**
     * Parse a comma-separated recipients header (To/Cc) into structured rows.
     *
     * @param string $recipientsHeader Raw header value with one or more recipients
     * @return list<array{name: string, email: string}>
     */
    public static function parseRecipients(string $recipientsHeader): array
    {
        if ($recipientsHeader === '') {
            return [];
        }

        $recipients = [];
        // Split on commas not inside quoted strings.
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $recipientsHeader) ?: [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $recipients[] = [
                'name' => self::extractName($part),
                'email' => self::extractEmailAddress($part),
            ];
        }

        return $recipients;
    }
}
