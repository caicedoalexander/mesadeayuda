<?php
declare(strict_types=1);

namespace App\Notification\Email;

use App\Notification\Email\Ticket\Template\TicketCommentAddedTemplate;
use App\Notification\Email\Ticket\Template\TicketCreatedTemplate;
use App\Notification\Email\Ticket\Template\TicketStatusChangedTemplate;
use App\Notification\Email\Ticket\Template\TicketUpdatedTemplate;
use InvalidArgumentException;

/**
 * Resolves EmailTemplate instances by key. Stateless and instance-free —
 * each invocation builds fresh templates (they're tiny and immutable).
 */
final class TemplateRegistry
{
    /**
     * @var array<string, \App\Notification\Email\EmailTemplate>
     */
    private array $templates = [];

    /**
     * Build the registry with every known template implementation.
     */
    public function __construct()
    {
        $instances = [
            new TicketCreatedTemplate(),
            new TicketStatusChangedTemplate(),
            new TicketCommentAddedTemplate(),
            new TicketUpdatedTemplate(),
        ];

        foreach ($instances as $tpl) {
            $this->templates[$tpl->key()] = $tpl;
        }
    }

    /**
     * @param string $key Template registry key
     * @return \App\Notification\Email\EmailTemplate
     * @throws \InvalidArgumentException When the key is not registered
     */
    public function get(string $key): EmailTemplate
    {
        if (!isset($this->templates[$key])) {
            throw new InvalidArgumentException("Unknown email template: {$key}");
        }

        return $this->templates[$key];
    }

    /** @return list<\App\Notification\Email\EmailTemplate> */
    public function all(): array
    {
        return array_values($this->templates);
    }
}
