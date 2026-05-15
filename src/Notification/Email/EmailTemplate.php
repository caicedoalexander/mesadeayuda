<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * One transactional email template. Implementations are stateless and
 * registered in TemplateRegistry by `key()`.
 */
interface EmailTemplate
{
    public function key(): string;

    public function render(TemplateContext $ctx): RenderedEmail;
}
