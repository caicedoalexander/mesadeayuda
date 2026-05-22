<?php
declare(strict_types=1);

namespace App\Service\Util;

use Cake\Core\Configure;

/**
 * Anti-spoof stamp embedded in outgoing notification subjects.
 *
 * Format appended at the END of the subject:
 *   " [#<ticketNumber>·s=<8-hex-HMAC>]"
 *
 * The 8 hex chars = 32 bits of HMAC-SHA256(input='ticket:<N>', key=Security.salt)
 * truncated. This is anti-spoof at the cost of one preimage chance in ~4e9 — not
 * a cryptographic signature. The salt is private; an external sender cannot mint
 * a valid stamp for an arbitrary ticket number.
 *
 * Receiver flow (GmailService::isSystemNotification):
 *   $ticket = NotificationStamp::verifiedTicketNumber($subject);
 *   if ($ticket !== null) { // treat as system reply, skip ingest }
 */
final class NotificationStamp
{
    private const STAMP_RE = '/\[#(\d+)·s=([0-9a-f]{8})\]/u';
    private const STAMP_LENGTH = 8;

    /**
     * Append the HMAC stamp suffix to the subject.
     *
     * @param string $subject       Original subject (any text, may already contain "#N")
     * @param string $ticketNumber  Ticket number to bind into the stamp
     * @return string Subject with " [#<N>·s=<hex>]" appended
     */
    public static function append(string $subject, string $ticketNumber): string
    {
        // Strip any previously-attached stamps so repeated round-trips don't bloat
        // the subject. Customer's quoted subject may already carry our stamp; we
        // re-stamp with the same ticket_number anyway (deterministic given the
        // salt) so removing first is safe and idempotent.
        $clean = preg_replace(self::STAMP_RE, '', $subject) ?? $subject;

        return rtrim($clean) . ' [#' . $ticketNumber . '·s=' . self::compute($ticketNumber) . ']';
    }

    /**
     * Returns the ticket number iff the subject carries a stamp whose HMAC
     * matches the one we would compute for that same ticket number under
     * the current Security.salt. Returns null otherwise.
     *
     * @param string $subject Subject line to inspect
     * @return string|null Ticket number when the stamp validates, null when absent or tampered
     */
    public static function verifiedTicketNumber(string $subject): ?string
    {
        if (!preg_match(self::STAMP_RE, $subject, $m)) {
            return null;
        }

        return hash_equals(self::compute($m[1]), $m[2]) ? $m[1] : null;
    }

    /**
     * Compute the truncated HMAC for a ticket number using the current salt.
     *
     * @param string $ticketNumber Ticket number bound into the stamp input
     * @return string 8-char lowercase hex digest
     */
    private static function compute(string $ticketNumber): string
    {
        $salt = (string)Configure::read('Security.salt');

        return substr(hash_hmac('sha256', 'ticket:' . $ticketNumber, $salt), 0, self::STAMP_LENGTH);
    }
}
