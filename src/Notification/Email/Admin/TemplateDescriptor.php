<?php
declare(strict_types=1);

namespace App\Notification\Email\Admin;

/**
 * Read-only descriptor of a registered email template, surfaced by the
 * admin index/preview pages.
 */
final readonly class TemplateDescriptor
{
    public function __construct(
        public string $key,
        public string $accentColor,
        public string $accentSoftColor,
        public string $tag,
        public string $description,
    ) {
    }
}
