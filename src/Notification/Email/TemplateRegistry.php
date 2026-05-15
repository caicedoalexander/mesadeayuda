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
    /** @var array<string, EmailTemplate> */
    private array $templates = [];

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

    public function get(string $key): EmailTemplate
    {
        if (!isset($this->templates[$key])) {
            throw new InvalidArgumentException("Unknown email template: {$key}");
        }

        return $this->templates[$key];
    }

    /** @return list<EmailTemplate> */
    public function all(): array
    {
        return array_values($this->templates);
    }
}
