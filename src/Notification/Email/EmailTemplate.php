<?php
declare(strict_types=1);

namespace App\Notification\Email;

/**
 * One transactional email template. Implementations are stateless and
 * registered in TemplateRegistry by `key()`.
 */
interface EmailTemplate
{
    /**
     * Unique registry key for this template (e.g. "ticket_created").
     */
    public function key(): string;

    /**
     * Render subject + body HTML against the given context.
     *
     * @param \App\Notification\Email\TemplateContext $ctx Input bag
     * @return \App\Notification\Email\RenderedEmail Rendered subject + body
     */
    public function render(TemplateContext $ctx): RenderedEmail;
}
