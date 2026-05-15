<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * Result of rendering an EmailTemplate. Immutable subject + html body pair.
 */
final readonly class RenderedEmail
{
    /**
     * @param string $subject Email subject line
     * @param string $bodyHtml Fully-rendered email body HTML
     */
    public function __construct(
        public string $subject,
        public string $bodyHtml,
    ) {
    }
}
