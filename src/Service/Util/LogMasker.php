<?php
declare(strict_types=1);

namespace App\Service\Util;

/**
 * I-3: mask the local-part of email addresses before they hit info-level logs.
 *
 * Keeps the first character and the domain intact so an operator can still
 * triage incidents ("which tenant complained?"), but the full identifier is
 * never persisted. Subjects are NOT masked — they are not PII in this system
 * and remain searchable for support workflows.
 *
 * Examples:
 * - alex@example.com         -> a***@example.com
 * - a@example.com            -> *@example.com
 * - alex@x.com, bob@y.com    -> a***@x.com, b***@y.com
 * - ''                       -> ''
 * - notanemail               -> notanemail
 * - @example.com             -> @example.com
 */
final class LogMasker
{
    /**
     * Mask the local-part of one or more email addresses in a single string.
     *
     * @param string $value Raw email or comma-separated list of emails.
     * @return string The same string with each local-part replaced by `?***`
     *                or `*` when the local-part is a single character.
     */
    public static function email(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (str_contains($value, ',')) {
            $parts = array_map('trim', explode(',', $value));

            return implode(', ', array_map(self::email(...), $parts));
        }

        $atPos = strrpos($value, '@');
        if ($atPos === false || $atPos === 0) {
            return $value;
        }

        $local = substr($value, 0, $atPos);
        $domain = substr($value, $atPos);

        if (strlen($local) <= 1) {
            return '*' . $domain;
        }

        return $local[0] . '***' . $domain;
    }

    /**
     * Mask an E.164 phone number to its country code prefix + last 4 digits.
     *
     * Example: +573001234567 → +57***4567
     *
     * Designed for log lines so operators can distinguish users without
     * exposing the full PII. For phones with fewer than 4 digits, returns
     * a fully-masked placeholder.
     *
     * @param string $value Phone number (E.164 expected, plain digits tolerated)
     */
    public static function phone(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $hasPlus = str_starts_with($value, '+');
        $digits = $hasPlus ? substr($value, 1) : $value;

        if (!ctype_digit($digits) || strlen($digits) < 6) {
            return '***';
        }

        $prefix = ($hasPlus ? '+' : '') . substr($digits, 0, 2);
        $last4 = substr($digits, -4);

        return $prefix . '***' . $last4;
    }
}
