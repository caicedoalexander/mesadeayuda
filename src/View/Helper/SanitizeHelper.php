<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Html\HtmlSanitizerPolicy;
use Cake\View\Helper;
use HTMLPurifier;

/**
 * Sanitize Helper
 *
 * Provides output-level HTML sanitization for user-generated content.
 * Defense in depth: input is sanitized by HTMLPurifier in services,
 * this helper provides a second layer at the template/output level.
 */
class SanitizeHelper extends Helper
{
    private ?HTMLPurifier $purifier = null;

    /**
     * Sanitize HTML content for safe rendering
     *
     * @param string|null $html Raw HTML content
     * @return string Sanitized HTML safe for rendering
     */
    public function html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $this->purifier ??= HtmlSanitizerPolicy::createPurifier();

        return $this->purifier->purify($html);
    }
}
