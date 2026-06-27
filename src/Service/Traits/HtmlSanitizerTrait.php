<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Html\HtmlSanitizerPolicy;

/**
 * Provides sanitizeHtml() with a project-wide HTML allowlist for ticket bodies.
 * Used by services that persist user-submitted HTML (comments, ingested email).
 */
trait HtmlSanitizerTrait
{
    /**
     * Bytes reserved within the byte budget for closing tags emitted by the
     * re-purify pass. Empirical headroom — most close-tag tails are well under
     * this size, but 256 leaves a comfortable safety margin without sacrificing
     * useful content.
     */
    private const TRUNCATE_PURIFIER_HEADROOM = 256;

    /**
     * Sanitize HTML content using the project-wide allowlist.
     *
     * @param string $html Raw HTML content
     * @return string Sanitized HTML
     */
    private function sanitizeHtml(string $html): string
    {
        return HtmlSanitizerPolicy::createPurifier()->purify($html);
    }

    /**
     * Safely truncate already-sanitized HTML to fit within a byte budget.
     *
     * Replaces a naive {@see substr()} cut. Two correctness goals:
     *  1. UTF-8 safety: cut at character boundaries via {@see mb_substr()}, never
     *     in the middle of a multi-byte sequence.
     *  2. Markup safety: re-purify the truncated chunk so any tags left half-open
     *     by the cut are closed (otherwise downstream renders break and naive
     *     XSS sanitizers may misinterpret the malformed result).
     *
     * If re-purification still exceeds the budget (rare; happens only when the
     * input is mostly tags/entities), fall back to plain text via strip_tags
     * trimmed to the same byte budget. Always returns a string ≤ $maxBytes.
     *
     * @param string $html Sanitized HTML (caller must have run sanitizeHtml first)
     * @param int $maxBytes Hard byte budget (e.g. MySQL TEXT column = 65535)
     * @return string HTML guaranteed to fit within $maxBytes bytes
     */
    private function truncateSanitizedHtml(string $html, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($html) <= $maxBytes) {
            return $html;
        }

        $budget = max(1, $maxBytes - self::TRUNCATE_PURIFIER_HEADROOM);

        // Proportional first estimate (UTF-8 ratio) to converge fast on long bodies.
        $totalBytes = strlen($html);
        $totalChars = mb_strlen($html, 'UTF-8');
        $charBudget = max(1, (int)floor($totalChars * $budget / $totalBytes));

        $truncated = mb_substr($html, 0, $charBudget, 'UTF-8');
        $truncatedLen = strlen($truncated);

        // Estimate may overshoot when char-density is uneven; trim the tail.
        while ($charBudget > 0 && $truncatedLen > $budget) {
            $charBudget--;
            $truncated = mb_substr($html, 0, $charBudget, 'UTF-8');
            $truncatedLen = strlen($truncated);
        }

        // Re-run the purifier on the truncated chunk so half-open tags get closed.
        $repurified = $this->sanitizeHtml($truncated);

        if (strlen($repurified) <= $maxBytes) {
            return $repurified;
        }

        // Final safety net: drop tags entirely and trim plain text to the budget.
        $plain = strip_tags($repurified);
        $plainChars = mb_strlen($plain, 'UTF-8');
        $plainBytes = strlen($plain);
        while ($plainChars > 0 && $plainBytes > $maxBytes) {
            $plainChars--;
            $plain = mb_substr($plain, 0, $plainChars, 'UTF-8');
            $plainBytes = strlen($plain);
        }

        return $plain;
    }
}
