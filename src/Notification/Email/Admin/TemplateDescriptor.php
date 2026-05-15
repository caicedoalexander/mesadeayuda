<?php
declare(strict_types=1);

namespace App\Notification\Email\Admin;

/**
 * Read-only descriptor of a registered email template, surfaced by the
 * admin index/preview pages.
 */
final readonly class TemplateDescriptor
{
    /**
     * @param string $key Template registry key
     * @param string $accentColor Main accent hex color
     * @param string $accentSoftColor Soft accent hex color
     * @param string $tag Human label for the template
     * @param string $description Short description for the admin index
     */
    public function __construct(
        public string $key,
        public string $accentColor,
        public string $accentSoftColor,
        public string $tag,
        public string $description,
    ) {
    }
}
